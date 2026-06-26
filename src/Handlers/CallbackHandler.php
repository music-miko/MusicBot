<?php
/**
 * src/Handlers/CallbackHandler.php
 *
 * Handles inline keyboard button presses (callback_query).
 * Buttons from TelegramApi::playerKeyboard().
 *
 * Data format: "<action>_<chat_id>"
 * Actions: pause, resume, skip, stop, shuffle, loop, queue
 */

declare(strict_types=1);

namespace TeleMusic\Handlers;

use TeleMusic\Commands\ControlCommands;
use TeleMusic\Commands\QueueCommand;
use TeleMusic\Core\TelegramApi;
use TeleMusic\Core\Logger;

class CallbackHandler
{
    private TelegramApi     $tg;
    private Logger          $log;
    private ControlCommands $control;
    private QueueCommand    $queue;

    public function __construct()
    {
        $this->tg      = TelegramApi::getInstance();
        $this->log     = Logger::getInstance();
        $this->control = new ControlCommands();
        $this->queue   = new QueueCommand();
    }

    public function handle(array $callback): void
    {
        $callbackId = $callback['id'];
        $data       = $callback['data'] ?? '';
        $message    = $callback['message'] ?? [];
        $userId     = $callback['from']['id'] ?? 0;

        $this->log->debug("[CB] user=$userId data=$data");

        if (!$data || !$message) {
            $this->tg->answerCallbackQuery($callbackId, '❌ Invalid action');
            return;
        }

        // Parse "action_chatId"
        $lastUnderscore = strrpos($data, '_');
        if ($lastUnderscore === false) {
            $this->tg->answerCallbackQuery($callbackId, '❌ Unknown action');
            return;
        }

        $action = substr($data, 0, $lastUnderscore);
        $chatId = (int) substr($data, $lastUnderscore + 1);

        if (!$chatId) {
            $this->tg->answerCallbackQuery($callbackId);
            return;
        }

        // Synthesize a minimal message array for command handlers
        $fakeMsg = array_merge($message, [
            'chat' => ['id' => $chatId],
            'from' => $callback['from'],
        ]);

        match ($action) {
            'pause'   => $this->handlePause($fakeMsg, $callbackId),
            'resume'  => $this->handleResume($fakeMsg, $callbackId),
            'skip'    => $this->handleSkip($fakeMsg, $callbackId),
            'stop'    => $this->handleStop($fakeMsg, $callbackId),
            'shuffle' => $this->handleShuffle($fakeMsg, $callbackId),
            'loop'    => $this->handleLoop($fakeMsg, $callbackId),
            'queue'   => $this->handleQueue($fakeMsg, $callbackId),
            default   => $this->tg->answerCallbackQuery($callbackId, '❓ Unknown action'),
        };
    }

    private function handlePause(array $msg, string $cbId): void
    {
        $this->control->pause($msg);
        $this->tg->answerCallbackQuery($cbId, '⏸ Paused');
    }

    private function handleResume(array $msg, string $cbId): void
    {
        $this->control->resume($msg);
        $this->tg->answerCallbackQuery($cbId, '▶ Resumed');
    }

    private function handleSkip(array $msg, string $cbId): void
    {
        $this->control->skip($msg);
        $this->tg->answerCallbackQuery($cbId, '⏭ Skipped');
    }

    private function handleStop(array $msg, string $cbId): void
    {
        $this->control->stop($msg);
        $this->tg->answerCallbackQuery($cbId, '⏹ Stopped');
    }

    private function handleShuffle(array $msg, string $cbId): void
    {
        $this->control->shuffle($msg);
        $this->tg->answerCallbackQuery($cbId, '🔀 Shuffled');
    }

    private function handleLoop(array $msg, string $cbId): void
    {
        $this->control->loop($msg);
        $this->tg->answerCallbackQuery($cbId, '🔁 Loop toggled');
    }

    private function handleQueue(array $msg, string $cbId): void
    {
        $this->queue->show($msg);
        $this->tg->answerCallbackQuery($cbId);
    }
}
