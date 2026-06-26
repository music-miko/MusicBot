<?php
/**
 * src/Core/Logger.php
 * Simple PSR-3-style logger backed by Monolog.
 */

declare(strict_types=1);

namespace TeleMusic\Core;

use Monolog\Handler\StreamHandler;
use Monolog\Logger as Monolog;
use Monolog\Level;

class Logger
{
    private static ?self $instance = null;
    private Monolog $log;

    private function __construct()
    {
        $this->log = new Monolog('TeleMusic');
        // Console output
        $this->log->pushHandler(new StreamHandler('php://stdout', Level::Debug));
        // File output
        if (!is_dir(LOGS_DIR)) {
            @mkdir(LOGS_DIR, 0755, true);
        }
        $this->log->pushHandler(new StreamHandler(LOGS_DIR . '/bot.log', Level::Info));
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function info(string $msg, array $ctx = []): void    { $this->log->info($msg, $ctx); }
    public function warning(string $msg, array $ctx = []): void { $this->log->warning($msg, $ctx); }
    public function error(string $msg, array $ctx = []): void   { $this->log->error($msg, $ctx); }
    public function debug(string $msg, array $ctx = []): void   { $this->log->debug($msg, $ctx); }
}
