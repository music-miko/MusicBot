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
use TeleMusic\Commands\HelpCommand;
use TeleMusic\Commands\StartCommand;
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

        // ── /start & /help panel navigation (no chatId suffix) ─────────────────
        if ($this->handleNavigation($data, $callback, $callbackId)) {
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

    /**
     * Handles the fixed-name callbacks used by the /start and /help panels
     * (mirrors tosu4's setup_guide_cb / start_back_cb handlers).
     * Returns true if it handled the callback (caller should stop processing).
     */
    private function handleNavigation(string $data, array $callback, string $callbackId): bool
    {
        $message = $callback['message'] ?? [];
        $chatId  = $message['chat']['id'] ?? 0;
        $msgId   = $message['message_id'] ?? null;
        $hasPhoto = !empty($message['photo']);

        switch (true) {
            case $data === 'close':
                $this->tg->answerCallbackQuery($callbackId);
                if ($msgId) {
                    $this->tg->deleteMessage($chatId, $msgId);
                }
                return true;

            case $data === 'setup_guide_helper':
                $this->tg->answerCallbackQuery($callbackId);
                $text = StartCommand::setupGuideText();
                $markup = TelegramApi::guideBackMarkup();
                if ($hasPhoto) {
                    $this->tg->call('editMessageCaption', [
                        'chat_id' => $chatId, 'message_id' => $msgId,
                        'caption' => $text, 'parse_mode' => 'HTML',
                        'reply_markup' => $markup,
                    ]);
                } else {
                    $this->tg->editMessageText($chatId, $msgId, $text, ['reply_markup' => $markup]);
                }
                return true;

            case $data === 'start_back_helper':
                $this->tg->answerCallbackQuery($callbackId);
                $userName = $callback['from']['first_name'] ?? 'there';
                $caption  = StartCommand::privateWelcomeCaption($userName);
                $markup   = TelegramApi::privatePanel();
                if ($hasPhoto) {
                    $this->tg->call('editMessageCaption', [
                        'chat_id' => $chatId, 'message_id' => $msgId,
                        'caption' => $caption, 'parse_mode' => 'HTML',
                        'reply_markup' => $markup,
                    ]);
                } else {
                    $this->tg->editMessageText($chatId, $msgId, $caption, ['reply_markup' => $markup]);
                }
                return true;

            case $data === 'help_back_helper':
                $this->tg->answerCallbackQuery($callbackId);
                $text = "Choose a category to explore available commands.\n\n" .
                    "For support, visit <a href=\"" . SUPPORT_CHAT . "\">our group</a>.\n\n" .
                    "All commands use the <code>/</code> prefix.";
                $markup = TelegramApi::helpPanel();
                if ($hasPhoto) {
                    $this->tg->call('editMessageCaption', [
                        'chat_id' => $chatId, 'message_id' => $msgId,
                        'caption' => $text, 'parse_mode' => 'HTML',
                        'reply_markup' => $markup,
                    ]);
                } else {
                    $this->tg->editMessageText($chatId, $msgId, $text, ['reply_markup' => $markup]);
                }
                return true;

            case isset(HelpCommand::CATEGORIES[$data]):
                $this->tg->answerCallbackQuery($callbackId);
                $cat = HelpCommand::CATEGORIES[$data];
                $text = "<b>{$cat['title']}</b>\n\n{$cat['body']}";
                $markup = TelegramApi::inlineKeyboard([[
                    ['text' => '◀️ Back', 'callback_data' => 'help_back_helper'],
                ]]);
                $this->tg->editMessageText($chatId, $msgId, $text, ['reply_markup' => $markup]);
                return true;

            default:
                return false;
        }
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
