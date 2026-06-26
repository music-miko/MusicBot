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
}
