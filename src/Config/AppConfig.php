<?php

declare(strict_types=1);

namespace CodexAuthProxy\Config;

final class AppConfig
{
    public function __construct(
        public readonly string $home,
        public readonly string $accountsDir,
        public readonly string $stateFile,
        public readonly string $host,
        public readonly int $port,
        public readonly int $cooldownSeconds,
        public readonly string $callbackHost,
        public readonly int $callbackPort,
        public readonly int $callbackTimeoutSeconds,
        public readonly string $logLevel,
        public readonly string $codexUserAgent,
        public readonly string $codexBetaFeatures,
        public readonly string $traceDir,
        public readonly ?string $httpProxy,
        public readonly ?string $httpsProxy,
        public readonly string $noProxy,
    ) {
    }
}
