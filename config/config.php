<?php
/**
 * config/config.php — TeleMusic Bot Configuration
 * Copy this file to config/local.php and fill in your credentials.
 * All values can be overridden via environment variables.
 */

declare(strict_types=1);

// ─── Load .env if present ────────────────────────────────────────────────────
if (file_exists(ROOT_DIR . '/.env')) {
    $lines = file(ROOT_DIR . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $val] = array_pad(explode('=', $line, 2), 2, '');
        putenv(trim($key) . '=' . trim($val));
    }
}

function env(string $key, mixed $default = null): mixed
{
    $v = getenv($key);
    return ($v !== false && $v !== '') ? $v : $default;
}

// ─── Telegram credentials ────────────────────────────────────────────────────
define('BOT_TOKEN',  env('BOT_TOKEN'));           // @BotFather token
define('API_ID',     env('API_ID'));               // my.telegram.org  (for LiveProto / MTProto)
define('API_HASH',   env('API_HASH'));             // my.telegram.org
define('SESSION',    env('SESSION'));              // Pyrogram/LiveProto string session for assistant
define('OWNER_ID',   (int) env('OWNER_ID', 0));

// ─── Log channel IDs ─────────────────────────────────────────────────────────
define('LOGGER_ID',   (int) env('LOGGER_ID', 0));
define('ERROR_LOG_ID',(int) env('ERROR_LOG_ID', 0));

// ─── API-1  (arc / deadline-tech — YouTube jobs) ─────────────────────────────
// Get credentials from https://deadlinetech.site or @smaugxd
define('API_URL',  env('API_URL',  'https://api.arcmusic.fun'));
define('API_KEY',  env('API_KEY'));

// ─── API-2  (onegrab — Spotify CDN direct download) ──────────────────────────
define('API_URL2', env('API_URL2', 'https://api.onegrab.fun'));
define('API_KEY2', env('API_KEY2'));

// ─── Spotify OAuth (fallback metadata via spotipy-equivalent) ────────────────
define('SPOTIFY_CLIENT_ID',     env('SPOTIFY_CLIENT_ID',     '6be9f0b34c384ad097cc71b1c1fc5e8b'));
define('SPOTIFY_CLIENT_SECRET', env('SPOTIFY_CLIENT_SECRET', '2607415f99944cc6b24fa98018fb8c09'));

// ─── MongoDB ─────────────────────────────────────────────────────────────────
define('MONGO_URI',  env('MONGO_URI', ''));
define('MONGO_DB',   env('MONGO_DB',  'telemusic'));

// ─── Bot settings ─────────────────────────────────────────────────────────────
define('DURATION_LIMIT_MIN',  (int) env('DURATION_LIMIT', 469));
define('PLAYLIST_FETCH_LIMIT',(int) env('PLAYLIST_FETCH_LIMIT', 50));
define('DOWNLOAD_DIR',  ROOT_DIR . '/downloads');
define('COOKIES_DIR',   ROOT_DIR . '/cookies');
define('LOGS_DIR',      ROOT_DIR . '/logs');

define('JOIN_TIMEOUT_SEC', (int) env('JOIN_TIMEOUT_SEC', 25));

// ─── /start UI (mirrors tosu4's config.SUPPORT_CHAT / SUPPORT_CHANNEL / START_IMG_URL)
define('SUPPORT_CHAT',    env('SUPPORT_CHAT',    'https://t.me/arcchatz'));
define('SUPPORT_CHANNEL', env('SUPPORT_CHANNEL', 'https://t.me/arcupdates'));
define('START_IMG_URL',   env('START_IMG_URL',   'https://files.catbox.moe/hrs8oc.jpg'));

// ─── phptgcalls / LiveProto paths ────────────────────────────────────────────
// https://github.com/TakNone/phptgcalls  &  https://github.com/TakNone/LiveProto
define('PHPTGCALLS_BIN', env('PHPTGCALLS_BIN', ROOT_DIR . '/bin/phptgcalls'));
define('LIVEPROTO_BIN',  env('LIVEPROTO_BIN',  ROOT_DIR . '/bin/liveproto'));

// ─── Polling settings ─────────────────────────────────────────────────────────
define('POLL_TIMEOUT',  30);    // long-poll seconds
define('MAX_CONNECTIONS', 40);

// ─── Supported platforms in v1.0.0 ───────────────────────────────────────────
define('SUPPORTED_PLATFORMS', ['youtube', 'spotify']);
