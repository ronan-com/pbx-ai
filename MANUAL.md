# PBX AI — Operator Manual

This manual covers day-to-day use of the PBX AI dashboard for PABX operators managing multiple hotel properties.

---

## Dashboard Overview

The sidebar has three sections:

- **Property selector** — switches context between hotels. All data on every page is scoped to the selected property.
- **Main** — Dashboard (stats + recent calls) and Call History (recordings)
- **Configure** — Per-hotel settings: AI Agent, Knowledge Base, Phone Numbers
- **Admin** — Properties page (add/view all hotels, operator-only)
- **AI toggle** at the bottom — turns AI reception on or off for the currently selected hotel

---

## Switching Between Hotels

When more than one property is set up, a dropdown appears at the top of the sidebar. Select a hotel from the list — the page reloads and all data (calls, settings, recordings) switches to that property.

With only one property configured, the selector shows that hotel's name as a static label.

---

## Dashboard Page

Shows a snapshot of the selected hotel's activity:

- **Today's Calls** — calls received today (accurate count, separate API call)
- **Total Calls (Last 50)** — the most recent 50 calls from Twilio
- **Avg Duration** — average answered call duration across the last 50 calls
- **Recent Calls table** — the 8 most recent calls with caller number, date, duration, and status badge

Click **View all** to go to the full Call History page.

---

## Call History Page

Full list of the last 50 calls for the selected hotel, newest first. Each entry shows:

- Caller number and call status
- Date, time, and duration
- Audio player if a Twilio recording exists for that call (click the play button or the download icon to save)

> Note: "Recorded" only appears when Twilio call recording is enabled on the number. Recordings are fetched live from Twilio — they are not stored on this server.

---

## AI Agent Page

Configures the voice AI for the selected hotel. Changes here take effect on the **next incoming call** — there is no need to restart the bot.

### Fields

**ElevenLabs Voice ID**
The specific ElevenLabs voice this hotel's bot should use. Leave blank to use the server-wide default voice set in `.env`. Use this to give each hotel a distinct voice character. Get the voice ID from your ElevenLabs dashboard under Voices.

**Language**
Sets the language context for the bot. Currently informational — the actual language the bot speaks depends on the system prompt and ElevenLabs voice configuration.

**Greeting Message**
What the bot "remembers" saying at the start of the call. This is injected into the LLM conversation history right after the caller connects, so the AI knows what was already said (the pre-recorded audio greeting plays via Twilio before the bot's WebSocket connects).

Keep it consistent with whatever your greeting audio actually says. Example:
> Hello, thank you for calling The Grand Azure Hotel. How can I help you today?

**System Prompt**
The full instruction set for the AI. This is what defines the bot's personality, scope, and knowledge. For a concierge bot it should include:

- The hotel name and a brief persona description
- What the bot is and is not allowed to do (information-only, no bookings, etc.)
- All factual hotel information: hours, amenities, room types, dining, nearby attractions
- Response style guidelines (short answers for phone, no bullet points, etc.)

The system prompt is the single most important thing to get right. When you add entries to the Knowledge Base, they are appended to this prompt automatically at call time — so keep general instructions here and put specific factual content (menus, hours, pricing) in the Knowledge Base.

Click **Save Changes** to write to the database. The change takes effect immediately on the next call.

---

## Knowledge Base Page

Hotel-specific information that gets appended to the system prompt on every call. Think of this as the factual layer that sits on top of the instructions in the AI Agent system prompt.

### When to use it vs. the System Prompt

| Put in System Prompt | Put in Knowledge Base |
|---|---|
| Bot persona and tone | Dining hours and menus |
| What the bot can/can't do | Room types and rates |
| Response style guidelines | Spa treatment list |
| Scope restrictions | Event calendar |

### Adding a file

Supported formats: **TXT, CSV, MD**

1. Click **Choose file**, select your file, click **Upload**
2. The file's text content is read and stored in the database
3. It appears in the Uploaded Documents list with a character count

For PDF files, copy and paste the text content using the paste method below.

### Pasting content

1. Fill in the **Source name** field (e.g. `Hotel_Info_2025.pdf`) — this is just a label for your reference
2. Paste the text content into the text area
3. Click **Add Entry**

### Deleting an entry

Click the trash icon on any row. A confirmation prompt appears before deletion. Deletions are immediate and permanent.

### How knowledge base content reaches the bot

At call time, `bot.py` loads the hotel's system prompt from `agent_settings` and the bot uses it for that call. Knowledge base entries are not yet automatically appended by the bot — this is the **next integration step**. For now, paste or copy important factual content directly into the **System Prompt** field on the AI Agent page.

> **Roadmap note**: The next version of `bot.py` will concatenate all `knowledge_entries` for the hotel and append them to the system prompt automatically at call time, making the Knowledge Base fully live.

---

## Phone Numbers Page

Shows the phone numbers assigned to the selected hotel and their current routing state.

**Active Numbers list** — each number shows:
- The phone number and label (e.g. "Main Reception")
- Whether AI routing is currently active or passing to PBX

**Routing Configuration panel** — shows:
- **AI Webhook** — the URL calls are routed to when AI is on (your bot server)
- **PBX Fallback** — the original Twilio VoiceUrl that was saved before AI was enabled; this is where calls go when you turn AI off

> The PBX Fallback field will show "Not saved yet — toggle AI on first" if you've never enabled AI for that number. The first time you toggle AI on, the current VoiceUrl is captured and saved. Toggle it off to restore.

### Adding a phone number to a hotel

This is currently done directly in the database. You'll need SSH access to the server:

```bash
sqlite3 /var/www/pbx-ai/db/pbx.sqlite "
INSERT INTO phone_numbers (hotel_id, twilio_number, twilio_number_sid, label)
VALUES (<hotel_id>, '+61XXXXXXXXX', 'PNxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'Main Reception');
"
```

Get the phone number SID from the Twilio console: **Phone Numbers → Active Numbers → click the number**.

---

## AI Toggle

The button at the bottom of the sidebar shows the current state of AI reception for the selected hotel.

**Turning AI on:**
1. The bot saves the number's current Twilio VoiceUrl (your PBX fallback) to the database
2. Updates the Twilio number's VoiceUrl to point at the bot server
3. All inbound calls to that number now go to the AI bot

**Turning AI off:**
1. Reads the saved PBX fallback URL from the database
2. Restores the Twilio number's VoiceUrl to that URL
3. Calls route back to your PBX as normal

If you toggle AI on before any original URL is saved (i.e. the number was already pointing at the bot), the fallback will be empty. In that case, turning AI off will set the VoiceUrl to blank — calls will fail. Make sure the number has a proper PBX destination configured in Twilio before the first toggle-on.

---

## Properties Page (Admin)

Lists all hotels on the platform and lets you add new ones.

### Adding a new hotel

Fill in:
- **Hotel Name** — the full property name as it should appear in the sidebar (e.g. "Sheraton Grand Sydney")
- **Identifier** — a unique lowercase slug (e.g. `sheraton-grand-sydney`). Letters, numbers, and hyphens only. This is used internally and cannot be changed after creation.

After adding, the property appears in the sidebar selector immediately. Switch to it and configure:
1. **AI Agent** — set the system prompt and greeting
2. **Knowledge Base** — upload any hotel info documents
3. **Phone Numbers** — add the Twilio number via the database command (see Phone Numbers section)

### The Properties list

Each hotel shows its slug and how many phone numbers are assigned. Click **Open** to go straight to that hotel's dashboard.

---

## Typical Onboarding Workflow for a New Hotel

1. **Admin → Properties** → Add the hotel (name + slug)
2. **SSH to server** → Insert the hotel's Twilio number into the DB
3. **Configure → AI Agent** → Write the system prompt (hotel persona, scope, guidelines) and greeting
4. **Configure → Knowledge Base** → Upload or paste the hotel's info documents
5. **Sidebar toggle** → Turn AI on — verify the Twilio VoiceUrl updates in the console
6. Make a test call to the number — confirm the bot answers with the correct hotel's voice and knowledge
7. Toggle AI off — confirm the original PBX routing restores correctly

---

## Common Issues

**AI toggle shows "AI Active" but Twilio isn't routing to the bot**
Check that `BOT_WEBHOOK_URL` is set correctly in the server environment and that the PHP-FPM process has access to it. The dashboard updates the DB state and calls Twilio — if the Twilio API call fails silently, the DB and Twilio get out of sync. Re-toggle to retry.

**Bot answers with the wrong hotel's content**
The `To` number in the WebSocket URL query param (`?to=+61...`) must match exactly what's in the `phone_numbers` table, including the `+` and country code. Check the bot logs for `Loaded DB config for ...` — if it shows `Using hardcoded SYSTEM_PROMPT fallback`, the number lookup failed.

**System prompt or greeting isn't updating on calls**
The bot loads config at the start of each call — there's no caching. If changes aren't appearing, verify the save succeeded (look for the green "Agent settings saved" toast) and that the DB file the bot is reading from is the same one the dashboard writes to.

**"PBX Fallback: Not saved yet"**
This means AI was never toggled on for that number through this dashboard, so no original VoiceUrl was captured. Before turning AI on for the first time, manually set `original_voice_url` in the DB:

```bash
sqlite3 /var/www/pbx-ai/db/pbx.sqlite "
UPDATE phone_numbers
SET original_voice_url = 'https://your-pbx-twiml-endpoint.com/twiml'
WHERE twilio_number = '+61XXXXXXXXX';
"
```
