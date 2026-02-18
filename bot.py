"""PBX AI - Voice Intelligence Platform.

AI voice concierge for JW Marriott Gold Coast Resort & Spa.
Handles inbound calls via Twilio with real-time STT, LLM, and TTS.
"""

import os

from dotenv import load_dotenv
from loguru import logger

from pipecat.audio.vad.silero import SileroVADAnalyzer
from pipecat.pipeline.pipeline import Pipeline
from pipecat.pipeline.runner import PipelineRunner
from pipecat.pipeline.task import PipelineParams, PipelineTask
from pipecat.processors.aggregators.llm_context import LLMContext
from pipecat.processors.aggregators.llm_response_universal import (
    LLMContextAggregatorPair,
    LLMUserAggregatorParams,
)
from pipecat.runner.types import RunnerArguments
from pipecat.runner.utils import create_transport
from pipecat.services.deepgram.stt import DeepgramSTTService
from pipecat.services.elevenlabs.tts import ElevenLabsTTSService
from pipecat.services.openai.llm import OpenAILLMService
from pipecat.transports.base_transport import BaseTransport
from pipecat.transports.websocket.fastapi import FastAPIWebsocketParams

load_dotenv(override=True)

SYSTEM_PROMPT = """\
You are the AI concierge at the JW Marriott Gold Coast Resort & Spa. You are \
speaking on the phone with a guest or caller. Be warm, friendly, and helpful — \
remember this is a phone call, keep it natural and conversational.

You are an INFORMATION-ONLY concierge. You provide info about the resort, its \
amenities, dining, activities, and nearby attractions. You DO NOT make bookings, \
cancellations, or reservations. You DO NOT transfer calls. If someone asks to \
book or cancel anything, politely let them know you can only provide information \
and suggest they speak with the front desk team directly or call the main line.

RESORT DETAILS:
- Name: JW Marriott Gold Coast Resort & Spa
- Address: 158 Ferny Avenue, Surfers Paradise, QLD 4217, Australia
- 28-level luxury resort with 329 rooms
- Check-in: 3:00 PM | Check-out: 11:00 AM
- WiFi: Free in all rooms and throughout the resort
- Parking: Self-parking $24/day, Valet parking $45/day, EV charging available
- Beach: Surfers Paradise Beach is a 15-minute walk away

DINING:
- Citrique Restaurant: Breakfast daily 7:00-10:30 AM. Lunch Sunday 12:00-2:30 PM. \
Dinner Mon-Thu 6:00-8:00 PM, Fri-Sat 5:30-9:30 PM. Award-winning, seafood and \
grill buffet on weekends.
- Misono Japanese Steakhouse: Dinner Tue-Sun from 5:30 PM. Teppanyaki, sushi bar, \
izakaya, and whisky bar on the outdoor terrace.
- Chapter & Verse: Open daily 7:00 AM until late. All-day bar and restaurant, \
cocktails, international fare. High tea served 11:00 AM-3:00 PM.
- JW Market: On-site cafe with locally crafted products, great for snacks and \
family dinners.
- In-room dining available including kids menus.

POOLS & AQUATIC:
- $8 million aquatic playground across one hectare of landscaped gardens
- Saltwater lagoon with 400+ tropical fish — guests can swim and snorkel through \
cascading waterfalls
- Freshwater pool with kids waterslide and lava tube slide
- Lazy river, 2 spa tubs, swim-up grotto
- White sandy beach areas around the pools
- Poolside daybeds and cabanas available (surcharge for cabanas)
- Award-winning: Australia's Best Hotel Pool 2021 and 2024

SPA:
- Spa by JW: Four experiences — Calm, Invigorate, Indulge, and Renew
- Couples retreats, facials, aromatherapy massages
- Sauna, hot tub, steam room, body treatments
- Hours: Mon-Sat 9:00 AM-6:00 PM, Sun 10:00 AM-5:00 PM (by appointment)

FITNESS & ACTIVITIES:
- Modern fitness centre with cardio and strength equipment
- Multi-purpose sport court and tennis
- Bicycle rentals available
- Daily fish feeding at the lagoon
- Snorkelling in the saltwater lagoon
- Family by JW program: cooking classes, JW Garden harvesting, beach walks

KIDS:
- Children 13 and under stay free using existing bedding
- Supervised childcare available
- Kids waterslide and shallow pool areas
- Family activities and kids dining menus

NEARBY ATTRACTIONS:
- Surfers Paradise Beach: 15-minute walk
- Budds Beach: 300 metres
- Cavill Avenue shopping: 1.2 km
- Surfers Paradise Wax Museum: 800 metres
- Dreamworld, Sea World, MovieWorld: all within 30 minutes drive
- Gold Coast hinterland valleys nearby

GUIDELINES:
- Keep responses short and natural — 1-3 sentences max for phone conversation.
- You are INFO ONLY. Never offer to book, cancel, or transfer calls.
- If asked about bookings or cancellations, say something like "I can only help \
with information, but the front desk team would be happy to help you with that."
- Never invent information. If you're not sure, say you're not certain and suggest \
they check with the front desk.
- Do not use bullet points, numbered lists, or special characters — speak naturally.
- If the caller seems done, ask if there's anything else they'd like to know about \
the resort.\
"""

transport_params = {
    "twilio": lambda: FastAPIWebsocketParams(
        audio_in_enabled=True,
        audio_out_enabled=True,
    ),
}


async def run_bot(transport: BaseTransport, runner_args: RunnerArguments):
    logger.info("Starting Hotel Receptionist bot")

    stt = DeepgramSTTService(api_key=os.getenv("DEEPGRAM_API_KEY"))

    tts = ElevenLabsTTSService(
        api_key=os.getenv("ELEVENLABS_API_KEY", ""),
        voice_id=os.getenv("ELEVENLABS_VOICE_ID", ""),
        params=ElevenLabsTTSService.InputParams(
            optimize_streaming_latency=4,
        ),
    )

    llm = OpenAILLMService(
        api_key=os.getenv("OPENAI_API_KEY"),
        model="gpt-4o-mini",
    )

    messages = [
        {"role": "system", "content": SYSTEM_PROMPT},
    ]

    context = LLMContext(messages)
    user_aggregator, assistant_aggregator = LLMContextAggregatorPair(
        context,
        user_params=LLMUserAggregatorParams(vad_analyzer=SileroVADAnalyzer()),
    )

    pipeline = Pipeline(
        [
            transport.input(),
            stt,
            user_aggregator,
            llm,
            tts,
            transport.output(),
            assistant_aggregator,
        ]
    )

    task = PipelineTask(
        pipeline,
        params=PipelineParams(
            enable_metrics=True,
            enable_usage_metrics=True,
        ),
        idle_timeout_secs=runner_args.pipeline_idle_timeout_secs,
    )

    @transport.event_handler("on_client_connected")
    async def on_client_connected(transport, client):
        logger.info("Caller connected")
        # Greeting is spoken by Twilio TwiML <Say> before WebSocket connects
        # Just tell the LLM context what was already said
        greeting = "Hello, thank you for calling the JW Marriott Gold Coast Resort and Spa. How can I help you today?"
        messages.append({"role": "assistant", "content": greeting})

    @transport.event_handler("on_client_disconnected")
    async def on_client_disconnected(transport, client):
        logger.info("Caller disconnected")
        await task.cancel()

    runner = PipelineRunner(handle_sigint=runner_args.handle_sigint)
    await runner.run(task)


async def bot(runner_args: RunnerArguments):
    """Main bot entry point."""
    transport = await create_transport(runner_args, transport_params)
    await run_bot(transport, runner_args)


if __name__ == "__main__":
    from pipecat.runner.run import main

    main()
