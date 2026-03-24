"""PBX AI - Voice Intelligence Platform.

AI voice concierge for JW Marriott Gold Coast Resort & Spa.
Handles inbound calls via Twilio with real-time STT, LLM, and TTS.
"""

import asyncio
import os
import sqlite3
import time

from dotenv import load_dotenv
from loguru import logger

from pipecat.audio.vad.silero import SileroVADAnalyzer
from pipecat.frames.frames import Frame, TranscriptionFrame
from pipecat.pipeline.pipeline import Pipeline
from pipecat.pipeline.runner import PipelineRunner
from pipecat.pipeline.task import PipelineParams, PipelineTask
from pipecat.processors.aggregators.llm_context import LLMContext
from pipecat.processors.aggregators.llm_response_universal import (
    LLMContextAggregatorPair,
    LLMUserAggregatorParams,
)
from pipecat.processors.frame_processor import FrameDirection, FrameProcessor
from pipecat.runner.types import RunnerArguments
from pipecat.runner.utils import create_transport
from pipecat.services.deepgram.stt import DeepgramSTTService
from pipecat.services.elevenlabs.tts import ElevenLabsTTSService
from pipecat.services.openai.llm import OpenAILLMService
from pipecat.transports.base_transport import BaseTransport
from pipecat.transports.websocket.fastapi import FastAPIWebsocketParams

SILENCE_TIMEOUT_SECS = 30


class SilenceTimeoutProcessor(FrameProcessor):
    """Tracks when the caller last spoke; used by the silence watchdog."""

    def __init__(self):
        super().__init__()
        self.last_speech_at: float = time.monotonic()

    async def process_frame(self, frame: Frame, direction: FrameDirection):
        if isinstance(frame, TranscriptionFrame):
            self.last_speech_at = time.monotonic()
        await self.push_frame(frame, direction)


load_dotenv(override=True)

# Path to the SQLite DB written by the PHP dashboard.
# Adjust if your db/ directory is in a different location.
_DB_PATH = os.path.join(os.path.dirname(__file__), "db", "pbx.sqlite")


def load_hotel_config(to_number: str) -> dict | None:
    """Load hotel agent config from the DB by the called phone number.

    Returns a dict with system_prompt, greeting_message, and
    elevenlabs_voice_id — or None if not found (triggers fallback).
    """
    if not to_number or not os.path.exists(_DB_PATH):
        return None
    try:
        conn = sqlite3.connect(_DB_PATH)
        conn.row_factory = sqlite3.Row
        cur = conn.cursor()
        cur.execute(
            """
            SELECT a.system_prompt, a.greeting_message, a.elevenlabs_voice_id
            FROM agent_settings a
            JOIN phone_numbers p ON p.hotel_id = a.hotel_id
            WHERE p.twilio_number = ?
            """,
            (to_number,),
        )
        row = cur.fetchone()
        conn.close()
        if row and row["system_prompt"]:
            return dict(row)
    except Exception as e:
        logger.error(f"DB config lookup failed for {to_number}: {e}")
    return None


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
the resort.
- STRICT SCOPE: You ONLY answer questions about this resort and its immediate \
surroundings. If someone asks about anything unrelated — legal advice, medical \
advice, news, general knowledge, personal situations, other hotels, or anything \
else outside the resort — do NOT engage with it at all. Simply say: "Sorry, I can \
only help with questions about the JW Marriott Gold Coast Resort. Is there anything \
about the resort I can help you with?" Do not offer partial answers, safety tips, \
or any other content for off-topic questions.\
"""

transport_params = {
    "twilio": lambda: FastAPIWebsocketParams(
        audio_in_enabled=True,
        audio_out_enabled=True,
    ),
}


async def run_bot(transport: BaseTransport, runner_args: RunnerArguments, to_number: str = None):
    logger.info(f"Starting Hotel Receptionist bot (to_number={to_number!r})")

    # Load hotel-specific config from DB; fall back to the hardcoded constant.
    hotel_cfg = load_hotel_config(to_number)
    if hotel_cfg:
        logger.info(f"Loaded DB config for {to_number}")
        system_prompt = hotel_cfg["system_prompt"]
        greeting      = hotel_cfg["greeting_message"] or "Hello, how can I help you today?"
        voice_id      = hotel_cfg["elevenlabs_voice_id"] or os.getenv("ELEVENLABS_VOICE_ID", "")
    else:
        logger.info("Using hardcoded SYSTEM_PROMPT fallback")
        system_prompt = SYSTEM_PROMPT
        greeting      = "Hello, thank you for calling the JW Marriott Gold Coast Resort and Spa. How can I help you today?"
        voice_id      = os.getenv("ELEVENLABS_VOICE_ID", "")

    silence_tracker = SilenceTimeoutProcessor()

    stt = DeepgramSTTService(api_key=os.getenv("DEEPGRAM_API_KEY"))

    tts = ElevenLabsTTSService(
        api_key=os.getenv("ELEVENLABS_API_KEY", ""),
        voice_id=voice_id,
        model="eleven_turbo_v2_5",
        params=ElevenLabsTTSService.InputParams(
            optimize_streaming_latency=4,
        ),
    )

    llm = OpenAILLMService(
        api_key=os.getenv("OPENAI_API_KEY"),
        model="gpt-4o-mini",
    )

    messages = [
        {"role": "system", "content": system_prompt},
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
            silence_tracker,
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

    _watchdog_task: asyncio.Task | None = None

    @transport.event_handler("on_client_connected")
    async def on_client_connected(transport, client):
        nonlocal _watchdog_task
        logger.info("Caller connected")
        # The greeting audio plays via Twilio TwiML <Play> before the WebSocket
        # connects. Injecting it here tells the LLM what was already said.
        messages.append({"role": "assistant", "content": greeting})

        silence_tracker.last_speech_at = time.monotonic()

        async def _silence_watchdog():
            while True:
                await asyncio.sleep(5)
                if time.monotonic() - silence_tracker.last_speech_at >= SILENCE_TIMEOUT_SECS:
                    logger.info(f"No caller speech for {SILENCE_TIMEOUT_SECS}s — hanging up")
                    await task.cancel()
                    return

        _watchdog_task = asyncio.ensure_future(_silence_watchdog())

    @transport.event_handler("on_client_disconnected")
    async def on_client_disconnected(transport, client):
        nonlocal _watchdog_task
        logger.info("Caller disconnected")
        if _watchdog_task:
            _watchdog_task.cancel()
            _watchdog_task = None
        await task.cancel()

    runner = PipelineRunner(handle_sigint=runner_args.handle_sigint)
    await runner.run(task)


async def bot(runner_args: RunnerArguments):
    """Main bot entry point.

    The 'To' number is passed as a query parameter (?to=+61...) in the
    WebSocket URL that Twilio connects to. Set this in your TwiML response:

        <Connect>
          <Stream url="wss://yourdomain.com/bot?to=YOUR_TWILIO_NUMBER" />
        </Connect>

    Pipecat exposes the original request via runner_args; we pull 'to' from
    query_params if available, then fall back to the env var DEFAULT_HOTEL_NUMBER.
    """
    # Try to extract the 'to' number from the WebSocket request query params.
    to_number = None
    try:
        request = getattr(runner_args, "request", None)
        if request is not None:
            to_number = request.query_params.get("to")
    except Exception:
        pass

    # Fallback: operator can set DEFAULT_HOTEL_NUMBER in .env for single-hotel
    # deployments or while testing before TwiML is updated.
    if not to_number:
        to_number = os.getenv("DEFAULT_HOTEL_NUMBER")

    transport = await create_transport(runner_args, transport_params)
    await run_bot(transport, runner_args, to_number=to_number)


if __name__ == "__main__":
    from pipecat.runner.run import main

    main()
