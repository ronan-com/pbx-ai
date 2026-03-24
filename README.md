# PBX AI — Voice Intelligence Platform

AI-powered voice concierge for hospitality. Replaces or augments hotel front-desk phone lines with a real-time AI agent that handles guest inquiries over the phone — dining hours, resort amenities, nearby attractions, and more.

Built for PABX system owners to offer AI reception as a managed service to their hotel clients.

---

## How It Works

```
Incoming Call → Twilio (PSTN) → WebSocket → Pipecat Pipeline → AI Response → Caller
```

1. **Guest calls the hotel number** — Twilio receives the inbound call via SIP trunk
2. **Pre-recorded greeting plays instantly** — ElevenLabs-generated MP3 via TwiML `<Play>`, zero latency on pickup
3. **Audio streams in real-time** — Twilio Media Streams opens a WebSocket to the bot server
4. **Speech-to-text** — Deepgram Nova-3 transcribes caller speech with <300ms latency
5. **AI generates response** — OpenAI GPT-4o Mini processes the transcript against the hotel's knowledge base
6. **Text-to-speech** — ElevenLabs streams natural speech back to the caller in real-time
7. **Conversation continues** — Full-duplex audio with VAD (voice activity detection) for natural turn-taking

The entire pipeline runs in ~1–2 seconds end-to-end per turn.

---

## Tech Stack

| Layer | Technology | Purpose |
|-------|-----------|---------|
| **Telephony** | Twilio | SIP trunking, PSTN connectivity, call recording, Media Streams WebSocket |
| **Orchestration** | Pipecat 0.0.102 | Real-time voice AI pipeline framework — manages audio streaming, service coordination, and turn-taking |
| **Speech-to-Text** | Deepgram Nova-3 | Low-latency streaming transcription with endpoint detection |
| **LLM** | OpenAI GPT-4o Mini | Fast inference for conversational responses, hotel knowledge grounding |
| **Text-to-Speech** | ElevenLabs | Natural voice synthesis with streaming output, custom voice cloning support |
| **VAD** | Silero | Voice activity detection for barge-in handling and natural conversation flow |
| **Dashboard** | PHP 8 + Tailwind CSS | Hotel management UI — call logs, transcripts, recordings, AI toggle |
| **Infrastructure** | Nginx + Let's Encrypt | Reverse proxy, SSL termination, WebSocket upgrade handling |
| **Runtime** | Python 3.12 | Bot process managed by systemd, served via uvicorn |

---

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

---

## Dashboard

PHP-based management interface for hotel operators:

- **Call Analytics** — Total calls, average duration, daily volume from Twilio API
- **Transcripts & Recordings** — Full call history with audio playback pulled from Twilio
- **AI Toggle** — Enable/disable AI reception per number (switches Twilio webhook between AI bot and original PBX endpoint)
- **Agent Configuration** — Select AI persona and attach hotel-specific knowledge base
- **Phone Numbers** — View and manage assigned Twilio numbers

---

## PABX Integration

This section is for PABX system developers integrating AI reception into their platform.

### How It Works Today (Demo)

The demo uses a single Twilio number with a hardcoded webhook. When AI is toggled ON, the dashboard makes one Twilio REST API call to point the number's `VoiceUrl` at the AI bot. Toggle OFF reverts it to the original endpoint.

This same mechanism is exactly what your PABX backend needs to implement at scale.

### Routing Switch — How Selecting a Number Enables AI

When a hotel admin picks a number and enables AI reception, your PABX backend makes a single call to Twilio's REST API to update the SIP routing for that DID:

**Enable AI for a number:**
```
POST https://api.twilio.com/2010-04-01/Accounts/{ACCOUNT_SID}/IncomingPhoneNumbers/{NUMBER_SID}.json
Authorization: Basic {base64(ACCOUNT_SID:AUTH_TOKEN)}
Content-Type: application/x-www-form-urlencoded

VoiceUrl=https://ai-server.example.com/bot
```

**Disable AI (revert to PABX):**
```
POST https://api.twilio.com/2010-04-01/Accounts/{ACCOUNT_SID}/IncomingPhoneNumbers/{NUMBER_SID}.json

VoiceUrl=https://your-pabx.example.com/original-sip-endpoint
```

**PHP example (for your backend):**
```php
function set_ai_routing(string $number_sid, bool $enable, array $twilio): void {
    $url = "https://api.twilio.com/2010-04-01/Accounts/{$twilio['account_sid']}"
         . "/IncomingPhoneNumbers/{$number_sid}.json";

    $voice_url = $enable
        ? 'https://ai-server.example.com/bot'
        : $twilio['original_voice_url'];  // stored when AI was first enabled

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => $twilio['account_sid'] . ':' . $twilio['auth_token'],
        CURLOPT_POSTFIELDS     => http_build_query(['VoiceUrl' => $voice_url]),
    ]);
    curl_exec($ch);
    curl_close($ch);
}
```

**cURL equivalent:**
```bash
curl -X POST "https://api.twilio.com/2010-04-01/Accounts/ACXXX/IncomingPhoneNumbers/PNXXX.json" \
  -u "ACXXX:auth_token" \
  --data-urlencode "VoiceUrl=https://ai-server.example.com/bot"
```

That single API call is the entire integration point. Twilio immediately starts routing all inbound calls on that number to the AI bot's WebSocket endpoint.

### What Your PABX Backend Needs to Store Per Number

| Field | Description |
|-------|-------------|
| `twilio_number_sid` | e.g. `PN720e1e898d9c5bef...` — identifies the DID in Twilio |
| `twilio_account_sid` | Twilio account that owns the number |
| `twilio_auth_token` | Auth token for that Twilio account |
| `original_voice_url` | The pre-AI webhook/SIP endpoint — must be saved before first enable so it can be restored on disable |
| `hotel_id` | Links the number to the hotel's config (knowledge base, voice, etc.) |
| `ai_enabled` | Boolean — current routing state |

### Multi-Tenant: One AI Server, Many Hotels

Once AI is enabled for a number, Twilio calls `https://ai-server.example.com/bot` for every inbound call. To serve multiple hotels from one server, the `To` number on each incoming call is used to look up the right hotel config:

```
Inbound call to +61 7 XXXX XXXX
         ↓
Bot receives call with To=+617XXXXXXXX
         ↓
DB lookup: number → hotel_id → {knowledge_base, voice_id, system_prompt}
         ↓
AI agent initialised with that hotel's config
```

The `bot.py` supports this today — it reads the `?to=` query parameter from the WebSocket URL and performs a SQLite lookup to load the hotel's system prompt, greeting, and voice. For your integration, replace the SQLite lookup with a call to your PABX backend API.

### Native SIP Integration (No Twilio on the PSTN Leg)

For PABX systems that want to bypass Twilio for the PSTN leg entirely:

1. The PABX SIP trunk for a DID is updated to route to a SIP server in front of the AI bot (Asterisk, FreeSWITCH, or Twilio SIP Domain)
2. The SIP server converts the inbound SIP call into the Media Streams WebSocket format Pipecat expects
3. Your PABX updates the dial peer / outbound route for that DID: `sip:ai-reception@your-sip-proxy.example.com`

This removes Twilio from the call path — only the AI processing (Deepgram, OpenAI, ElevenLabs) remains in the cloud.

---

## Project Structure

```
├── bot.py              # Voice AI pipeline — STT → LLM → TTS
├── greeting.mp3        # Pre-recorded pickup greeting (ElevenLabs, cached)
├── .env                # API credentials (not in repo)
├── .env.example        # Credential template
├── dashboard/
│   ├── config.php      # Infrastructure config (reads from env vars)
│   └── index.php       # Full dashboard application
└── README.md
```

---

## Deployment

Running on a single VPS (DigitalOcean droplet):

- **Nginx** reverse proxies HTTP → PHP-FPM (dashboard) and HTTPS → WebSocket (bot)
- **Let's Encrypt** SSL via certbot (required — Twilio rejects self-signed certs for WSS)
- **systemd** service for auto-restart of the bot process
- **Bot** runs via uvicorn on port 7860, bound to `0.0.0.0`

---

## Configuration

1. Copy `.env.example` to `.env` and fill in API keys
2. Set environment variables for `TWILIO_SID`, `TWILIO_TOKEN`, and `BOT_WEBHOOK_URL` (used by the PHP dashboard)
3. Point Twilio phone number webhook to `https://<your-domain>/bot`

---

## API Dependencies

| Service | Endpoint | Auth |
|---------|----------|------|
| Deepgram | `wss://api.deepgram.com` | API key |
| OpenAI | `https://api.openai.com/v1` | Bearer token |
| ElevenLabs | `https://api.elevenlabs.io/v1` | API key |
| Twilio | `https://api.twilio.com/2010-04-01` | Account SID + Auth Token |
