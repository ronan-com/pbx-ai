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

This section is for PABX/telephony platform developers (e.g. Broadband Solutions) integrating AI reception into their product.

### The Core Concept

When a hotel admin enables AI reception for a phone number, your PABX platform needs to redirect inbound calls for that DID to this AI bot instead of the normal extension. When they disable it, calls revert to the original destination.

The AI bot's entry point is a **WebSocket endpoint** (`wss://ai-server.example.com/bot`). Your platform needs a way to hand off live call audio to that endpoint. There are two ways to do this depending on your infrastructure:

---

### Option A — SIP Forwarding (Recommended if you run your own SIP infrastructure)

This is the cleanest integration if Broadband Solutions manages its own SIP trunks or softswitch.

**How it works:**

```
Hotel admin enables AI for +617XXXXXXXX
         ↓
Your PABX backend updates the routing table for that DID:
  original destination: sip:reception@hotel-pbx.example.com
  new destination:      sip:ai-reception@ai-sip-bridge.example.com
         ↓
Inbound call arrives → PABX routes to AI SIP bridge
         ↓
SIP bridge (Asterisk / FreeSWITCH) converts call to WebSocket audio stream
         ↓
Pipecat AI pipeline handles the conversation
```

**What this requires on our side:** A SIP-capable front-end (Asterisk or FreeSWITCH) that accepts inbound SIP calls and bridges audio to the Pipecat bot via WebSocket. This is a one-time infrastructure addition. The bot itself (`bot.py`) does not change.

**What your PABX needs to do:**
- On AI enable: update the DID's inbound route to `sip:ai-reception@<our-sip-server>`
- On AI disable: restore the original SIP destination
- Pass the dialled number (`To`) as a SIP header or URI parameter so the bot can load the right hotel's config

---

### Option B — SIP Trunking via Twilio (Current Demo Approach)

If you provision DIDs through Twilio (or want to use Twilio as the SIP-to-WebSocket bridge without running your own Asterisk/FreeSWITCH), the integration is a single Twilio REST API call per number.

**How it works:**

```
Hotel admin enables AI for a number
         ↓
Your backend calls Twilio API to update VoiceUrl for that DID:
  POST /IncomingPhoneNumbers/{NUMBER_SID}.json
  VoiceUrl=https://ai-server.example.com/bot
         ↓
Twilio opens WebSocket to bot on every inbound call
         ↓
To disable: same call with VoiceUrl=<original PBX endpoint>
```

**PHP example:**
```php
function set_ai_routing(string $number_sid, bool $enable, array $twilio): void {
    $url = "https://api.twilio.com/2010-04-01/Accounts/{$twilio['account_sid']}"
         . "/IncomingPhoneNumbers/{$number_sid}.json";

    $voice_url = $enable
        ? 'https://ai-server.example.com/bot'
        : $twilio['original_voice_url'];  // save this before first enable

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

---

### What Your Backend Needs to Store Per Number

Regardless of which option is used:

| Field | Description |
|-------|-------------|
| `phone_number` | The DID in E.164 format, e.g. `+61741523627` |
| `original_destination` | SIP URI or webhook URL before AI was enabled — restored on disable |
| `hotel_id` | Links the number to the hotel's knowledge base and voice config |
| `ai_enabled` | Boolean — current state |

---

### Multi-Tenant: One AI Server, Many Hotels

The bot identifies which hotel is calling via the `To` number passed in the WebSocket request. It performs a DB lookup to load that hotel's system prompt, greeting text, and voice:

```
Inbound call to +617XXXXXXXX
         ↓
Bot receives To=+617XXXXXXXX
         ↓
DB lookup: number → hotel_id → {system_prompt, greeting, voice_id}
         ↓
AI agent initialised with that hotel's config
```

`bot.py` supports this today — it reads `?to=` from the WebSocket URL and queries a local SQLite DB. For your integration, this lookup can be replaced with an API call to your PABX backend.

---

### Questions to Align On

To choose between Option A and Option B and finalise the integration design, we need to know:

1. **Does Broadband Solutions run its own SIP infrastructure** (softswitch, Asterisk, FreeSWITCH, Kamailio), or do you provision DIDs through a third-party carrier?
2. **How are inbound call routes currently managed** — config files, a database, a vendor API?
3. **Is there an existing API** in your PABX platform for updating DID routing programmatically, or would this need to be built?

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
