<?php
/**
 * src/Core/VideoCallManager.php
 *
 * Manages Telegram Video Chat (Group Call) streaming via:
 *   - phptgcalls  (https://github.com/TakNone/phptgcalls)
 *   - LiveProto   (https://github.com/TakNone/LiveProto)
 *
 * Architecture:
 *   PHP Bot ──► VideoCallManager ──► phptgcalls process (per chat)
 *                                         │
 *                                     LiveProto (MTProto layer)
 *                                         │
 *                                  Telegram Group Call
 *
 * Each active chat spawns a persistent phptgcalls subprocess.
 * IPC is done via JSON over stdin/stdout of the subprocess.
 *
 * phptgcalls subprocess command format (stdin JSON):
 *   {"action":"join",   "chat_id":..., "file":"...", "video":false}
 *   {"action":"stream", "chat_id":..., "file":"..."}
 *   {"action":"pause",  "chat_id":...}
 *   {"action":"resume", "chat_id":...}
 *   {"action":"skip",   "chat_id":...}
 *   {"action":"stop",   "chat_id":...}
 *   {"action":"volume", "chat_id":..., "value":100}
 *   {"action":"seek",   "chat_id":..., "seconds":90}
 *
 * Responses (stdout JSON lines):
 *   {"event":"joined",  "chat_id":...}
 *   {"event":"playing", "chat_id":..., "file":"..."}
 *   {"event":"ended",   "chat_id":...}
 *   {"event":"error",   "chat_id":..., "message":"..."}
 */

declare(strict_types=1);

namespace TeleMusic\Core;

class VideoCallManager
{
    private static ?self $instance = null;

    /** @var array<int, resource> Running phptgcalls processes indexed by chat_id */
    private array $processes = [];

    /** @var array<int, resource[]> stdin/stdout pipes per process */
    private array $pipes = [];

    /** @var array<int, bool> Is audio-only (no video) */
    private array $audioOnly = [];

    private Logger $log;

    private function __construct()
    {
        $this->log = Logger::getInstance();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── Join / start streaming ────────────────────────────────────────────────

    /**
     * Join or re-use existing call and start streaming a file.
     *
     * Waits for phptgcalls' own "joined"/"error" event before returning,
     * since a successful pipe write only confirms the command was *sent* —
     * not that the assistant account actually authenticated and joined the
     * group call. Trusting the write alone was the root cause of the bot
     * reporting "Now Playing" while no audio was actually streaming.
     *
     * @param int    $chatId   Telegram group/supergroup chat ID
     * @param string $file     Local path to audio/video file (opus/mp4/mkv)
     * @param bool   $isVideo  True = stream video+audio; false = audio only
     * @return bool  True only if phptgcalls confirmed it joined and is streaming.
     */
    public function joinAndPlay(int $chatId, string $file, bool $isVideo = false): bool
    {
        if (!file_exists($file)) {
            $this->log->error("[VCM] File not found: $file");
            return false;
        }

        if ($this->isAlive($chatId)) {
            // Already in call — just stream the new file
            $sent = $this->sendCommand($chatId, [
                'action'  => 'stream',
                'chat_id' => $chatId,
                'file'    => $file,
            ]);
            if (!$sent) return false;
            return $this->awaitEvent($chatId, ['playing'], ['error'], JOIN_TIMEOUT_SEC);
        }

        // Spawn new phptgcalls process for this chat
        return $this->spawn($chatId, $file, $isVideo);
    }

    public function pause(int $chatId): bool
    {
        return $this->sendCommand($chatId, ['action' => 'pause', 'chat_id' => $chatId]);
    }

    public function resume(int $chatId): bool
    {
        return $this->sendCommand($chatId, ['action' => 'resume', 'chat_id' => $chatId]);
    }

    public function skip(int $chatId): bool
    {
        return $this->sendCommand($chatId, ['action' => 'skip', 'chat_id' => $chatId]);
    }

    public function stop(int $chatId): bool
    {
        $ok = $this->sendCommand($chatId, ['action' => 'stop', 'chat_id' => $chatId]);
        $this->cleanup($chatId);
        return $ok;
    }

    public function setVolume(int $chatId, int $volume): bool
    {
        $volume = max(1, min(200, $volume));
        return $this->sendCommand($chatId, [
            'action'  => 'volume',
            'chat_id' => $chatId,
            'value'   => $volume,
        ]);
    }

    public function seek(int $chatId, int $seconds): bool
    {
        return $this->sendCommand($chatId, [
            'action'  => 'seek',
            'chat_id' => $chatId,
            'seconds' => $seconds,
        ]);
    }

    public function isActive(int $chatId): bool
    {
        return $this->isAlive($chatId);
    }

    // ── Process management ────────────────────────────────────────────────────

    private function spawn(int $chatId, string $file, bool $isVideo): bool
    {
        $bin = PHPTGCALLS_BIN;
        if (!file_exists($bin)) {
            $this->log->error("[VCM] phptgcalls binary not found at: $bin");
            $this->log->info("[VCM] Hint: clone https://github.com/TakNone/phptgcalls and build/place binary at $bin");
            return false;
        }

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin  (we write commands)
            1 => ['pipe', 'w'],  // stdout (we read events)
            2 => ['pipe', 'w'],  // stderr (log)
        ];

        $env = [
            'TG_API_ID'   => API_ID,
            'TG_API_HASH' => API_HASH,
            'TG_SESSION'  => SESSION,
            'TG_BOT_TOKEN'=> BOT_TOKEN,
        ];

        $process = proc_open($bin, $descriptors, $pipes, ROOT_DIR, $env);
        if (!is_resource($process)) {
            $this->log->error("[VCM] Failed to spawn phptgcalls for chat $chatId");
            return false;
        }

        // Non-blocking reads
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->processes[$chatId] = $process;
        $this->pipes[$chatId]     = $pipes;
        $this->audioOnly[$chatId] = !$isVideo;

        $this->log->info("[VCM] Spawned phptgcalls for chat $chatId");

        // Send join+play command
        $sent = $this->sendCommand($chatId, [
            'action'  => 'join',
            'chat_id' => $chatId,
            'file'    => $file,
            'video'   => $isVideo,
        ]);
        if (!$sent) {
            $this->log->error("[VCM] Failed to write join command for chat $chatId");
            $this->cleanup($chatId);
            return false;
        }

        // Block (with timeout) until phptgcalls confirms it actually joined
        // the group call and started streaming — a successful pipe write
        // above only means the command was *sent*, not that the assistant
        // account authenticated and joined.
        $joined = $this->awaitEvent($chatId, ['joined', 'playing'], ['error'], JOIN_TIMEOUT_SEC);
        if (!$joined) {
            $this->log->error("[VCM] phptgcalls did not confirm join for chat $chatId — tearing down");
            $this->cleanup($chatId);
            return false;
        }

        return true;
    }

    /**
     * Block (briefly, with timeout) reading the subprocess's stdout for one
     * of the given success or failure event names. Returns true only on a
     * success event; false on a failure event, timeout, or dead process.
     *
     * @param string[] $successEvents e.g. ['joined', 'playing']
     * @param string[] $failureEvents e.g. ['error']
     */
    private function awaitEvent(int $chatId, array $successEvents, array $failureEvents, int $timeoutSec): bool
    {
        if (!isset($this->pipes[$chatId])) {
            return false;
        }
        $stdout = $this->pipes[$chatId][1];
        $deadline = microtime(true) + $timeoutSec;

        while (microtime(true) < $deadline) {
            if (!$this->isAlive($chatId)) {
                $this->log->error("[VCM] Process died while awaiting event for chat $chatId");
                return false;
            }

            $read   = [$stdout];
            $write  = null;
            $except = null;
            $ready  = @stream_select($read, $write, $except, 0, 200000); // 200ms poll

            if ($ready === false || $ready === 0) {
                continue;
            }

            $line = fgets($stdout);
            if ($line === false || trim($line) === '') {
                continue;
            }

            $data = json_decode(trim($line), true);
            if (!is_array($data) || !isset($data['event'])) {
                continue;
            }

            $event = $data['event'];
            if (in_array($event, $successEvents, true)) {
                $this->log->info("[VCM] Confirmed event '$event' for chat $chatId");
                return true;
            }
            if (in_array($event, $failureEvents, true)) {
                $reason = $data['message'] ?? 'unknown error';
                $this->log->error("[VCM] phptgcalls reported error for chat $chatId: $reason");
                return false;
            }
            // Any other event (e.g. unrelated status) — keep waiting.
        }

        $this->log->error("[VCM] Timed out waiting for join confirmation for chat $chatId (waited {$timeoutSec}s)");
        return false;
    }

    private function sendCommand(int $chatId, array $cmd): bool
    {
        if (!$this->isAlive($chatId)) {
            $this->log->warning("[VCM] No active process for chat $chatId");
            return false;
        }
        $json = json_encode($cmd) . "\n";
        $written = @fwrite($this->pipes[$chatId][0], $json);
        return $written !== false;
    }

    private function isAlive(int $chatId): bool
    {
        if (!isset($this->processes[$chatId])) {
            return false;
        }
        $status = proc_get_status($this->processes[$chatId]);
        if (!$status['running']) {
            $this->cleanup($chatId);
            return false;
        }
        return true;
    }

    private function cleanup(int $chatId): void
    {
        if (isset($this->pipes[$chatId])) {
            foreach ($this->pipes[$chatId] as $pipe) {
                if (is_resource($pipe)) fclose($pipe);
            }
            unset($this->pipes[$chatId]);
        }
        if (isset($this->processes[$chatId])) {
            proc_close($this->processes[$chatId]);
            unset($this->processes[$chatId]);
        }
        unset($this->audioOnly[$chatId]);
        $this->log->info("[VCM] Cleaned up process for chat $chatId");
    }

    /**
     * Poll all running processes for events (call from main loop periodically).
     * Returns array of events: [['chat_id'=>..., 'event'=>..., ...], ...]
     */
    public function pollEvents(): array
    {
        $events = [];
        foreach ($this->processes as $chatId => $proc) {
            if (!$this->isAlive($chatId)) continue;
            $line = fgets($this->pipes[$chatId][1]);
            if ($line === false || $line === '') continue;
            $data = json_decode(trim($line), true);
            if (is_array($data)) {
                $events[] = $data;
            }
            // Drain stderr to log
            $err = stream_get_contents($this->pipes[$chatId][2]);
            if ($err) {
                $this->log->debug("[VCM][chat=$chatId] stderr: " . trim($err));
            }
        }
        return $events;
    }

    public function __destruct()
    {
        foreach (array_keys($this->processes) as $chatId) {
            $this->cleanup($chatId);
        }
    }
}
