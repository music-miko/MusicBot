<?php
/**
 * src/Handlers/MessageHandler.php
 *
 * Routes incoming Telegram messages to command handlers.
 * All commands are checked for admin privileges where required.
 */

declare(strict_types=1);

namespace TeleMusic\Handlers;

use TeleMusic\Commands\PlayCommand;
use TeleMusic\Commands\ControlCommands;
use TeleMusic\Commands\QueueCommand;
use TeleMusic\Commands\HelpCommand;
use TeleMusic\Commands\StartCommand;
use TeleMusic\Core\TelegramApi;
use TeleMusic\Core\Logger;

class MessageHandler
{
    private TelegramApi $tg;
    private Logger      $log;

    private PlayCommand     $play;
    private ControlCommands $control;
    private QueueCommand    $queue;
    private HelpCommand     $help;
    private StartCommand    $start;

    public function __construct()
    {
        $this->tg      = TelegramApi::getInstance();
        $this->log     = Logger::getInstance();
        $this->play    = new PlayCommand();
        $this->control = new ControlCommands();
        $this->queue   = new QueueCommand();
        $this->help    = new HelpCommand();
        $this->start   = new StartCommand();
    }

    public function handle(array $message): void
    {
        $chatId   = $message['chat']['id']   ?? 0;
        $userId   = $message['from']['id']   ?? 0;
        $text     = $message['text']         ?? '';
        $chatType = $message['chat']['type'] ?? 'private';

        // Bot added to a new group → send welcome panel.
        if (!empty($message['new_chat_members'])) {
            $this->handleNewChatMembers($message);
            return;
        }

        // Only respond in groups/supergroups (video chat only exists there)
        // Allow private messages for /help and /ping
        if (!$text) return;

        [$cmd, $args] = $this->parseCommand($text);
        if (!$cmd) return;

        $this->log->debug("[MSG] Chat=$chatId User=$userId Cmd=$cmd Args=\"$args\"");

        match ($cmd) {
            '/play',    '/p'       => $this->play->execute($message, $args, false),
            '/vplay',   '/vp'      => $this->play->execute($message, $args, true),
            '/pause'               => $this->control->pause($message),
            '/resume'              => $this->control->resume($message),
            '/skip',    '/s'       => $this->control->skip($message),
            '/stop'                => $this->control->stop($message),
            '/shuffle'             => $this->control->shuffle($message),
            '/loop'                => $this->control->loop($message),
            '/seek'                => $this->control->seek($message, $args),
            '/volume',  '/vol'     => $this->control->volume($message, $args),
            '/queue',   '/q'       => $this->queue->show($message),
            '/start'               => $this->start->show($message),
            '/help'                => $this->help->show($message),
            '/ping'                => $this->ping($message),
            default                => null,
        };
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function handleNewChatMembers(array $message): void
    {
        $botUsername = TelegramApi::botUsername();
        foreach ($message['new_chat_members'] as $member) {
            if (($member['username'] ?? null) !== $botUsername) {
                continue; // only react when the bot itself was added
            }
            $chatId      = $message['chat']['id'] ?? 0;
            $chatTitle   = $message['chat']['title'] ?? 'this group';
            $addedByName = $message['from']['first_name'] ?? 'there';
            $this->start->welcome($chatId, $addedByName, $chatTitle);
            break;
        }
    }

    private function parseCommand(string $text): array
    {
        // Remove @BotName suffix if present
        $text = preg_replace('/@\w+/', '', $text);
        $text = trim($text);

        if (!str_starts_with($text, '/')) {
            return ['', ''];
        }

        $parts = explode(' ', $text, 2);
        $cmd   = strtolower($parts[0]);
        $args  = trim($parts[1] ?? '');
        return [$cmd, $args];
    }

    private function ping(array $message): void
    {
        $start = microtime(true);
        $sent  = $this->tg->sendMessage($message['chat']['id'], '🏓 Pong!');
        if ($sent) {
            $ms = round((microtime(true) - $start) * 1000);
            $this->tg->editMessageText(
                $message['chat']['id'],
                $sent['message_id'],
                "🏓 <b>Pong!</b> <code>{$ms}ms</code>"
            );
        }
    }
}
