<?php
/**
 * src/Commands/HelpCommand.php
 */

declare(strict_types=1);

namespace TeleMusic\Commands;

use TeleMusic\Core\TelegramApi;

class HelpCommand
{
    private TelegramApi $tg;

    public function __construct()
    {
        $this->tg = TelegramApi::getInstance();
    }

    public function show(array $message): void
    {
        $chatId = $message['chat']['id'];

        $text = "🎵 <b>TeleMusic Bot v1.0.0</b>\n\n" .
            "<b>Platforms:</b> YouTube, Spotify\n\n" .
            "<b>▶ Playback</b>\n" .
            "/play &lt;URL or query&gt; — Play audio in voice chat\n" .
            "/vplay &lt;URL or query&gt; — Play video in video chat\n\n" .
            "<b>⏯ Controls</b>\n" .
            "/pause — Pause current track\n" .
            "/resume — Resume playback\n" .
            "/skip — Skip to next track\n" .
            "/stop — Stop and leave voice chat\n\n" .
            "<b>📋 Queue</b>\n" .
            "/queue — View current queue\n" .
            "/shuffle — Shuffle the queue\n" .
            "/loop — Toggle loop mode (off → track → queue)\n\n" .
            "<b>⚙️ Settings</b>\n" .
            "/seek 1:30 — Seek to position\n" .
            "/volume 80 — Set volume (1-200)\n\n" .
            "<b>📡 Supported URLs</b>\n" .
            "• <code>https://youtube.com/watch?v=...</code>\n" .
            "• <code>https://youtu.be/...</code>\n" .
            "• <code>https://open.spotify.com/track/...</code>\n" .
            "• <code>https://open.spotify.com/playlist/...</code>\n" .
            "• <code>https://open.spotify.com/album/...</code>\n\n" .
            "/ping — Check bot latency";

        $this->tg->sendMessage($chatId, $text);
    }
}
