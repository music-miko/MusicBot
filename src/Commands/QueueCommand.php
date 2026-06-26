<?php
/**
 * src/Commands/QueueCommand.php
 */

declare(strict_types=1);

namespace TeleMusic\Commands;

use TeleMusic\Core\QueueManager;
use TeleMusic\Core\TelegramApi;

class QueueCommand
{
    private TelegramApi  $tg;
    private QueueManager $queue;

    public function __construct()
    {
        $this->tg    = TelegramApi::getInstance();
        $this->queue = QueueManager::getInstance();
    }

    public function show(array $message): void
    {
        $chatId = $message['chat']['id'];
        $tracks = $this->queue->getQueue($chatId);

        if (empty($tracks)) {
            $this->tg->sendMessage($chatId, '📋 <b>Queue is empty.</b>');
            return;
        }

        $loop   = $this->queue->getLoopMode($chatId);
        $loopTxt = match ($loop) {
            'track' => '🔂 Track',
            'queue' => '🔁 Queue',
            default => '❌ Off',
        };

        $lines  = ["📋 <b>Queue</b> — {$loopTxt} loop\n"];
        $max    = min(count($tracks), 15);  // show max 15

        foreach (array_slice($tracks, 0, $max) as $i => $t) {
            $num   = $i + 1;
            $icon  = $i === 0 ? '🎵' : "  $num.";
            $title = htmlspecialchars($t['title']);
            $dur   = $t['duration_min'];
            $lines[] = "$icon <b>$title</b> <code>[$dur]</code>";
        }

        if (count($tracks) > $max) {
            $remaining = count($tracks) - $max;
            $lines[] = "\n<i>...and $remaining more</i>";
        }

        $lines[] = "\n<i>Total: " . count($tracks) . " track(s)</i>";

        $this->tg->sendMessage($chatId, implode("\n", $lines));
    }
}
