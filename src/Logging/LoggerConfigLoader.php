<?php

declare(strict_types=1);

namespace CodexAuthProxy\Logging;

use CodexAuthProxy\Support\BasePath;
use RuntimeException;

final class LoggerConfigLoader
{
    public function __construct(
        private readonly ?string $configFile = null,
        private readonly ?string $basePath = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function load(): array
    {
        $configFile = $this->configFile ?? dirname(__DIR__, 2) . '/config/logger.php';
        if (!is_file($configFile)) {
            throw new RuntimeException('Missing logger config file: ' . $configFile);
        }

        $basePath = BasePath::resolve(
            explicitBasePath: $this->basePath,
            definedBasePath: defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : null,
            pharPath: \Phar::running(false),
            sourceBasePath: dirname(__DIR__, 2),
        );
        $config = require $configFile;
        if (!is_array($config)) {
            throw new RuntimeException('Logger config file must return an array: ' . $configFile);
        }

        return $config;
    }
}
