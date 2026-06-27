<?php
/**
 * src/Commands/HelpCommand.php
 *
 * /help — mirrors tosu4's help_pannel(): a category chooser rather than
 * one big wall of text. Tapping a category edits the message in place;
 * a Back button returns to the /start panel.
 */

declare(strict_types=1);

namespace TeleMusic\Commands;

use TeleMusic\Core\TelegramApi;

class HelpCommand
{
    private TelegramApi $tg;

    /** Category key => [title, body] shown when a help button is tapped. */
    public const CATEGORIES = [
        'help_playback' => [
            'title' => '▶️ Playback',
            'body'  =>
                "/play &lt;URL or query&gt; — Play audio in voice chat\n" .
                "/vplay &lt;URL or query&gt; — Play video in video chat\n\n" .
                "<b>Supported URLs</b>\n" .
                "• <code>https://youtube.com/watch?v=...</code>\n" .
                "• <code>https://youtu.be/...</code>\n" .
                "• <code>https://open.spotify.com/track/...</code>\n" .
                "• <code>https://open.spotify.com/playlist/...</code>\n" .
                "• <code>https://open.spotify.com/album/...</code>\n\n" .
                "You can also just type a song name to search.",
        ],
        'help_controls' => [
            'title' => '⏯ Controls',
            'body'  =>
                "/pause — Pause current track\n" .
                "/resume — Resume playback\n" .
                "/skip — Skip to next track\n" .
                "/stop — Stop and leave voice chat",
        ],
        'help_queue' => [
            'title' => '📋 Queue',
            'body'  =>
                "/queue — View current queue\n" .
                "/shuffle — Shuffle the queue\n" .
                "/loop — Toggle loop mode (off → track → queue)",
        ],
        'help_settings' => [
            'title' => '⚙️ Settings',
            'body'  =>
                "/seek 1:30 — Seek to position\n" .
                "/volume 80 — Set volume (1–200)\n" .
                "/ping — Check bot latency",
        ],
    ];

    public function __construct()
    {
        $this->tg = TelegramApi::getInstance();
    }

    public function show(array $message): void
    {
        $chatId = $message['chat']['id'];

        $text = "Choose a category to explore available commands.\n\n" .
            "For support, visit <a href=\"" . SUPPORT_CHAT . "\">our group</a>.\n\n" .
            "All commands use the <code>/</code> prefix.";

        $this->tg->sendMessage($chatId, $text, [
            'reply_markup' => TelegramApi::helpPanel(),
        ]);
    }
}
