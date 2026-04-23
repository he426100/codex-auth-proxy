<?php

declare(strict_types=1);

use CodexAuthProxy\Support\BasePath;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;

require_once dirname(__DIR__) . '/src/Config/env.php';

$basePath = BasePath::resolve(
    explicitBasePath: is_string($basePath ?? null) ? $basePath : null,
    definedBasePath: defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : null,
    pharPath: \Phar::running(false),
    sourceBasePath: dirname(__DIR__),
);
$logRoot = $basePath . '/runtime/logs';
$logFile = BasePath::toAbsolute($basePath, env('CODEX_AUTH_PROXY_LOG_FILE')) ?? $logRoot . '/app.log';
$traceFile = BasePath::toAbsolute($basePath, env('CODEX_AUTH_PROXY_TRACE_FILE'));

if ($traceFile === null) {
    $legacyTraceDir = BasePath::toAbsolute($basePath, env('CODEX_AUTH_PROXY_TRACE_DIR'));
    $traceFile = $legacyTraceDir !== null
        ? $legacyTraceDir . '/trace.jsonl'
        : $logRoot . '/trace.jsonl';
}

return [
    'default' => 'default',
    'channels' => [
        'default' => [
            'handler' => [
                'class' => StreamHandler::class,
                'constructor' => [
                    'stream' => $logFile,
                    'level' => (string) env('CODEX_AUTH_PROXY_LOG_LEVEL', 'warning'),
                ],
            ],
            'formatter' => [
                'class' => LineFormatter::class,
                'constructor' => [
                    'format' => null,
                    'dateFormat' => 'Y-m-d H:i:s',
                    'allowInlineLineBreaks' => true,
                ],
            ],
        ],
        'trace' => [
            'handler' => [
                'class' => StreamHandler::class,
                'constructor' => [
                    'stream' => $traceFile,
                    'level' => (string) env('CODEX_AUTH_PROXY_TRACE_LEVEL', 'info'),
                ],
            ],
            'formatter' => [
                'class' => JsonFormatter::class,
                'constructor' => [],
            ],
        ],
    ],
];
