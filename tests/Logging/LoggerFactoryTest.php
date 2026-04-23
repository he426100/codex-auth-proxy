<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Logging;

use CodexAuthProxy\Logging\LoggerFactory;
use CodexAuthProxy\Tests\TestCase;

final class LoggerFactoryTest extends TestCase
{
    public function testCreateWritesConfiguredDefaultChannelToConfiguredFile(): void
    {
        $path = $this->tempDir('logger-default') . '/app.log';
        $logger = LoggerFactory::create([
            'default' => 'default',
            'channels' => [
                'default' => [
                    'handler' => [
                        'class' => \Monolog\Handler\StreamHandler::class,
                        'constructor' => [
                            'stream' => $path,
                            'level' => 'warning',
                        ],
                    ],
                    'formatter' => [
                        'class' => \Monolog\Formatter\LineFormatter::class,
                        'constructor' => [
                            'format' => null,
                            'dateFormat' => 'Y-m-d H:i:s',
                            'allowInlineLineBreaks' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $logger->warning('runtime log message');

        self::assertFileExists($path);
        self::assertStringContainsString('runtime log message', (string) file_get_contents($path));
    }

    public function testCreateWritesConfiguredTraceChannelAsJson(): void
    {
        $path = $this->tempDir('logger-trace') . '/trace.jsonl';
        $logger = LoggerFactory::create([
            'default' => 'default',
            'channels' => [
                'trace' => [
                    'handler' => [
                        'class' => \Monolog\Handler\StreamHandler::class,
                        'constructor' => [
                            'stream' => $path,
                            'level' => 'info',
                        ],
                    ],
                    'formatter' => [
                        'class' => \Monolog\Formatter\JsonFormatter::class,
                        'constructor' => [],
                    ],
                ],
            ],
        ], 'trace');

        $logger->info('trace event', ['request_id' => 'req-1']);

        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('trace event', $payload['message']);
        self::assertSame('req-1', $payload['context']['request_id']);
    }
}
