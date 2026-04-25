<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Observability;

use CodexAuthProxy\Logging\LoggerFactory;
use CodexAuthProxy\Observability\RequestTraceLogger;
use CodexAuthProxy\Tests\TestCase;

final class RequestTraceLoggerTest extends TestCase
{
    public function testWritesRedactedErrorTrace(): void
    {
        [$path, $logger] = $this->traceLogger('trace');

        $logger->error([
            'request_id' => 'abc123ef',
            'transport' => 'http',
            'phase' => 'upstream_response',
            'session' => 'session-a',
            'account' => 'account-a',
            'status' => 401,
            'message' => 'Authorization: Bearer secret-token refresh_token=rt_secret',
        ]);

        $records = $this->traceRecords($path);
        self::assertCount(1, $records);
        self::assertSame('request_trace_error', $records[0]['message']);
        self::assertSame('WARNING', $records[0]['level_name']);
        self::assertSame('codex-auth-proxy.trace', $records[0]['channel']);
        self::assertSame('codex-auth-proxy.trace.v1', $records[0]['context']['schema']);
        self::assertSame('abc123ef', $records[0]['context']['request_id']);
        self::assertSame('http', $records[0]['context']['transport']);
        self::assertSame('upstream_response', $records[0]['context']['phase']);
        self::assertSame('session-a', $records[0]['context']['session']);
        self::assertSame('account-a', $records[0]['context']['account']);
        self::assertSame(401, $records[0]['context']['status']);
        self::assertStringNotContainsString('secret-token', $records[0]['context']['message']);
        self::assertStringNotContainsString('rt_secret', $records[0]['context']['message']);
        self::assertStringContainsString('Bearer [redacted]', $records[0]['context']['message']);
        self::assertStringContainsString('refresh_token=[redacted]', $records[0]['context']['message']);
    }

    public function testAppendsMultipleErrorsIntoSingleTraceFile(): void
    {
        [$path, $logger] = $this->traceLogger('trace-multiple');

        $logger->error(['request_id' => 'same-id', 'message' => 'first']);
        $logger->error(['request_id' => 'same-id', 'message' => 'second']);

        self::assertSame([$path], glob(dirname($path) . '/*') ?: []);
        self::assertCount(2, $this->traceRecords($path));
    }

    public function testRedactsPromptContentFromJsonErrorBody(): void
    {
        [$path, $logger] = $this->traceLogger('trace-content');

        $logger->error([
            'request_id' => 'content-redaction',
            'message' => json_encode([
                'error' => ['message' => 'invalid request'],
                'input' => 'secret prompt',
                'output' => [
                    [
                        'content' => [
                            ['type' => 'input_text', 'text' => 'source code secret'],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $records = $this->traceRecords($path);
        self::assertCount(1, $records);
        self::assertSame('{"error":{"message":"invalid request"},"input":"[redacted]","output":"[redacted]"}', $records[0]['context']['message']);
        self::assertStringNotContainsString('secret prompt', $records[0]['context']['message']);
        self::assertStringNotContainsString('source code secret', $records[0]['context']['message']);
    }

    public function testWritesMutationTraceWithoutPromptContent(): void
    {
        [$path, $logger] = $this->traceLogger('trace-mutations');

        $logger->event([
            'request_id' => 'mutation-1',
            'transport' => 'http',
            'phase' => 'request_normalized',
            'session' => 'session-a',
            'mutations' => [
                'http.input.string_to_message',
                'parameters.empty_array_to_object',
            ],
            'message' => '{"input":"secret prompt"}',
        ]);

        $records = $this->traceRecords($path);
        self::assertCount(1, $records);
        self::assertSame('request_trace', $records[0]['message']);
        self::assertSame('INFO', $records[0]['level_name']);
        self::assertSame('request_normalized', $records[0]['context']['phase']);
        self::assertSame([
            'http.input.string_to_message',
            'parameters.empty_array_to_object',
        ], $records[0]['context']['mutations']);
        self::assertStringNotContainsString('secret prompt', $records[0]['context']['message']);
    }

    public function testWritesTimingMetricsForRequestEvents(): void
    {
        [$path, $logger] = $this->traceLogger('trace-timings');

        $logger->event([
            'request_id' => 'timing-1',
            'transport' => 'http',
            'phase' => 'request_completed',
            'status' => 200,
            'classification' => 'none',
            'attempts' => 2,
            'timings_ms' => [
                'scheduler_reload' => 1.23456,
                'account_prepare' => 2.34567,
                'upstream' => 123.45678,
                'first_byte' => 45.67891,
                'total' => 130.00049,
                'ignore_string' => 'x',
            ],
        ]);

        $records = $this->traceRecords($path);
        self::assertCount(1, $records);
        self::assertSame(2, $records[0]['context']['attempts']);
        self::assertSame([
            'scheduler_reload' => 1.235,
            'account_prepare' => 2.346,
            'upstream' => 123.457,
            'first_byte' => 45.679,
            'total' => 130.0,
        ], $records[0]['context']['timings_ms']);
    }

    public function testWritesTransportRecoveryFields(): void
    {
        [$path, $logger] = $this->traceLogger('trace-recovery');

        $logger->event([
            'request_id' => 'retry-1',
            'transport' => 'websocket',
            'phase' => 'websocket_retry',
            'session' => 'session-a',
            'account' => 'account-a',
            'classification' => 'transport',
            'recovery' => 'retry',
            'retry_reason' => 'closed_before_first_payload',
            'retry_account' => 'account-b',
            'message' => 'stream disconnected before response.completed',
        ]);

        $records = $this->traceRecords($path);
        self::assertCount(1, $records);
        self::assertSame('websocket_retry', $records[0]['context']['phase']);
        self::assertSame('retry', $records[0]['context']['recovery']);
        self::assertSame('closed_before_first_payload', $records[0]['context']['retry_reason']);
        self::assertSame('account-b', $records[0]['context']['retry_account']);
    }

    /** @return array{0:string,1:RequestTraceLogger} */
    private function traceLogger(string $name): array
    {
        $path = $this->tempDir($name) . '/trace.jsonl';

        return [$path, new RequestTraceLogger(LoggerFactory::createTrace($path))];
    }

    /** @return list<array<string,mixed>> */
    private function traceRecords(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            $lines,
        );
    }
}
