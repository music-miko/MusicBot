<?php
/**
 * bin/generate_session.php
 *
 * Generates a native LiveProto string session for the assistant account.
 *
 * Why this is needed:
 *   LiveProto's "string session" format is its OWN serialization —
 *   base64(gzdeflate(serialize($sessionContent))) — which is completely
 *   different from a Pyrogram session string (a fixed binary layout:
 *   dc_id + api_id + test_mode + auth_key + user_id + is_bot). The two
 *   are not interchangeable, and LiveProto has no Pyrogram import support.
 *   A Pyrogram-generated SESSION value will never authenticate here.
 *
 * Usage (on your VPS, from the MusicBot root directory):
 *   php bin/generate_session.php
 *
 * You will be prompted for:
 *   1. Your assistant account's phone number (international format, e.g. +15551234567)
 *   2. The login code Telegram sends you
 *   3. Your 2FA password, ONLY if your account has one enabled
 *
 * On success, it prints a session string. Copy that into your .env as:
 *   SESSION=<the printed string>
 */

declare(strict_types=1);

// LiveProto is cloned as a separate sibling project (bin/liveproto), with
// its own independent `composer install` — it is NOT a dependency listed
// in MusicBot's own composer.json, so we need its own autoloader here.
$livProtoAutoload = __DIR__ . '/liveproto/vendor/autoload.php';
if (!file_exists($livProtoAutoload)) {
    fwrite(STDERR, "❌ Could not find $livProtoAutoload\n");
    fwrite(STDERR, "   Make sure install.sh has cloned and set up LiveProto at bin/liveproto first.\n");
    exit(1);
}
require $livProtoAutoload;

use Tak\Liveproto\Network\Client;
use Tak\Liveproto\Utils\Settings;

// Load API_ID / API_HASH the same way config.php does, so this stays
// consistent with whatever is already in your .env — no values are
// hardcoded here.
function loadEnvValue(string $key): ?string
{
    $envFile = __DIR__ . '/../.env';
    if (!file_exists($envFile)) {
        return null;
    }
    foreach (file($envFile) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        if (trim($k) === $key) {
            return trim($v);
        }
    }
    return null;
}

$apiId   = (int) (loadEnvValue('API_ID') ?? 0);
$apiHash = loadEnvValue('API_HASH') ?? '';

if (!$apiId || !$apiHash) {
    fwrite(STDERR, "❌ Could not read API_ID / API_HASH from .env — please set them first.\n");
    exit(1);
}

echo "Using API_ID=$apiId\n";
echo "Logging in as the ASSISTANT account (not the bot) — have its phone ready.\n\n";

$settings = new Settings();
$settings->setApiId($apiId);
$settings->setApiHash($apiHash);
$settings->setHideLog(false);

// 'string' mode writes a local .session file during login, AND lets us
// pull the portable session string back out afterward via getStringSession().
$sessionName = 'assistant';
$client = new Client($sessionName, 'string', $settings);

// start(false): don't block in Loop::run() afterward — we only need the
// interactive CLI login wizard to run, then we grab the session and exit.
$client->start(false);

if (!$client->isAuthorized()) {
    fwrite(STDERR, "\n❌ Login did not complete — account is not authorized. Please re-run and try again.\n");
    $client->stop();
    exit(1);
}

// Client::session is a protected property, so we can't call
// $client->session->getStringSession() directly from outside the class.
// LiveProto exposes this exact call via Client::__toString() instead.
$stringSession = (string) $client;

echo "\n\n✅ Login successful!\n\n";
echo "This string works as a LiveProto 'text'-mode session — i.e. it is fully\n";
echo "self-contained and does not depend on any local .session file existing.\n";
echo "This is almost certainly what phptgcalls expects via the TG_SESSION env var.\n\n";
echo "Copy this entire line into your .env file, replacing the old SESSION=... line:\n\n";
echo "SESSION=$stringSession\n\n";
echo "──────────────────────────────────────────────────────────────────────\n";
echo "Note: this script also left a local file at bin/{$sessionName}.session\n";
echo "(LiveProto 'string' mode). If phptgcalls fails to authenticate using the\n";
echo "SESSION value above, check phptgcalls' own README/source for whether it\n";
echo "expects a session FILE NAME (string mode) instead of the raw encoded\n";
echo "string (text mode) — in that case point it at bin/{$sessionName}.session\n";
echo "directly instead of passing the env var.\n";

$client->stop();
