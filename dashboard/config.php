<?php
// Infrastructure credentials — read from environment variables.
// Set these in /etc/environment, your web server config, or a .env file.
// Do NOT hardcode credentials here.
define('TWILIO_SID',      getenv('TWILIO_SID')      ?: '');
define('TWILIO_TOKEN',    getenv('TWILIO_TOKEN')    ?: '');
define('BOT_WEBHOOK_URL', getenv('BOT_WEBHOOK_URL') ?: '');

// SQLite database path — must be writable by the web server process.
define('DB_PATH', __DIR__ . '/../db/pbx.sqlite');

/**
 * Returns a shared PDO connection to the SQLite database.
 * Creates the DB file on first run if it doesn't exist.
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}
