<?php
/**
 * src/Core/Bot.php
 *
 * Main bot loop. Uses long-polling against the Telegram Bot API.
 * Command dispatch → Handlers → Platform resolvers → phptgcalls streaming.
 */

declare(strict_types=1);

namespace TeleMusic\Core;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use TeleMusic\Handlers\MessageHandler;
use TeleMusic\Handlers\CallbackHandler;

class Bot
{
    private Client $http;
    private MessageHandler $messageHandler;
    private CallbackHandler $callbackHandler;
    private Logger $log;
    private int $offset = 0;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => 'https://api.telegram.org/bot' . BOT_TOKEN . '/',
            'timeout'  => POLL_TIMEOUT + 5,
        ]);
        $this->log             = Logger::getInstance();
        $this->messageHandler  = new MessageHandler();
        $this->callbackHandler = new CallbackHandler();
    }

    public function run(): void
    {
        $this->log->info("Bot polling started");
        $this->setupCommands();

        while (true) {
            try {
                $updates = $this->getUpdates();
                foreach ($updates as $update) {
                    $this->process($update);
                    $this->offset = $update['update_id'] + 1;
                }
            } catch (GuzzleException $e) {
                $this->log->error("Polling HTTP error: " . $e->getMessage());
                sleep(3);
            } catch (\Throwable $e) {
                $this->log->error("Polling loop error: " . $e->getMessage());
                sleep(1);
            }
        }
    }

    // ── Telegram API helpers ─────────────────────────────────────────────────

    private function getUpdates(): array
    {
        $res = $this->http->get('getUpdates', [
            'query' => [
                'offset'          => $this->offset,
                'timeout'         => POLL_TIMEOUT,
                'allowed_updates' => json_encode(['message', 'callback_query']),
                'limit'           => 100,
            ],
        ]);
        $data = json_decode((string) $res->getBody(), true);
        return $data['result'] ?? [];
    }

    private function process(array $update): void
    {
        if (isset($update['message'])) {
            $this->messageHandler->handle($update['message']);
        } elseif (isset($update['callback_query'])) {
            $this->callbackHandler->handle($update['callback_query']);
        }
    }

    private function setupCommands(): void
    {
        try {
            $this->http->post('setMyCommands', [
                'json' => [
                    'commands' => [
                        ['command' => 'play',    'description' => 'Play a song (YouTube/Spotify URL or search)'],
                        ['command' => 'vplay',   'description' => 'Play a video in video chat'],
                        ['command' => 'pause',   'description' => 'Pause current track'],
                        ['command' => 'resume',  'description' => 'Resume paused track'],
                        ['command' => 'skip',    'description' => 'Skip to next track'],
                        ['command' => 'stop',    'description' => 'Stop and leave voice chat'],
                        ['command' => 'queue',   'description' => 'Show current queue'],
                        ['command' => 'shuffle', 'description' => 'Shuffle the queue'],
                        ['command' => 'loop',    'description' => 'Toggle loop mode'],
                        ['command' => 'seek',    'description' => 'Seek to position (e.g. /seek 1:30)'],
                        ['command' => 'volume',  'description' => 'Set volume 1-200'],
                        ['command' => 'ping',    'description' => 'Check bot latency'],
                        ['command' => 'help',    'description' => 'Show help'],
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            $this->log->warning("setMyCommands failed: " . $e->getMessage());
        }
    }
}
