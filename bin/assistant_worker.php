<?php
/**
 * bin/assistant_worker.php
 *
 * The ASSISTANT side of voice-chat streaming, run as its own long-lived
 * process, separate from bot.php.
 *
 * WHY THIS EXISTS (read this before touching the IPC mechanism):
 *   phptgcalls (Tak\Tgcalls\Driver) is an in-process PHP library, not an
 *   external binary — confirmed from phptgcalls-main/example/groupCalls.php.
 *   Joining a group call means holding the authenticated LiveProto Client
 *   and Driver objects directly in the SAME PHP process, running inside
 *   Tak\Asyncio\Loop (Fiber-based).
 *
 *   bot.php is a separate, synchronous, Bot-API-polling PHP process (see
 *   src/Core/Bot.php) — it cannot also host an Asyncio event loop and a
 *   LiveProto Client without a full rearchitecture. So instead of merging
 *   the two, this script runs independently and the two processes talk
 *   through a small file-based command/result queue:
 *
 *     queue/commands/<job_id>.json   written by VideoCallManager.php (bot.php side)
 *     queue/results/<job_id>.json    written by this script (assistant side)
 *
 *   VideoCallManager writes a command file, then polls for the matching
 *   result file (with a timeout) and deletes both once read. This worker
 *   polls the commands directory in its own loop tick, processes each job
 *   using real Driver calls, and writes the result.
 *
 * Run this alongside bot.php (e.g. in its own systemd unit / screen / pm2
 * process) — both need to be running for /play to actually stream audio.
 *
 * Command shapes (queue/commands/<job_id>.json):
 *   {"job_id":"...", "action":"whoami"}
 *   {"job_id":"...", "action":"join_chat_via_invite", "invite_hash":"..."}
 *   {"job_id":"...", "action":"join",   "chat_id":..., "file":"...", "is_video":false}
 *   {"job_id":"...", "action":"stream", "chat_id":..., "file":"...", "is_video":false}
 *   {"job_id":"...", "action":"pause",  "chat_id":...}
 *   {"job_id":"...", "action":"resume", "chat_id":...}
 *   {"job_id":"...", "action":"stop",   "chat_id":...}
 *   {"job_id":"...", "action":"is_alive", "chat_id":...}
 *
 * Result shapes (queue/results/<job_id>.json):
 *   {"ok":true,  "event":"authenticated", "user_id":..., "username":"..."}
 *   {"ok":true,  "event":"chat_joined"}
 *   {"ok":true,  "event":"invite_request_sent"}
 *   {"ok":true,  "event":"already_participant"}
 *   {"ok":true,  "event":"joined"}
 *   {"ok":true,  "event":"playing"}
 *   {"ok":true,  "event":"stopped"}
 *   {"ok":true,  "event":"alive", "alive":true|false}
 *   {"ok":false, "event":"error", "message":"..."}
 */

declare(strict_types = 1);

error_reporting(E_ALL);

$rootDir = dirname(__DIR__);
require $rootDir . '/vendor/autoload.php';

use Tak\Liveproto\Network\Client;
use Tak\Liveproto\Utils\Settings;
use Tak\Tgcalls\Driver;
use Tak\Tgcalls\Event;
use Tak\Asyncio\Loop;
use function Tak\Asyncio\delay;
use TgCalls\AudioDescription;
use TgCalls\VideoDescription;
use TgCalls\MediaSource;
use TgCalls\StreamMode;
use TgCalls\ConnectionState;

// ─── Load config the same way config.php does (kept standalone here so this
//     script has no dependency on the bot's own namespaced classes) ─────────
function loadEnvValue(string $key, string $rootDir): ?string
{
    $envFile = $rootDir . '/.env';
    if (!file_exists($envFile)) return null;
    foreach (file($envFile) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        if (trim($k) === $key) return trim($v);
    }
    return null;
}

$apiId   = (int) (loadEnvValue('API_ID', $rootDir) ?? 0);
$apiHash = loadEnvValue('API_HASH', $rootDir) ?? '';
$session = loadEnvValue('SESSION', $rootDir) ?? '';

if (!$apiId || !$apiHash || !$session) {
    fwrite(STDERR, "[assistant_worker] Missing API_ID / API_HASH / SESSION in .env — cannot start.\n");
    exit(1);
}

// ─── Queue directories ───────────────────────────────────────────────────────
$queueDir   = $rootDir . '/queue';
$cmdDir     = $queueDir . '/commands';
$resultDir  = $queueDir . '/results';
foreach ([$queueDir, $cmdDir, $resultDir] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

function logLine(string $msg): void
{
    $ts = date('Y-m-d\TH:i:s.vP');
    echo "[$ts] [assistant_worker] $msg\n";
}

function writeResult(string $resultDir, string $jobId, array $payload): void
{
    $tmp = "$resultDir/$jobId.json.tmp";
    file_put_contents($tmp, json_encode($payload));
    rename($tmp, "$resultDir/$jobId.json"); // atomic-ish on same filesystem
}

// ─── LiveProto Settings ──────────────────────────────────────────────────────
$settings = new Settings();
$settings->setApiId($apiId);
$settings->setApiHash($apiHash);
$settings->setHideLog(false);

/**
 * $client and $calls are populated once the assistant authenticates inside
 * Loop::queue() below. $calls maps chat_id => Driver, exactly like the
 * $calls array in phptgcalls' own groupCalls.php example.
 */
$client = null;
$calls  = [];

/**
 * Poll the commands directory once and process anything found.
 * Runs as a periodic tick inside the same Asyncio loop the Client uses,
 * so Driver/Client calls below are always made from the correct context.
 */
function processCommandQueue(string $cmdDir, string $resultDir, ?Client &$client, array &$calls): void
{
    $files = glob("$cmdDir/*.json");
    if (!$files) return;

    foreach ($files as $file) {
        $jobId = basename($file, '.json');
        $raw   = @file_get_contents($file);
        @unlink($file); // claim it immediately so nothing double-processes it

        $cmd = $raw ? json_decode($raw, true) : null;
        if (!is_array($cmd) || !isset($cmd['action'])) {
            writeResult($resultDir, $jobId, ['ok' => false, 'event' => 'error', 'message' => 'Malformed command']);
            continue;
        }

        try {
            handleCommand($cmd, $jobId, $resultDir, $client, $calls);
        } catch (\Throwable $e) {
            logLine("Error handling action '{$cmd['action']}': " . $e->getMessage());
            writeResult($resultDir, $jobId, ['ok' => false, 'event' => 'error', 'message' => $e->getMessage()]);
        }
    }
}

function handleCommand(array $cmd, string $jobId, string $resultDir, ?Client &$client, array &$calls): void
{
    $action = $cmd['action'];

    if ($client === null) {
        writeResult($resultDir, $jobId, ['ok' => false, 'event' => 'error', 'message' => 'Assistant not connected yet']);
        return;
    }

    switch ($action) {
        case 'whoami':
            $me = $client->get_me();
            writeResult($resultDir, $jobId, [
                'ok' => true, 'event' => 'authenticated',
                'user_id' => $me->id, 'username' => $me->username ?? null,
            ]);
            break;

        case 'join_chat_via_invite':
            $hash = $cmd['invite_hash'] ?? null;
            if (!$hash) {
                writeResult($resultDir, $jobId, ['ok' => false, 'event' => 'error', 'message' => 'Missing invite_hash']);
                break;
            }
            try {
                // NOTE: 'hash' is the parameter name per Telegram's documented
                // public MTProto schema for messages.importChatInvite. It is
                // NOT independently confirmed verbatim inside this LiveProto
                // checkout (no raw .tl schema file is bundled — methods are
                // dispatched via reflection against a schema baked in
                // elsewhere). If this throws an "unknown parameter" style
                // error rather than a normal RPC error, that's the first
                // thing to check.
                $client->messages->importChatInvite(hash: $hash);
                writeResult($resultDir, $jobId, ['ok' => true, 'event' => 'chat_joined']);
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'INVITE_REQUEST_SENT') !== false) {
                    writeResult($resultDir, $jobId, ['ok' => true, 'event' => 'invite_request_sent']);
                } elseif (stripos($msg, 'USER_ALREADY_PARTICIPANT') !== false) {
                    writeResult($resultDir, $jobId, ['ok' => true, 'event' => 'already_participant']);
                } else {
                    writeResult($resultDir, $jobId, ['ok' => false, 'event' => 'error', 'message' => $msg]);
                }
            }
            break;

        case 'join':
            joinGroupCall($cmd, $jobId, $resultDir, $client, $calls);
            break;

        case 'stream':
            streamToExistingCall($cmd, $jobId, $resultDir, $calls);
            break;

        case 'seek':
            seekExistingCall($cmd, $jobId, $resultDir, $calls);
            break;

        case 'volume':
            setVolumeExistingCall($cmd, $jobId, $resultDir, $calls);
            break;

        case 'pause':
            respondBool($cmd, $jobId, $resultDir, $calls, fn($d) => $d->pause(), 'paused');
            break;

        case 'resume':
            respondBool($cmd, $jobId, $resultDir, $calls, fn($d) => $d->resume(), 'resumed');
            break;

        case 'stop':
            $chatId = (int) ($cmd['chat_id'] ?? 0);
            $entry  = $calls[$chatId] ?? null;
            if ($entry) {
                $entry['driver']->stop();
                unset($calls[$chatId]);
            }
            writeResult($resultDir, $jobId, ['ok' => true, 'event' => 'stopped']);
            break;

        case 'is_alive':
            $chatId = (int) ($cmd['chat_id'] ?? 0);
            writeResult($resultDir, $jobId, ['ok' => true, 'event' => 'alive', 'alive' => isset($calls[$chatId])]);
            break;

        default:
            writeResult($resultDir, $jobId, ['ok' => false, 'event' => 'error', 'message' => "Unknown action: $action"]);
    }
}

/**
 * Join a group's voice chat and start streaming a file — mirrors
 * phptgcalls-main/example/groupCalls.php's joinCall() handler exactly,
 * adapted to take a chat_id + local file path instead of a Telegram command.
 */
function joinGroupCall(array $cmd, string $jobId, string $resultDir, Client $client, array &$calls): void
{
    $chatId  = (int) ($cmd['chat_id'] ?? 0);
    $file    = $cmd['file'] ?? null;
    $isVideo = (bool) ($cmd['is_video'] ?? false);

    if (!$chatId || !$file || !file_exists($file)) {
        writeResult($resultDir, $jobId, ['ok' => false, 'event' => 'error', 'message' => 'Missing/invalid chat_id or file']);
        return;
    }

    // A group call must already be active — the assistant can't start one.
    // (Confirmed in groupCalls.php: get_full_peer(...)->call must be non-null.)
    $call = $client->get_full_peer($chatId)->call ?? null;
    if ($call === null) {
        writeResult($resultDir, $jobId, ['ok' => false, 'event' => 'error', 'message' => 'No active voice chat in this group']);
        return;
    }

    $tgcalls = new Driver(client: $client, chat_id: $chatId);

    // Stop on disconnect/failure so we don't leak a dead Driver in $calls.
    $tgcalls->on(Event::Connection, function (object $event) use (&$calls, $chatId, $tgcalls): void {
        if (in_array($event->state, [ConnectionState::FAILED, ConnectionState::CLOSED], true)) {
            unset($calls[$chatId]);
        }
    });

    $params_json = $tgcalls->create();

    $audio = new AudioDescription(
        media_source: MediaSource::FILE,
        input: realpath($file),
        sample_rate: 96000,
        channel_count: 2,
        keep_open: false,
    );

    if ($isVideo) {
        $video = new VideoDescription(
            media_source: MediaSource::FILE,
            input: realpath($file),
            width: 1280,
            height: 720,
            fps: 30,
            keep_open: false,
        );
        $tgcalls->set_stream(microphone: $audio, camera: $video);
    } else {
        $tgcalls->set_stream(microphone: $audio);
    }

    $result = $client->phone->joinGroupCall(
        call: $call,
        join_as: $client->get_input_peer('me'),
        params: $client->dataJSON(data: $params_json),
    );

    foreach ($result->updates as $upd) {
        if ($upd->getClass() === 'updateGroupCallConnection') {
            $tgcalls->connect(params_json: $upd->params->data);
            $calls[$chatId] = ['driver' => $tgcalls, 'file' => $file, 'is_video' => $isVideo, 'volume' => 100];
            writeResult($resultDir, $jobId, ['ok' => true, 'event' => 'joined']);
            return;
        }
    }

    writeResult($resultDir, $jobId, ['ok' => false, 'event' => 'error', 'message' => 'joinGroupCall did not return a connection update']);
}

/** Re-stream a new file into a call the assistant is already in. */
function streamToExistingCall(array $cmd, string $jobId, string $resultDir, array &$calls): void
{
    $chatId  = (int) ($cmd['chat_id'] ?? 0);
    $file    = $cmd['file'] ?? null;
    $isVideo = (bool) ($cmd['is_video'] ?? false);
    $entry   = $calls[$chatId] ?? null;

    if (!$entry) {
        writeResult($resultDir, $jobId, ['ok' => false, 'event' => 'error', 'message' => 'Not currently in a call for this chat']);
        return;
    }
    if (!$file || !file_exists($file)) {
        writeResult($resultDir, $jobId, ['ok' => false, 'event' => 'error', 'message' => 'Invalid file']);
        return;
    }

    $driver = $entry['driver'];

    $audio = new AudioDescription(
        media_source: MediaSource::FILE,
        input: realpath($file),
        sample_rate: 96000,
        channel_count: 2,
        keep_open: false,
    );

    if ($isVideo) {
        $video = new VideoDescription(
            media_source: MediaSource::FILE,
            input: realpath($file),
            width: 1280,
            height: 720,
            fps: 30,
            keep_open: false,
        );
        $driver->set_stream(microphone: $audio, camera: $video);
    } else {
        $driver->set_stream(microphone: $audio);
    }

    $entry['file']     = $file;
    $entry['is_video'] = $isVideo;
    $calls[$chatId]    = $entry;

    writeResult($resultDir, $jobId, ['ok' => true, 'event' => 'playing']);
}

/**
 * Seek within the currently-playing file. There is no native seek
 * primitive (confirmed exhaustively from stubs/tgcalls.php) — the only
 * honest way to do this is to re-invoke ffmpeg with a -ss offset and pipe
 * its raw output into set_stream via MediaSource::SHELL, exactly the same
 * mechanism phptgcalls' own example uses for re-streaming (see playCall()
 * in groupCalls.php, which pipes ffmpeg -> set_stream).
 */
function seekExistingCall(array $cmd, string $jobId, string $resultDir, array &$calls): void
{
    $chatId  = (int) ($cmd['chat_id'] ?? 0);
    $seconds = (int) ($cmd['seconds'] ?? 0);
    $entry   = $calls[$chatId] ?? null;

    if (!$entry || !isset($entry['file'])) {
        writeResult($resultDir, $jobId, ['ok' => false, 'event' => 'error', 'message' => 'Not currently playing anything in this chat']);
        return;
    }

    $driver = $entry['driver'];
    $file   = $entry['file'];
    $isVideo = $entry['is_video'] ?? false;

    $audioCmd = sprintf(
        'ffmpeg -ss %d -i %s -f s16le -ac 2 -ar 96k pipe:1',
        $seconds, escapeshellarg($file)
    );
    $audio = new AudioDescription(
        media_source: MediaSource::SHELL,
        input: $audioCmd,
        sample_rate: 96000,
        channel_count: 2,
        keep_open: false,
    );

    if ($isVideo) {
        $videoCmd = sprintf(
            'ffmpeg -ss %d -i %s -f rawvideo -r 30 -pix_fmt yuv420p -vf scale=1280:720 pipe:1',
            $seconds, escapeshellarg($file)
        );
        $video = new VideoDescription(
            media_source: MediaSource::SHELL,
            input: $videoCmd,
            width: 1280, height: 720, fps: 30,
            keep_open: false,
        );
        $driver->set_stream(microphone: $audio, camera: $video);
    } else {
        $driver->set_stream(microphone: $audio);
    }

    writeResult($resultDir, $jobId, ['ok' => true, 'event' => 'playing']);
}

/**
 * Set playback volume. There is no native volume/gain primitive on the
 * extension (confirmed exhaustively from stubs/tgcalls.php) — applied via
 * ffmpeg's `volume` audio filter in the same MediaSource::SHELL pipe used
 * for seeking. $volumePercent is 1-200 (100 = original level), matching the
 * range the bot's /volume command already validates against.
 */
function setVolumeExistingCall(array $cmd, string $jobId, string $resultDir, array &$calls): void
{
    $chatId        = (int) ($cmd['chat_id'] ?? 0);
    $volumePercent = max(1, min(200, (int) ($cmd['value'] ?? 100)));
    $entry         = $calls[$chatId] ?? null;

    if (!$entry || !isset($entry['file'])) {
        writeResult($resultDir, $jobId, ['ok' => false, 'event' => 'error', 'message' => 'Not currently playing anything in this chat']);
        return;
    }

    $driver = $entry['driver'];
    $file   = $entry['file'];
    $gain   = $volumePercent / 100;

    $audioCmd = sprintf(
        'ffmpeg -i %s -af volume=%.2f -f s16le -ac 2 -ar 96k pipe:1',
        escapeshellarg($file), $gain
    );
    $audio = new AudioDescription(
        media_source: MediaSource::SHELL,
        input: $audioCmd,
        sample_rate: 96000,
        channel_count: 2,
        keep_open: false,
    );
    $driver->set_stream(microphone: $audio);
    $entry['volume'] = $volumePercent;
    $calls[$chatId] = $entry;

    writeResult($resultDir, $jobId, ['ok' => true, 'event' => 'volume_set']);
}

/** Shared helper for the simple boolean-returning Driver methods (pause/resume/mute/unmute). */
function respondBool(array $cmd, string $jobId, string $resultDir, array &$calls, callable $fn, string $successEvent): void
{
    $chatId = (int) ($cmd['chat_id'] ?? 0);
    $entry  = $calls[$chatId] ?? null;
    if (!$entry) {
        writeResult($resultDir, $jobId, ['ok' => false, 'event' => 'error', 'message' => 'Not currently in a call for this chat']);
        return;
    }
    $ok = $fn($entry['driver']);
    writeResult($resultDir, $jobId, $ok
        ? ['ok' => true, 'event' => $successEvent]
        : ['ok' => false, 'event' => 'error', 'message' => "$successEvent failed"]
    );
}

// ─── Main loop ────────────────────────────────────────────────────────────────

Loop::queue(static function () use ($settings, $cmdDir, $resultDir, &$client, &$calls): void {
    try {
        $client = new Client('assistant', 'string', $settings);
        $client->start(false);

        if (!$client->isAuthorized()) {
            logLine('❌ Assistant session is not authorized — check SESSION in .env (run bin/generate_session.php).');
            return;
        }

        $me = $client->get_me();
        logLine("✅ Assistant connected as @{$me->username} (id={$me->id})");
        logLine('Listening for commands in ' . $cmdDir);

        // Periodic tick: check the command queue every 300ms, indefinitely.
        while (true) {
            processCommandQueue($cmdDir, $resultDir, $client, $calls);
            delay(0.3);
        }
    } catch (\Throwable $e) {
        logLine('❌ Fatal error: ' . $e->getMessage());
    } finally {
        $client?->stop();
    }
});

Loop::run();

?>
