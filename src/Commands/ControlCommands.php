<?php
/**
 * src/Commands/ControlCommands.php
 *
 * Playback control commands:
 *   /pause  /resume  /skip  /stop  /shuffle  /loop  /seek  /volume
 */

declare(strict_types=1);

namespace TeleMusic\Commands;

use TeleMusic\Core\Logger;
use TeleMusic\Core\QueueManager;
use TeleMusic\Core\TelegramApi;
use TeleMusic\Core\VideoCallManager;
use TeleMusic\Platforms\YouTube;

class ControlCommands
{
    private TelegramApi      $tg;
    private Logger           $log;
    private QueueManager     $queue;
    private VideoCallManager $vcm;
    private YouTube          $yt;

    public function __construct()
    {
        $this->tg    = TelegramApi::getInstance();
        $this->log   = Logger::getInstance();
        $this->queue = QueueManager::getInstance();
        $this->vcm   = VideoCallManager::getInstance();
        $this->yt    = new YouTube();
    }

    // ── Pause ─────────────────────────────────────────────────────────────────

    public function pause(array $message): void
    {
        $chatId = $message['chat']['id'];

        if (!$this->vcm->isActive($chatId)) {
            $this->tg->sendMessage($chatId, '❌ Nothing is playing.');
            return;
        }

        if ($this->vcm->pause($chatId)) {
            $np = $this->queue->nowPlaying($chatId);
            $title = $np ? $np['title'] : 'the stream';
            $this->tg->sendMessage($chatId,
                "⏸ <b>Paused:</b> <i>$title</i>",
                ['reply_markup' => TelegramApi::pausedKeyboard($chatId)]
            );
        } else {
            $this->tg->sendMessage($chatId, '❌ Failed to pause.');
        }
    }

    // ── Resume ────────────────────────────────────────────────────────────────

    public function resume(array $message): void
    {
        $chatId = $message['chat']['id'];

        if (!$this->vcm->isActive($chatId)) {
            $this->tg->sendMessage($chatId, '❌ Nothing is paused.');
            return;
        }

        if ($this->vcm->resume($chatId)) {
            $np = $this->queue->nowPlaying($chatId);
            $title = $np ? $np['title'] : 'the stream';
            $this->tg->sendMessage($chatId,
                "▶ <b>Resumed:</b> <i>$title</i>",
                ['reply_markup' => TelegramApi::playerKeyboard($chatId)]
            );
        } else {
            $this->tg->sendMessage($chatId, '❌ Failed to resume.');
        }
    }

    // ── Skip ──────────────────────────────────────────────────────────────────

    public function skip(array $message): void
    {
        $chatId = $message['chat']['id'];

        if (!$this->vcm->isActive($chatId)) {
            $this->tg->sendMessage($chatId, '❌ Nothing is playing.');
            return;
        }

        $next = $this->queue->skip($chatId);

        if (!$next) {
            // Queue exhausted → stop
            $this->vcm->stop($chatId);
            $this->queue->clear($chatId);
            $this->tg->sendMessage($chatId, '⏹ Queue ended. Left the voice chat.');
            return;
        }

        // Play next track
        $file = $next['file'] ?? null;
        if (!$file || !file_exists($file)) {
            $this->tg->sendMessage($chatId, "⚠️ Next track file missing, resolving…");
            // Could trigger a re-download here; for v1 just skip again
            $this->skip($message);
            return;
        }

        $this->vcm->joinAndPlay($chatId, $file, $next['is_video'] ?? false);
        $this->sendSkipCard($chatId, $next);
    }

    // ── Stop ──────────────────────────────────────────────────────────────────

    public function stop(array $message): void
    {
        $chatId = $message['chat']['id'];

        $this->vcm->stop($chatId);
        $this->queue->clear($chatId);
        $this->tg->sendMessage($chatId, '⏹ <b>Stopped.</b> Left the voice chat and cleared the queue.');
    }

    // ── Shuffle ───────────────────────────────────────────────────────────────

    public function shuffle(array $message): void
    {
        $chatId = $message['chat']['id'];

        if ($this->queue->isEmpty($chatId)) {
            $this->tg->sendMessage($chatId, '❌ Queue is empty.');
            return;
        }

        $this->queue->shuffle($chatId);
        $count = $this->queue->count($chatId);
        $this->tg->sendMessage($chatId, "🔀 <b>Shuffled {$count} tracks in the queue.</b>");
    }

    // ── Loop ──────────────────────────────────────────────────────────────────

    public function loop(array $message): void
    {
        $chatId = $message['chat']['id'];
        $mode   = $this->queue->toggleLoop($chatId);

        $icons = ['off' => '❌ Off', 'track' => '🔂 Track', 'queue' => '🔁 Queue'];
        $label = $icons[$mode] ?? $mode;
        $this->tg->sendMessage($chatId, "🔁 <b>Loop mode:</b> $label");
    }

    // ── Seek ──────────────────────────────────────────────────────────────────

    public function seek(array $message, string $args): void
    {
        $chatId = $message['chat']['id'];

        if (!$this->vcm->isActive($chatId)) {
            $this->tg->sendMessage($chatId, '❌ Nothing is playing.');
            return;
        }

        $seconds = $this->parseTime($args);
        if ($seconds === null) {
            $this->tg->sendMessage($chatId,
                "❌ <b>Invalid time format.</b>\n" .
                "Usage: <code>/seek 1:30</code> or <code>/seek 90</code>"
            );
            return;
        }

        if ($this->vcm->seek($chatId, $seconds)) {
            $fmt = $this->yt->secondsToMin($seconds);
            $this->tg->sendMessage($chatId, "⏩ <b>Seeked to:</b> $fmt");
        } else {
            $this->tg->sendMessage($chatId, '❌ Seek failed.');
        }
    }

    // ── Volume ────────────────────────────────────────────────────────────────

    public function volume(array $message, string $args): void
    {
        $chatId = $message['chat']['id'];
        $vol    = (int) $args;

        if ($vol < 1 || $vol > 200) {
            $this->tg->sendMessage($chatId,
                "❌ <b>Volume must be between 1 and 200.</b>\n" .
                "Usage: <code>/volume 100</code>"
            );
            return;
        }

        if (!$this->vcm->isActive($chatId)) {
            $this->tg->sendMessage($chatId, '❌ Nothing is playing.');
            return;
        }

        if ($this->vcm->setVolume($chatId, $vol)) {
            $bar = $this->volumeBar($vol);
            $this->tg->sendMessage($chatId, "🔊 <b>Volume set to {$vol}%</b>\n$bar");
        } else {
            $this->tg->sendMessage($chatId, '❌ Failed to set volume.');
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function sendSkipCard(int $chatId, array $track): void
    {
        $caption = "⏭ <b>Skipped</b> — Now Playing\n\n" .
            "🎼 <b>{$track['title']}</b>\n" .
            "👤 {$track['artist']}\n" .
            "⏱ {$track['duration_min']}";

        $keyboard = TelegramApi::playerKeyboard($chatId);

        if (!empty($track['thumb'])) {
            $this->tg->sendPhoto($chatId, $track['thumb'], $caption, [
                'reply_markup' => $keyboard,
            ]);
        } else {
            $this->tg->sendMessage($chatId, $caption, ['reply_markup' => $keyboard]);
        }
    }

    /** Parse "1:30" or "90" → int seconds */
    private function parseTime(string $input): ?int
    {
        $input = trim($input);
        if (preg_match('/^(\d+):(\d{2})$/', $input, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }
        if (ctype_digit($input)) {
            return (int) $input;
        }
        return null;
    }

    /** ASCII volume bar */
    private function volumeBar(int $vol): string
    {
        $filled = (int) round($vol / 200 * 10);
        return '[' . str_repeat('█', $filled) . str_repeat('░', 10 - $filled) . "]";
    }
}
