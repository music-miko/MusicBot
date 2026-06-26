<?php
/**
 * TeleMusic - Telegram Video Chat Music Streaming Bot
 * Version: 1.0.0
 *
 * PHP Telegram Bot with:
 *  - YouTube audio/video streaming via API-1 (arcmusic/deadline-tech)
 *  - Spotify metadata via API-2 (onegrab) + AES-CTR decrypt, fallback to YouTube search
 *  - Video chat integration via phptgcalls + LiveProto
 *  - Queue management, play/pause/skip/stop controls
 *
 * Requires:
 *  composer install
 *  php bot.php
 */

declare(strict_types=1);

define('ROOT_DIR', __DIR__);
define('VERSION', '1.0.0');

require_once ROOT_DIR . '/vendor/autoload.php';
require_once ROOT_DIR . '/config/config.php';

use TeleMusic\Core\Bot;
use TeleMusic\Core\Logger;

$logger = Logger::getInstance();
$logger->info("TeleMusic Bot v" . VERSION . " starting...");

try {
    $bot = new Bot();
    $bot->run();
} catch (\Throwable $e) {
    $logger->error("Fatal: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    exit(1);
}
