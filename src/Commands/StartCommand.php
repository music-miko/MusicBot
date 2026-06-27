<?php
/**
 * src/Commands/StartCommand.php
 *
 * /start command — mirrors tosu4/AnonXMusic/plugins/bot/start.py:
 *
 *   - Private chat → photo + caption + private_panel (Add to Group,
 *     Help & Commands, Support Chat, Updates, Setup Guide).
 *   - Group chat   → "<bot> is ready" text + uptime + startGroupPanel.
 *   - New chat member (the bot itself being added) → welcome message
 *     with a Setup Guide button.
 */

declare(strict_types=1);

namespace TeleMusic\Commands;

use TeleMusic\Core\Logger;
use TeleMusic\Core\TelegramApi;

class StartCommand
{
    private TelegramApi $tg;
    private Logger      $log;

    public function __construct()
    {
        $this->tg  = TelegramApi::getInstance();
        $this->log = Logger::getInstance();
    }

    public function show(array $message): void
    {
        $chatId   = $message['chat']['id'];
        $chatType = $message['chat']['type'] ?? 'private';
        $userName = $message['from']['first_name'] ?? 'there';
        $userId   = $message['from']['id'] ?? null;

        if ($chatType === 'private') {
            $this->showPrivate($chatId, $userName);
        } else {
            $this->showGroup($chatId);
        }

        $this->log->debug("[StartCommand] user=$userId chat=$chatId type=$chatType");
    }

    private function showPrivate(int|string $chatId, string $userName): void
    {
        $botMention = TelegramApi::botMention();
        $caption = "👋 Hello, " . htmlspecialchars($userName) . ".\n\n" .
            "{$botMention} is a music bot for Telegram — stream from YouTube and " .
            "Spotify, right inside any group voice chat.\n\n" .
            "Use /help to explore all commands.";

        $this->tg->sendPhoto($chatId, START_IMG_URL, $caption, [
            'reply_markup' => TelegramApi::privatePanel(),
        ]);
    }

    private function showGroup(int|string $chatId): void
    {
        $botMention = TelegramApi::botMention();
        $uptime     = $this->readableUptime(time() - BOOT_TIME);

        $text = "👋 <b>{$botMention} is ready</b>\n\n" .
            "<b>Uptime:</b> <code>{$uptime}</code>\n\n" .
            "A music player with support for YouTube and Spotify.";

        $this->tg->sendMessage($chatId, $text, [
            'reply_markup' => TelegramApi::startGroupPanel(),
        ]);
    }

    /**
     * Called when the bot itself is added to a new group
     * (mirrors the `welcome()` handler in tosu4's start.py).
     */
    public function welcome(int|string $chatId, string $addedByName, string $chatTitle): void
    {
        $botMention = TelegramApi::botMention();
        $text = "Hello " . htmlspecialchars($addedByName) . "!\n\n" .
            "Thank you for adding {$botMention} to <b>" . htmlspecialchars($chatTitle) . "</b>. " .
            "I'm ready to bring music to your group.\n\n" .
            "Use the buttons below — tap <b>Setup Guide</b> for a quick walkthrough.";

        $this->tg->sendMessage($chatId, $text, [
            'reply_markup' => TelegramApi::welcomePanel(),
        ]);
    }

    public static function setupGuideText(): string
    {
        $botMention = TelegramApi::botMention();
        return "🅰️ <b>Setup Guide</b>\n\n" .
            "Get {$botMention} up and running in your group in under a minute:\n\n" .
            "<b>Step 1 — Add the bot</b>\n" .
            "Tap <b>Add to Group</b> and select your group.\n\n" .
            "<b>Step 2 — Promote the bot</b>\n" .
            "Make {$botMention} an admin and grant the <b>Invite Users via Link</b> " .
            "permission. This is required for voice chats.\n\n" .
            "<b>Step 3 — Start a voice chat</b>\n" .
            "Open your group and start a voice/video chat.\n\n" .
            "<b>Step 4 — Play music</b>\n" .
            "Use <code>/play song name</code> or <code>/vplay song name</code> for video.\n\n" .
            "Example: <code>/play shape of you</code>\n\n" .
            "That's it — enjoy the music! 🎶";
    }

    public static function privateWelcomeCaption(string $userName): string
    {
        $botMention = TelegramApi::botMention();
        return "👋 Hello, " . htmlspecialchars($userName) . ".\n\n" .
            "{$botMention} is a music bot for Telegram — stream from YouTube and " .
            "Spotify, right inside any group voice chat.\n\n" .
            "Use /help to explore all commands.";
    }

    private function readableUptime(int $seconds): string
    {
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        $parts = [];
        if ($d > 0) $parts[] = "{$d}d";
        if ($h > 0) $parts[] = "{$h}h";
        if ($m > 0) $parts[] = "{$m}m";
        $parts[] = "{$s}s";

        return implode(' ', $parts);
    }
}
