# PBX AI — Voice Intelligence Platform

AI-powered voice concierge for hospitality. Replaces or augments hotel front-desk phone lines with a real-time AI agent that handles guest inquiries over the phone — dining hours, resort amenities, nearby attractions, and more.

Built for PABX system owners to offer AI reception as a managed service to their hotel clients.

## How It Works

```
Incoming Call → Twilio (PSTN) → WebSocket → Pipecat Pipeline → AI Response → Caller
```

1. **Guest calls the hotel number** — Twilio receives the inbound call via SIP trunk
2. **Pre-recorded greeting plays instantly** — ElevenLabs-generated MP3 via TwiML `<Play>`, zero latency on pickup
3. **Audio streams in real-time** — Twilio Media Streams opens a WebSocket to the bot server
4. **Speech-to-text** — Deepgram Nova-2 transcribes caller speech with <300ms latency
5. **AI generates response** — OpenAI GPT-4o Mini processes the transcript against the hotel's knowledge base
6. **Text-to-speech** — ElevenLabs streams natural speech back to the caller in real-time
7. **Conversation continues** — Full-duplex audio with VAD (voice activity detection) for natural turn-taking

The entire pipeline runs in ~1-2 seconds end-to-end per turn.

## Tech Stack

| Layer | Technology | Purpose |
|-------|-----------|---------|
| **Telephony** | Twilio | SIP trunking, PSTN connectivity, call recording, Media Streams WebSocket |
| **Orchestration** | Pipecat | Real-time voice AI pipeline framework — manages audio streaming, service coordination, and turn-taking |
| **Speech-to-Text** | Deepgram Nova-2 | Low-latency streaming transcription with endpoint detection |
| **LLM** | OpenAI GPT-4o Mini | Fast inference for conversational responses, hotel knowledge grounding |
| **Text-to-Speech** | ElevenLabs | Natural voice synthesis with streaming output, custom voice cloning support |
| **VAD** | Silero | Voice activity detection for barge-in handling and natural conversation flow |
| **Dashboard** | PHP + Tailwind CSS | Hotel management UI — call logs, transcripts, recordings, AI toggle |
| **Infrastructure** | Nginx + Let's Encrypt | Reverse proxy, SSL termination, WebSocket upgrade handling |

## Architecture

```
┌─────────────────────────────────────────────────────┐
│                    PABX AI Server                    │
│                                                      │
│  ┌──────────────┐    ┌───────────────────────────┐  │
│  │   Nginx      │    │   Pipecat Bot (port 7860) │  │
│  │              │    │                           │  │
│  │  :80 → PHP   │    │  Deepgram STT             │  │
│  │  :443 → WSS ─┼───→│  OpenAI LLM               │  │
│  │              │    │  ElevenLabs TTS            │  │
│  │  SSL/TLS     │    │  Silero VAD               │  │
│  └──────────────┘    └───────────────────────────┘  │
│                                                      │
│  ┌──────────────────────────────────────────────┐   │
│  │  PHP Dashboard                                │   │
│  │  Call logs · Transcripts · Recordings · Toggle│   │
│  │  Twilio REST API ← cURL with Basic Auth       │   │
│  └──────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────┘
         ▲                          ▲
         │ HTTPS                    │ WSS (Media Streams)
         │                          │
┌────────┴──────────────────────────┴─────────────────┐
│                     Twilio                           │
│  SIP Trunk ← Hotel Phone Number ← PSTN Inbound Call │
└─────────────────────────────────────────────────────┘
```

## Dashboard

PHP-based management interface for hotel operators:

- **Call Analytics** — Total calls, average duration, daily volume from Twilio API
- **Transcripts & Recordings** — Full call history with audio playback pulled from Twilio
- **AI Toggle** — Enable/disable AI reception per number (switches Twilio webhook between AI bot and original PBX endpoint)
- **Agent Configuration** — Select AI persona and attach hotel-specific knowledge base
- **Phone Numbers** — View and manage assigned Twilio numbers

## Project Structure

```
├── bot.py              # Voice AI pipeline — STT → LLM → TTS
├── greeting.mp3        # Pre-recorded pickup greeting (ElevenLabs)
├── .env                # API credentials (not in repo)
├── .env.example        # Credential template
├── dashboard/
│   ├── config.php      # Twilio API config + hotel settings
│   └── index.php       # Full dashboard application
└── README.md
```

## Deployment

Running on a single VPS (DigitalOcean droplet):

- **Nginx** reverse proxies HTTP → PHP-FPM (dashboard) and HTTPS → WebSocket (bot)
- **Let's Encrypt** SSL via certbot (required — Twilio rejects self-signed certs for WSS)
- **systemd** service for auto-restart of the bot process
- **Bot** runs via uvicorn on port 7860, bound to 0.0.0.0

## Configuration

1. Copy `.env.example` to `.env` and fill in API keys
2. Update `dashboard/config.php` with Twilio credentials and bot webhook URL
3. Point Twilio phone number webhook to `https://<your-domain>/bot`

## API Dependencies

| Service | Endpoint | Auth |
|---------|----------|------|
| Deepgram | `wss://api.deepgram.com` | API key |
| OpenAI | `https://api.openai.com/v1` | Bearer token |
| ElevenLabs | `https://api.elevenlabs.io/v1` | API key |
| Twilio | `https://api.twilio.com/2010-04-01` | Account SID + Auth Token |
