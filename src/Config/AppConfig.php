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
        public readonly string $codexUserAgent,
        public readonly string $codexBetaFeatures,
        public readonly string $codexOriginator,
        public readonly string $codexResidency,
        public readonly string $codexUpstreamBaseUrl,
        public readonly string $usageBaseUrl,
        public readonly int $usageRefreshIntervalSeconds,
        public readonly bool $traceMutations,
        public readonly bool $traceTimings,
        public readonly ?string $httpProxy,
        public readonly ?string $httpsProxy,
        public readonly string $noProxy,
        public readonly int $activeSessionWindowSeconds = 21600,
    ) {
    }
}
