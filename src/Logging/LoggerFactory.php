<?php

declare(strict_types=1);

namespace CodexAuthProxy\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

final class LoggerFactory
{
    public static function create(string $level): LoggerInterface
    {
        $logger = new Logger('codex-auth-proxy');
        $logger->pushHandler(new StreamHandler('php://stderr', Level::fromName(strtoupper($level))));

        return $logger;
    }
}
