<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Observability;

use CodexAuthProxy\Observability\RequestTraceLogger;
use CodexAuthProxy\Tests\TestCase;

final class RequestTraceLoggerTest extends TestCase
{
    public function testWritesRedactedErrorTrace(): void
    {
        $dir = $this->tempDir('trace');
        $logger = new RequestTraceLogger($dir);

        $logger->error([
            'request_id' => 'abc123ef',
            'transport' => 'http',
            'phase' => 'upstream_response',
            'session' => 'session-a',
            'account' => 'account-a',
            'status' => 401,
            'message' => 'Authorization: Bearer secret-token refresh_token=rt_secret',
        ]);

        $files = glob($dir . '/*.json') ?: [];
        self::assertCount(1, $files);
        self::assertStringContainsString('abc123ef', basename($files[0]));

        $payload = json_decode((string) file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('codex-auth-proxy.trace.v1', $payload['schema']);
        self::assertSame('abc123ef', $payload['request_id']);
        self::assertSame('http', $payload['transport']);
        self::assertSame('upstream_response', $payload['phase']);
        self::assertSame('session-a', $payload['session']);
        self::assertSame('account-a', $payload['account']);
        self::assertSame(401, $payload['status']);
        self::assertStringNotContainsString('secret-token', $payload['message']);
        self::assertStringNotContainsString('rt_secret', $payload['message']);
        self::assertStringContainsString('Bearer [redacted]', $payload['message']);
        self::assertStringContainsString('refresh_token=[redacted]', $payload['message']);
    }

    public function testDoesNotOverwriteMultipleErrorsForSameRequest(): void
    {
        $dir = $this->tempDir('trace-multiple');
        $logger = new RequestTraceLogger($dir);

        $logger->error(['request_id' => 'same-id', 'message' => 'first']);
        $logger->error(['request_id' => 'same-id', 'message' => 'second']);

        $files = glob($dir . '/*.json') ?: [];
        self::assertCount(2, $files);
    }

    public function testRedactsPromptContentFromJsonErrorBody(): void
    {
        $dir = $this->tempDir('trace-content');
        $logger = new RequestTraceLogger($dir);

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

        $files = glob($dir . '/*.json') ?: [];
        self::assertCount(1, $files);

        $payload = json_decode((string) file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('{"error":{"message":"invalid request"},"input":"[redacted]","output":"[redacted]"}', $payload['message']);
        self::assertStringNotContainsString('secret prompt', $payload['message']);
        self::assertStringNotContainsString('source code secret', $payload['message']);
    }
}
