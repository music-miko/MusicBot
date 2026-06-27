<?php
/**
 * src/Core/VideoCallManager.php
 *
 * Bot-side bridge to the assistant worker (bin/assistant_worker.php).
 *
 * WHY A FILE QUEUE, NOT A SUBPROCESS:
 *   An earlier version of this class spawned a "phptgcalls binary" via
 *   proc_open() and talked to it over stdin/stdout pipes. That design was
 *   wrong — confirmed by reading phptgcalls' actual source
 *   (phptgcalls-main/example/groupCalls.php): phptgcalls (Tak\Tgcalls\Driver)
 *   is an in-process PHP library. Joining a group call requires holding the
 *   authenticated LiveProto Client directly in the same PHP process, inside
 *   Tak\Asyncio\Loop (Fiber-based).
 *
 *   bot.php runs its own separate, synchronous Bot-API polling loop
 *   (src/Core/Bot.php) and cannot also host an Asyncio event loop without a
 *   full rearchitecture. So the assistant runs as its own long-lived
 *   process (bin/assistant_worker.php), and this class talks to it through
 *   a simple file-based command/result queue:
 *
 *     queue/commands/<job_id>.json   written here, read by the worker
 *     queue/results/<job_id>.json    written by the worker, read here
 *
 * VERIFIED vs UNVERIFIED, as of last source review:
 *   - Confirmed by reading phptgcalls' real example/groupCalls.php: the
 *     join/stream/pause/resume/stop semantics this class assumes (Driver
 *     methods, AudioDescription/VideoDescription, MediaSource::FILE,
 *     requiring an active call to join) are accurate.
 *   - Confirmed by reading LiveProto's real source: get_full_peer(),
 *     get_input_peer(), get_me(), and messages->importChatInvite(...) all
 *     exist as real callable methods.
 *   - NOT independently confirmed: the exact parameter name LiveProto uses
 *     internally for messages.importChatInvite ('hash' is Telegram's
 *     documented public schema name, but wasn't found verbatim in this
 *     LiveProto checkout — see the matching comment in assistant_worker.php).
 *   - This class itself (the file-queue bridge) is OUR OWN design, since
 *     bot.php and the assistant must run as separate processes — there's no
 *     "correct" answer to verify here, just our own glue code.
 */

declare(strict_types=1);

namespace TeleMusic\Core;

class VideoCallManager
{
    private static ?self $instance = null;
    private Logger $log;

    /** Assistant's own Telegram user ID, learned from the "authenticated" event. Null until verifyAssistant() succeeds. */
    private static ?int $assistantUserId = null;

    /** Locally-tracked set of chat IDs we believe the assistant currently has an active call in (mirrors the worker's own $calls map, used for isActive() without a round-trip). */
    private array $activeChats = [];

    private string $queueDir;
    private string $cmdDir;
    private string $resultDir;

    private function __construct()
    {
        $this->log       = Logger::getInstance();
        $this->queueDir  = ROOT_DIR . '/queue';
        $this->cmdDir    = $this->queueDir . '/commands';
        $this->resultDir = $this->queueDir . '/results';
        foreach ([$this->queueDir, $this->cmdDir, $this->resultDir] as $dir) {
            if (!is_dir($dir)) mkdir($dir, 0755, true);
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Falls back to a default if config.php hasn't defined JOIN_TIMEOUT_SEC (e.g. stale deploy). */
    private const DEFAULT_JOIN_TIMEOUT_SEC = 25;

    private function joinTimeoutSec(): int
    {
        return defined('JOIN_TIMEOUT_SEC') ? (int) JOIN_TIMEOUT_SEC : self::DEFAULT_JOIN_TIMEOUT_SEC;
    }

    // ── Core queue mechanics ─────────────────────────────────────────────────────

    /**
     * Submit a command to the assistant worker and block (with timeout)
     * until its result file appears. Returns the decoded result array, or
     * null if the worker never responded in time (e.g. it isn't running).
     */
    private function submit(array $command, ?int $timeoutSec = null): ?array
    {
        $timeoutSec ??= $this->joinTimeoutSec();
        $jobId = bin2hex(random_bytes(8));
        $command['job_id'] = $jobId;

        $cmdFile    = "{$this->cmdDir}/{$jobId}.json";
        $resultFile = "{$this->resultDir}/{$jobId}.json";

        $tmp = "$cmdFile.tmp";
        file_put_contents($tmp, json_encode($command));
        rename($tmp, $cmdFile);

        $deadline = microtime(true) + $timeoutSec;
        while (microtime(true) < $deadline) {
            if (file_exists($resultFile)) {
                $raw = @file_get_contents($resultFile);
                @unlink($resultFile);
                $data = $raw ? json_decode($raw, true) : null;
                return is_array($data) ? $data : null;
            }
            usleep(150000); // 150ms poll
        }

        // Timed out — clean up whichever file might still be sitting there.
        @unlink($cmdFile);
        @unlink($resultFile);
        $this->log->error("[VCM] Command '{$command['action']}' timed out after {$timeoutSec}s — is bin/assistant_worker.php running?");
        return null;
    }

    // ── Public API (unchanged surface from the previous subprocess design) ──────

    /**
     * Verify the assistant session actually authenticates. Intended to be
     * called once at bot startup so a bad/expired SESSION is caught and
     * logged immediately, rather than only surfacing later as a confusing
     * "could not join voice chat" failure on someone's /play.
     */
    public function verifyAssistant(): bool
    {
        $result = $this->submit(['action' => 'whoami']);

        if ($result === null) {
            $this->log->error("[VCM] ❌ Assistant verification failed — no response from assistant_worker.php. Make sure it's running (php bin/assistant_worker.php).");
            return false;
        }

        if (($result['ok'] ?? false) && ($result['event'] ?? '') === 'authenticated') {
            $who = $result['username'] ?? $result['user_id'] ?? 'unknown';
            $this->log->info("[VCM] ✅ Assistant authenticated as $who");
            if (isset($result['user_id'])) {
                self::$assistantUserId = (int) $result['user_id'];
            }
            return true;
        }

        $reason = $result['message'] ?? 'unknown error';
        $this->log->error("[VCM] ❌ Assistant failed to authenticate: $reason");
        return false;
    }

    /** The assistant's own Telegram user ID, if known (set after a successful verifyAssistant() call). */
    public static function assistantUserId(): ?int
    {
        return self::$assistantUserId;
    }

    public const JOIN_INVITE_OK                  = 'joined';
    public const JOIN_INVITE_REQUEST_SENT        = 'invite_request_sent';
    public const JOIN_INVITE_ALREADY_PARTICIPANT = 'already_participant';
    public const JOIN_INVITE_FAILED              = 'failed';

    /**
     * Tell the ASSISTANT account to join a chat using an invite link —
     * mirrors Pyrogram's `await userbot.join_chat(invitelink)` in
     * tosu4/AnonXMusic/utils/decorators/play.py, translated to LiveProto's
     * `messages.importChatInvite`. The bot only ever generates the invite
     * link (TelegramApi::exportChatInviteLink); it cannot force-add a user.
     *
     * @return string One of the JOIN_INVITE_* constants above.
     */
    public function joinChatViaInvite(string $inviteLink): string
    {
        $hash = $this->extractInviteHash($inviteLink);
        $result = $this->submit(['action' => 'join_chat_via_invite', 'invite_hash' => $hash]);

        if ($result === null) {
            $this->log->error("[VCM] join_chat_via_invite: no response from assistant_worker.php");
            return self::JOIN_INVITE_FAILED;
        }

        $event = $result['event'] ?? '';
        switch ($event) {
            case 'chat_joined':
                $this->log->info("[VCM] ✅ Assistant joined chat via invite link");
                return self::JOIN_INVITE_OK;
            case 'invite_request_sent':
                $this->log->info("[VCM] ⏳ Assistant's join request is pending approval");
                return self::JOIN_INVITE_REQUEST_SENT;
            case 'already_participant':
                $this->log->info("[VCM] ℹ️ Assistant was already a participant");
                return self::JOIN_INVITE_ALREADY_PARTICIPANT;
            default:
                $reason = $result['message'] ?? 'unknown error';
                $this->log->error("[VCM] ❌ Assistant failed to join via invite: $reason");
                return self::JOIN_INVITE_FAILED;
        }
    }

    /**
     * Join (or re-use an existing call in) a chat and start streaming a file.
     *
     * @param int    $chatId   Telegram group/supergroup chat ID
     * @param string $file     Local path to audio/video file
     * @param bool   $isVideo  True = stream video+audio; false = audio only
     * @return bool  True only if the assistant confirmed it joined/is streaming.
     */
    public function joinAndPlay(int $chatId, string $file, bool $isVideo = false): bool
    {
        if (!file_exists($file)) {
            $this->log->error("[VCM] File not found: $file");
            return false;
        }

        if ($this->isActive($chatId)) {
            $result = $this->submit([
                'action'   => 'stream',
                'chat_id'  => $chatId,
                'file'     => $file,
                'is_video' => $isVideo,
            ]);
            $ok = $result !== null && ($result['ok'] ?? false) && ($result['event'] ?? '') === 'playing';
            if (!$ok) {
                $this->log->error("[VCM] stream failed for chat $chatId: " . ($result['message'] ?? 'no response'));
            }
            return $ok;
        }

        $result = $this->submit([
            'action'   => 'join',
            'chat_id'  => $chatId,
            'file'     => $file,
            'is_video' => $isVideo,
        ]);

        $ok = $result !== null && ($result['ok'] ?? false) && ($result['event'] ?? '') === 'joined';
        if ($ok) {
            $this->activeChats[$chatId] = true;
            $this->log->info("[VCM] ✅ Joined and streaming in chat $chatId");
        } else {
            $this->log->error("[VCM] join failed for chat $chatId: " . ($result['message'] ?? 'no response'));
        }
        return $ok;
    }

    public function pause(int $chatId): bool
    {
        return $this->simpleControl('pause', $chatId);
    }

    public function resume(int $chatId): bool
    {
        return $this->simpleControl('resume', $chatId);
    }

    public function stop(int $chatId): bool
    {
        $result = $this->submit(['action' => 'stop', 'chat_id' => $chatId]);
        unset($this->activeChats[$chatId]);
        return $result !== null && ($result['ok'] ?? false);
    }

    /**
     * Seek within the currently-playing file. No native seek primitive
     * exists in the extension — the worker re-invokes ffmpeg with a -ss
     * offset (see seekExistingCall in assistant_worker.php).
     */
    public function seek(int $chatId, int $seconds): bool
    {
        $result = $this->submit(['action' => 'seek', 'chat_id' => $chatId, 'seconds' => $seconds]);
        $ok = $result !== null && ($result['ok'] ?? false);
        if (!$ok) {
            $this->log->error("[VCM] seek failed for chat $chatId: " . ($result['message'] ?? 'no response'));
        }
        return $ok;
    }

    /**
     * Set playback volume (1-200, 100 = original). No native volume/gain
     * primitive exists in the extension — the worker applies ffmpeg's
     * `volume` audio filter (see setVolumeExistingCall in assistant_worker.php).
     */
    public function setVolume(int $chatId, int $volume): bool
    {
        $volume = max(1, min(200, $volume));
        $result = $this->submit(['action' => 'volume', 'chat_id' => $chatId, 'value' => $volume]);
        $ok = $result !== null && ($result['ok'] ?? false);
        if (!$ok) {
            $this->log->error("[VCM] setVolume failed for chat $chatId: " . ($result['message'] ?? 'no response'));
        }
        return $ok;
    }

    private function simpleControl(string $action, int $chatId): bool
    {
        $result = $this->submit(['action' => $action, 'chat_id' => $chatId]);
        return $result !== null && ($result['ok'] ?? false);
    }

    /**
     * Whether the assistant currently has an active call in this chat.
     * Checks our local cache first (fast path); falls back to asking the
     * worker directly if we're not sure (e.g. after a bot.php restart,
     * where activeChats starts empty even though the worker may still be
     * mid-call from before the restart).
     */
    public function isActive(int $chatId): bool
    {
        if (isset($this->activeChats[$chatId])) {
            return true;
        }
        $result = $this->submit(['action' => 'is_alive', 'chat_id' => $chatId], timeoutSec: 5);
        $alive = $result !== null && ($result['alive'] ?? false);
        if ($alive) {
            $this->activeChats[$chatId] = true;
        }
        return $alive;
    }

    /**
     * Extract the invite hash from a t.me invite link, matching the exact
     * logic confirmed in LiveProto's own get_input_peer() (Tl/Methods/Peers.php):
     * take the URL path's basename and trim a leading '+'.
     * Handles both link styles: https://t.me/+HASH and https://t.me/joinchat/HASH
     */
    private function extractInviteHash(string $inviteLink): string
    {
        $path = parse_url($inviteLink, PHP_URL_PATH) ?? '';
        $hash = basename($path);
        return ltrim($hash, '+');
    }
}
