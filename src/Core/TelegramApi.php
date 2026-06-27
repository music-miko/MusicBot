<?php
/**
 * src/Core/TelegramApi.php
 *
 * Thin wrapper around the Telegram Bot API.
 * All methods return the decoded JSON result array, or throw on hard failure.
 */

declare(strict_types=1);

namespace TeleMusic\Core;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class TelegramApi
{
    private static ?self $instance = null;
    private Client $http;
    private Logger $log;

    private static ?array $botInfo = null;

    private function __construct()
    {
        $this->http = new Client([
            'base_uri' => 'https://api.telegram.org/bot' . BOT_TOKEN . '/',
            'timeout'  => 30,
        ]);
        $this->log = Logger::getInstance();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── Message sending ──────────────────────────────────────────────────────

    public function sendMessage(int|string $chatId, string $text, array $extra = []): ?array
    {
        return $this->call('sendMessage', array_merge([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra));
    }

    public function editMessageText(int|string $chatId, int $msgId, string $text, array $extra = []): ?array
    {
        return $this->call('editMessageText', array_merge([
            'chat_id'    => $chatId,
            'message_id' => $msgId,
            'text'       => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra));
    }

    public function sendPhoto(int|string $chatId, string $photo, string $caption = '', array $extra = []): ?array
    {
        return $this->call('sendPhoto', array_merge([
            'chat_id'    => $chatId,
            'photo'      => $photo,
            'caption'    => $caption,
            'parse_mode' => 'HTML',
        ], $extra));
    }

    public function deleteMessage(int|string $chatId, int $msgId): ?array
    {
        return $this->call('deleteMessage', [
            'chat_id'    => $chatId,
            'message_id' => $msgId,
        ]);
    }

    public function answerCallbackQuery(string $callbackId, string $text = '', bool $showAlert = false): ?array
    {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text'              => $text,
            'show_alert'        => $showAlert,
        ]);
    }

    public function getChatMember(int|string $chatId, int $userId): ?array
    {
        return $this->call('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    // ── Bot identity ─────────────────────────────────────────────────────────

    /**
     * Fetch and cache this bot's own identity (id, username, first_name).
     * Call once at startup; safe to call repeatedly (cached after first success).
     */
    public function fetchBotInfo(): ?array
    {
        if (self::$botInfo !== null) {
            return self::$botInfo;
        }
        $me = $this->call('getMe');
        if ($me) {
            self::$botInfo = $me;
        }
        return self::$botInfo;
    }

    public static function botUsername(): string
    {
        return self::$botInfo['username'] ?? '';
    }

    public static function botMention(): string
    {
        $name = self::$botInfo['first_name'] ?? 'Bot';
        $username = self::$botInfo['username'] ?? null;
        return $username ? "@$username" : $name;
    }

    // ── Raw API call ─────────────────────────────────────────────────────────

    public function call(string $method, array $params = []): ?array
    {
        try {
            $res  = $this->http->post($method, ['json' => $params]);
            $data = json_decode((string) $res->getBody(), true);
            if (!($data['ok'] ?? false)) {
                $this->log->warning("Telegram API [$method] error: " . ($data['description'] ?? 'unknown'));
                return null;
            }
            return $data['result'] ?? [];
        } catch (GuzzleException $e) {
            $this->log->error("Telegram API [$method] HTTP error: " . $e->getMessage());
            return null;
        }
    }

    // ── Keyboard builders ────────────────────────────────────────────────────

    public static function inlineKeyboard(array $rows): array
    {
        return ['inline_keyboard' => $rows];
    }

    /**
     * Build the "Now Playing" control keyboard.
     * Buttons: ⏸ Pause | ⏭ Skip | ⏹ Stop
     */
    public static function playerKeyboard(int $chatId): array
    {
        return self::inlineKeyboard([[
            ['text' => '⏸ Pause',  'callback_data' => "pause_{$chatId}"],
            ['text' => '⏭ Skip',   'callback_data' => "skip_{$chatId}"],
            ['text' => '⏹ Stop',   'callback_data' => "stop_{$chatId}"],
        ], [
            ['text' => '🔀 Shuffle', 'callback_data' => "shuffle_{$chatId}"],
            ['text' => '🔁 Loop',    'callback_data' => "loop_{$chatId}"],
            ['text' => '📋 Queue',   'callback_data' => "queue_{$chatId}"],
        ]]);
    }

    /** Paused state keyboard: ▶ Resume | ⏭ Skip | ⏹ Stop */
    public static function pausedKeyboard(int $chatId): array
    {
        return self::inlineKeyboard([[
            ['text' => '▶ Resume', 'callback_data' => "resume_{$chatId}"],
            ['text' => '⏭ Skip',  'callback_data' => "skip_{$chatId}"],
            ['text' => '⏹ Stop',  'callback_data' => "stop_{$chatId}"],
        ]]);
    }

    // ── /start panels (mirrors tosu4's AnonXMusic/utils/inline/start.py) ──────

    /** Shown for /start in a group chat. */
    public static function startGroupPanel(): array
    {
        $username = self::botUsername();
        return self::inlineKeyboard([[
            ['text' => '➕ Add Me', 'url' => "https://t.me/{$username}?startgroup=true"],
            ['text' => '🆘 Support', 'url' => SUPPORT_CHAT],
        ]]);
    }

    /** Shown for /start in a private chat. */
    public static function privatePanel(): array
    {
        $username = self::botUsername();
        return self::inlineKeyboard([
            [['text' => '➕ Add to Group', 'url' => "https://t.me/{$username}?startgroup=true"]],
            [['text' => '📜 Help & Commands', 'callback_data' => 'help_back_helper']],
            [
                ['text' => '🆘 Support Chat', 'url' => SUPPORT_CHAT],
                ['text' => '📢 Updates', 'url' => SUPPORT_CHANNEL],
            ],
            [['text' => '🅰️ Setup Guide', 'callback_data' => 'setup_guide_helper']],
        ]);
    }

    /** Shown when the bot is added to a group. */
    public static function welcomePanel(): array
    {
        return self::inlineKeyboard([[
            ['text' => '🅰️ Setup Guide', 'callback_data' => 'setup_guide_helper'],
        ]]);
    }

    /** Back/Close row shown on the setup-guide screen. */
    public static function guideBackMarkup(): array
    {
        return self::inlineKeyboard([[
            ['text' => '◀️ Back', 'callback_data' => 'start_back_helper'],
            ['text' => '✖️ Close', 'callback_data' => 'close'],
        ]]);
    }

    /** Help panel — category overview with a Back button to /start. */
    public static function helpPanel(): array
    {
        return self::inlineKeyboard([
            [
                ['text' => '▶️ Playback', 'callback_data' => 'help_playback'],
                ['text' => '⏯ Controls', 'callback_data' => 'help_controls'],
            ],
            [
                ['text' => '📋 Queue', 'callback_data' => 'help_queue'],
                ['text' => '⚙️ Settings', 'callback_data' => 'help_settings'],
            ],
            [['text' => '◀️ Back', 'callback_data' => 'start_back_helper']],
        ]);
    }
}
