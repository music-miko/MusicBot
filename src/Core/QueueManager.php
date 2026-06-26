<?php
/**
 * src/Core/QueueManager.php
 *
 * Per-chat track queue. Supports:
 *   - Add to end / force-play (front)
 *   - Skip (pop front)
 *   - Shuffle
 *   - Loop toggle (track / queue / off)
 *   - Peek at now-playing
 *
 * Track array shape (mirrors tosu4's stream.py queue format):
 * [
 *   'title'        => string,
 *   'artist'       => string,
 *   'duration_min' => string,   // "3:45"
 *   'duration_sec' => int,
 *   'thumb'        => string,   // thumbnail URL
 *   'file'         => string,   // local downloaded file path
 *   'vidid'        => string,   // YouTube video ID or Spotify track ID
 *   'link'         => string,   // original URL
 *   'platform'     => string,   // 'youtube' | 'spotify'
 *   'is_video'     => bool,
 *   'user_id'      => int,
 *   'user_name'    => string,
 * ]
 */

declare(strict_types=1);

namespace TeleMusic\Core;

class QueueManager
{
    private static ?self $instance = null;

    /** @var array<int, array[]> Queue per chat_id */
    private array $queues = [];

    /** @var array<int, string> Loop mode: 'off' | 'track' | 'queue' */
    private array $loopMode = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── Mutators ─────────────────────────────────────────────────────────────

    /** Add a track to the end of the queue. */
    public function enqueue(int $chatId, array $track): void
    {
        if (!isset($this->queues[$chatId])) {
            $this->queues[$chatId] = [];
        }
        $this->queues[$chatId][] = $track;
    }

    /** Force-play: prepend track (index 0 = now playing, index 1 = next). */
    public function forcePlay(int $chatId, array $track): void
    {
        if (!isset($this->queues[$chatId])) {
            $this->queues[$chatId] = [];
        }
        // Insert at position 1 so it plays after the current track ends,
        // but current track is replaced by skipping below.
        array_unshift($this->queues[$chatId], $track);
    }

    /**
     * Pop the next track (called when track ends or /skip).
     * Handles loop modes.
     */
    public function popNext(int $chatId): ?array
    {
        $queue = $this->queues[$chatId] ?? [];
        if (empty($queue)) {
            return null;
        }

        $mode = $this->loopMode[$chatId] ?? 'off';

        if ($mode === 'track') {
            // Repeat same track
            return $queue[0];
        }

        // Remove current
        $current = array_shift($this->queues[$chatId]);

        if ($mode === 'queue') {
            // Add current back to end
            $this->queues[$chatId][] = $current;
        }

        return $this->queues[$chatId][0] ?? null;
    }

    /** Skip current track and return the next one. */
    public function skip(int $chatId): ?array
    {
        $queue = $this->queues[$chatId] ?? [];
        if (empty($queue)) {
            return null;
        }
        array_shift($this->queues[$chatId]);
        return $this->queues[$chatId][0] ?? null;
    }

    public function clear(int $chatId): void
    {
        unset($this->queues[$chatId], $this->loopMode[$chatId]);
    }

    public function shuffle(int $chatId): void
    {
        if (!empty($this->queues[$chatId])) {
            $current = array_shift($this->queues[$chatId]);
            shuffle($this->queues[$chatId]);
            array_unshift($this->queues[$chatId], $current);
        }
    }

    public function toggleLoop(int $chatId): string
    {
        $modes = ['off', 'track', 'queue'];
        $current = $this->loopMode[$chatId] ?? 'off';
        $idx = array_search($current, $modes, true);
        $next = $modes[($idx + 1) % count($modes)];
        $this->loopMode[$chatId] = $next;
        return $next;
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function nowPlaying(int $chatId): ?array
    {
        return $this->queues[$chatId][0] ?? null;
    }

    public function getQueue(int $chatId): array
    {
        return $this->queues[$chatId] ?? [];
    }

    public function count(int $chatId): int
    {
        return count($this->queues[$chatId] ?? []);
    }

    public function isEmpty(int $chatId): bool
    {
        return empty($this->queues[$chatId]);
    }

    public function getLoopMode(int $chatId): string
    {
        return $this->loopMode[$chatId] ?? 'off';
    }
}
