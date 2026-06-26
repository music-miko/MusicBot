<?php
/**
 * src/Commands/PlayCommand.php
 *
 * /play  <url|query>   — Audio stream
 * /vplay <url|query>   — Video stream
 *
 * Flow (mirrors tosu4's stream.py):
 *   1. Send "🔍 Searching…" message
 *   2. Resolve platform → download file
 *   3. Enqueue track
 *   4. If already playing → "Added to queue" edit
 *   5. Else → join video chat and start streaming
 *   6. Delete "Searching" message, send Now Playing photo card
 */

declare(strict_types=1);

namespace TeleMusic\Commands;

use TeleMusic\Core\Logger;
use TeleMusic\Core\QueueManager;
use TeleMusic\Core\TelegramApi;
use TeleMusic\Core\VideoCallManager;
use TeleMusic\Platforms\PlatformResolver;

class PlayCommand
{
    private TelegramApi     $tg;
    private Logger          $log;
    private QueueManager    $queue;
    private VideoCallManager $vcm;
    private PlatformResolver $resolver;

    public function __construct()
    {
        $this->tg       = TelegramApi::getInstance();
        $this->log      = Logger::getInstance();
        $this->queue    = QueueManager::getInstance();
        $this->vcm      = VideoCallManager::getInstance();
        $this->resolver = new PlatformResolver();
    }

    public function execute(array $message, string $args, bool $isVideo): void
    {
        $chatId   = $message['chat']['id'];
        $userId   = $message['from']['id'];
        $userName = $message['from']['first_name'] ?? 'User';

        if (empty($args)) {
            $this->tg->sendMessage($chatId,
                "❌ <b>Please provide a URL or search query.</b>\n\n" .
                "Usage:\n" .
                "<code>/play Shape of You</code>\n" .
                "<code>/play https://youtube.com/watch?v=...</code>\n" .
                "<code>/play https://open.spotify.com/track/...</code>"
            );
            return;
        }

        // Step 1: Send searching indicator
        $indicator = $this->tg->sendMessage($chatId,
            ($isVideo ? '🎬' : '🎵') . " <b>Searching…</b> <code>$args</code>"
        );
        $indicatorId = $indicator['message_id'] ?? null;

        try {
            // Step 2: Resolve track(s)
            $tracks = $this->resolver->resolve($args, $isVideo);

            if (empty($tracks)) {
                $this->editOrSend($chatId, $indicatorId,
                    "❌ <b>No results found for:</b> <code>$args</code>\n" .
                    "Please try a different query or URL."
                );
                return;
            }

            // Attach user info to all tracks
            foreach ($tracks as &$t) {
                $t['user_id']   = $userId;
                $t['user_name'] = $userName;
            }
            unset($t);

            $isPlaylist  = count($tracks) > 1;
            $firstTrack  = $tracks[0];
            $alreadyPlaying = $this->vcm->isActive($chatId);

            // Step 3: Enqueue
            foreach ($tracks as $track) {
                $this->queue->enqueue($chatId, $track);
            }

            // Step 4: Already playing → just add to queue
            if ($alreadyPlaying) {
                if ($isPlaylist) {
                    $this->editOrSend($chatId, $indicatorId,
                        "✅ <b>Added " . count($tracks) . " tracks to queue.</b>\n" .
                        "Use /queue to view."
                    );
                } else {
                    $queuePos = $this->queue->count($chatId);
                    $this->editOrSend($chatId, $indicatorId,
                        "✅ <b>Added to queue</b> [#{$queuePos}]\n" .
                        "🎵 <b>{$firstTrack['title']}</b>\n" .
                        "⏱ {$firstTrack['duration_min']}"
                    );
                }
                return;
            }

            // Step 5: Start streaming
            $file = $firstTrack['file'] ?? null;
            if (!$file || !file_exists($file)) {
                $this->editOrSend($chatId, $indicatorId,
                    "❌ <b>Download failed.</b> Please try again."
                );
                // Remove from queue
                $this->queue->clear($chatId);
                return;
            }

            $joined = $this->vcm->joinAndPlay($chatId, $file, $isVideo);
            if (!$joined) {
                $this->editOrSend($chatId, $indicatorId,
                    "❌ <b>Could not join the voice chat.</b>\n" .
                    "Make sure the bot is an admin and a voice chat is active."
                );
                $this->queue->clear($chatId);
                return;
            }

            // Step 6: Delete indicator, send Now Playing card
            if ($indicatorId) {
                $this->tg->deleteMessage($chatId, $indicatorId);
            }

            $this->sendNowPlaying($chatId, $firstTrack, $isPlaylist ? count($tracks) : 0);

        } catch (\Throwable $e) {
            $this->log->error("[PlayCommand] " . $e->getMessage());
            $this->editOrSend($chatId, $indicatorId,
                "❌ <b>An error occurred.</b>\n<code>" . htmlspecialchars($e->getMessage()) . "</code>"
            );
        }
    }

    // ── Now Playing card ──────────────────────────────────────────────────────

    private function sendNowPlaying(int $chatId, array $track, int $playlistSize = 0): void
    {
        $platformIcon = match ($track['platform']) {
            'spotify' => '🎵 Spotify',
            'youtube' => '▶️ YouTube',
            default   => '🎵',
        };

        $extra = $playlistSize > 1
            ? "\n📋 <i>+{$playlistSize} tracks added to queue</i>"
            : '';

        $caption = "🎵 <b>Now Playing</b>\n\n" .
            "🎼 <b>{$track['title']}</b>\n" .
            "👤 {$track['artist']}\n" .
            "⏱ {$track['duration_min']}\n" .
            "🎧 $platformIcon" .
            $extra . "\n\n" .
            "🔊 Requested by: {$track['user_name']}";

        $keyboard = TelegramApi::playerKeyboard($chatId);

        if (!empty($track['thumb'])) {
            $this->tg->sendPhoto($chatId, $track['thumb'], $caption, [
                'reply_markup' => $keyboard,
            ]);
        } else {
            $this->tg->sendMessage($chatId, $caption, [
                'reply_markup' => $keyboard,
            ]);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function editOrSend(int $chatId, ?int $msgId, string $text): void
    {
        if ($msgId) {
            $this->tg->editMessageText($chatId, $msgId, $text);
        } else {
            $this->tg->sendMessage($chatId, $text);
        }
    }
}
