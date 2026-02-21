# Deployment Guide — PBX AI v2 (Multi-Tenant)

This document covers what you need to do on the server to bring up the new multi-tenant version of PBX AI. The changes are backward-compatible — the JW Marriott setup is already seeded into the database and the bot falls back to the hardcoded prompt if the DB isn't found.

---

## What Changed

| Area | Before | After |
|------|--------|-------|
| Hotel config | Hardcoded in `dashboard/config.php` | Stored in SQLite DB per hotel |
| Twilio credentials | Hardcoded in `config.php` | Environment variables |
| AI toggle | Set VoiceUrl to empty string on disable | Saves the original PBX URL and restores it |
| System prompt | Single constant in `bot.py` | Per-hotel in DB, loaded at call time by phone number |
| Knowledge base | Static HTML demo | Real file upload + paste, stored in DB |
| Agent settings | UI only, saved nothing | Saves to DB, pre-fills on reload |

---

## Step 1 — Pull the updated code

```bash
cd /var/www/pbx-ai   # or wherever your repo lives
git pull
```

---

## Step 2 — Set environment variables

The credentials that were previously hardcoded in `dashboard/config.php` must now live as environment variables. The PHP dashboard reads them via `getenv()`. The bot reads them via `python-dotenv` from your `.env` file.

### For the PHP dashboard (Nginx / PHP-FPM)

Add to your Nginx `fastcgi_param` block or PHP-FPM pool config (`/etc/php/8.x/fpm/pool.d/www.conf`):

```ini
env[TWILIO_SID]      = ACxxxxxxxxxxxxxxxxxxxxxxxxxxxx
env[TWILIO_TOKEN]    = your_twilio_auth_token
env[BOT_WEBHOOK_URL] = https://yourdomain.com/bot
```

Or if you're using a `.env` file loaded by your web server, add those three vars there.

Reload PHP-FPM after:
```bash
systemctl reload php8.x-fpm
```

### For the Python bot

Add to your existing `.env` file (copy from `.env.example`):

```
TWILIO_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_TOKEN=your_twilio_auth_token
BOT_WEBHOOK_URL=https://yourdomain.com/bot
DEFAULT_HOTEL_NUMBER=+61341523627
```

`DEFAULT_HOTEL_NUMBER` is the fallback for `bot.py` when it can't extract the `To` number from the Twilio WebSocket connection. Set it to your JW Marriott number for now. Once you update the TwiML (Step 5), this becomes a true fallback only.

---

## Step 3 — Create the SQLite database

The schema file is at `db/schema.sql`. It creates all tables and seeds the JW Marriott Gold Coast as hotel 1.

```bash
# Run from the repo root
sqlite3 db/pbx.sqlite < db/schema.sql
```

Verify it worked:
```bash
sqlite3 db/pbx.sqlite "SELECT name FROM hotels;"
# Should print: JW Marriott Gold Coast Resort & Spa
```

### Set file permissions

The `db/` directory and `db/pbx.sqlite` must be writable by the PHP-FPM process (usually `www-data`):

```bash
chown -R www-data:www-data db/
chmod 750 db/
chmod 640 db/pbx.sqlite
```

The Python bot only reads from the DB (never writes), so it just needs read access — which `www-data` group membership covers if you run the bot as a user in that group, or adjust as needed for your setup.

---

## Step 4 — Verify PHP can reach the DB

Quick check from the command line:

```bash
php -r "
  define('DB_PATH', '/var/www/pbx-ai/db/pbx.sqlite');
  \$pdo = new PDO('sqlite:' . DB_PATH);
  \$rows = \$pdo->query('SELECT id, name FROM hotels')->fetchAll(PDO::FETCH_ASSOC);
  print_r(\$rows);
"
```

You should see the JW Marriott row. If you get a permission error, recheck Step 3.

---

## Step 5 — Update your TwiML to pass the `To` number

The bot now loads the hotel config by matching the Twilio `To` number against the `phone_numbers` table. To pass that number to the bot, your TwiML response must include it as a query parameter in the WebSocket URL.

**Before:**
```xml
<Connect>
  <Stream url="wss://yourdomain.com/bot" />
</Connect>
```

**After:**
```xml
<Connect>
  <Stream url="wss://yourdomain.com/bot?to=%2B61341523627" />
</Connect>
```

URL-encode the `+` as `%2B`. If your TwiML is generated dynamically (e.g. by a PHP script), build the URL like:

```php
$to_param = urlencode('+61341523627');
$stream_url = "wss://yourdomain.com/bot?to={$to_param}";
```

Until you make this change, the bot falls back to `DEFAULT_HOTEL_NUMBER` from `.env`, so the JW Marriott will keep working without it.

---

## Step 6 — Restart the bot

```bash
systemctl restart pbx-bot   # or whatever your systemd service is called
```

Check the logs to confirm it starts cleanly:
```bash
journalctl -u pbx-bot -f
```

On the first call you should see a log line like:
```
Loaded DB config for +61341523627
```
or
```
Using hardcoded SYSTEM_PROMPT fallback
```

---

## Step 7 — Open the dashboard and verify

1. Open the dashboard in a browser
2. The Property selector should show "JW Marriott Gold Coast Resort & Spa"
3. Navigate to **AI Agent** — the system prompt and greeting fields should be pre-filled from the DB
4. Navigate to **Phone Numbers** — should show `+61341523627` with "AI Off" status
5. Toggle AI on from the sidebar — the status should flip to "AI Active"
6. Toggle it off — the Twilio VoiceUrl should restore to what it was before (check the "PBX Fallback" field on the Phone Numbers page)

---

## Notes

### The old `config.php` credentials

The hardcoded `TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_NUMBER`, `TWILIO_NUMBER_SID`, and `HOTEL_NAME` are gone from `config.php`. Do not add them back. Everything is now in the DB and environment variables.

### Adding phone numbers to a hotel

The dashboard UI doesn't have a "Add Phone Number" form yet (that's the next build step). For now, add numbers directly via SQLite:

```bash
sqlite3 db/pbx.sqlite "
INSERT INTO phone_numbers (hotel_id, twilio_number, twilio_number_sid, label)
VALUES (1, '+61XXXXXXXXX', 'PNxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'Main Reception');
"
```

Get the `twilio_number_sid` from the Twilio console: Phone Numbers → Active Numbers → click the number → the SID is in the URL and on the page.

### Onboarding a second hotel

1. Go to **Admin → Properties** in the dashboard and add the hotel
2. Add their phone number via the SQLite command above (using the new hotel's `id`)
3. Go to **AI Agent** for that hotel and fill in the system prompt and greeting
4. Optionally upload knowledge base files on the **Knowledge Base** page
5. The bot will automatically serve that hotel's config on calls to their number

### PDO SQLite not available?

Check with:
```bash
php -m | grep -i sqlite
```

If `pdo_sqlite` is missing:
```bash
apt install php-sqlite3
systemctl reload php8.x-fpm
```

### Backing up the database

```bash
sqlite3 db/pbx.sqlite ".backup db/pbx.backup.sqlite"
```

Run this via a cron job or before any major changes.
