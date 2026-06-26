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
     * @param int    $chatId   Telegram group/supergroup chat ID
     * @param string $file     Local path to audio/video file (opus/mp4/mkv)
     * @param bool   $isVideo  True = stream video+audio; false = audio only
     * @return bool
     */
    public function joinAndPlay(int $chatId, string $file, bool $isVideo = false): bool
    {
        if (!file_exists($file)) {
            $this->log->error("[VCM] File not found: $file");
            return false;
        }

        if ($this->isAlive($chatId)) {
            // Already in call — just stream the new file
            return $this->sendCommand($chatId, [
                'action' => 'stream',
                'chat_id' => $chatId,
                'file'   => $file,
            ]);
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
        return $this->sendCommand($chatId, [
            'action'  => 'join',
            'chat_id' => $chatId,
            'file'    => $file,
            'video'   => $isVideo,
        ]);
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
