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
        TelegramApi::getInstance()->fetchBotInfo();
        $this->setupCommands();
        $this->discardBacklog();

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

    /**
     * Skip any updates (commands, callbacks, etc.) that piled up on
     * Telegram's side while the bot was offline. Without this, a restart
     * causes every queued /play, /skip, button tap, etc. from the downtime
     * to fire all at once.
     *
     * Mechanism: requesting offset=-1 returns only the single most recent
     * pending update (if any), without consuming it. We read its update_id
     * and ack everything up to and including it via a zero-timeout call —
     * this discards the backlog without processing any of it.
     */
    private function discardBacklog(): void
    {
        try {
            $res  = $this->http->get('getUpdates', [
                'query' => ['offset' => -1, 'timeout' => 0, 'limit' => 1],
            ]);
            $data    = json_decode((string) $res->getBody(), true);
            $pending = $data['result'] ?? [];

            if (empty($pending)) {
                return; // nothing queued
            }

            $latestId    = $pending[0]['update_id'];
            $this->offset = $latestId + 1;

            // Ack the offset so Telegram drops everything up to $latestId.
            $this->http->get('getUpdates', [
                'query' => ['offset' => $this->offset, 'timeout' => 0, 'limit' => 1],
            ]);

            $this->log->info("Discarded backlogged updates up to update_id=$latestId");
        } catch (\Throwable $e) {
            $this->log->warning("discardBacklog failed (continuing anyway): " . $e->getMessage());
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
                        ['command' => 'start',   'description' => 'Start the bot / show welcome panel'],
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
