<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Proxy;

use CodexAuthProxy\Tests\TestCase;
use GuzzleHttp\Client as GuzzleClient;

final class CodexProxyServerIntegrationTest extends TestCase
{
    public function testProxiesCompactRequestToFakeUpstream(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-integration');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-request.json';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $http = new GuzzleClient(['http_errors' => false, 'timeout' => 5]);
            $response = $http->post("http://127.0.0.1:{$proxyPort}/v1/responses/compact", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Request-Id' => 'integration-compact',
                ],
                'body' => json_encode([
                    'input' => 'hello',
                    'max_output_tokens' => 2048,
                    'truncation' => 'auto',
                    'service_tier' => 'default',
                    'context_management' => [
                        'compaction' => ['type' => 'auto'],
                    ],
                ], JSON_THROW_ON_ERROR),
            ]);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('{"id":"resp_1","object":"response.compaction"}', (string) $response->getBody());

            $capture = $this->waitForJsonFile($captureFile);
            self::assertSame('/responses/compact', $capture['path']);
            self::assertSame('application/json', $capture['accept']);
            self::assertStringStartsWith('Bearer ', $capture['authorization']);
            self::assertStringContainsString('"input":[{"type":"message"', $capture['body']);
            self::assertStringContainsString('"max_output_tokens":2048', $capture['body']);
            self::assertStringContainsString('"truncation":"auto"', $capture['body']);
            self::assertStringContainsString('"service_tier":"default"', $capture['body']);
            self::assertStringContainsString('"context_management":{"compaction":{"type":"auto"}}', $capture['body']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testPreservesHttpQueryStringWhenForwardingUpstream(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-http-query');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-request.json';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $http = new GuzzleClient(['http_errors' => false, 'timeout' => 5]);
            $response = $http->post("http://127.0.0.1:{$proxyPort}/v1/responses/compact?client_version=0.122.0&foo=bar", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Request-Id' => 'integration-http-query',
                ],
                'body' => '{"input":"hello"}',
            ]);

            self::assertSame(200, $response->getStatusCode());

            $capture = $this->waitForJsonFile($captureFile);
            self::assertSame('/responses/compact?client_version=0.122.0&foo=bar', $capture['target']);
            self::assertSame('client_version=0.122.0&foo=bar', $capture['query']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testReturnsStructuredErrorWhenNoAccountIsAvailable(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-no-account');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);

        $captureFile = $home . '/upstream-request.json';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $http = new GuzzleClient(['http_errors' => false, 'timeout' => 5]);
            $response = $http->post("http://127.0.0.1:{$proxyPort}/v1/responses", [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => '{"input":"hello"}',
            ]);

            $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(503, $response->getStatusCode());
            self::assertSame('codex_proxy_unavailable', $body['error']['code']);
            self::assertSame('No Codex accounts configured', $body['error']['message']);
            self::assertFileDoesNotExist($captureFile);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testTracesHttpTransportFailureAsTransientBadGateway(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-http-transport-failure');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $http = new GuzzleClient(['http_errors' => false, 'timeout' => 5]);
            $response = $http->post("http://127.0.0.1:{$proxyPort}/v1/responses/compact", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Request-Id' => 'integration-http-transport-failure',
                ],
                'body' => '{"input":"hello"}',
            ]);

            self::assertSame(502, $response->getStatusCode());
            self::assertStringContainsString('HTTP request failed', (string) $response->getBody());

            $trace = $this->waitForTrace($home . '/logs/trace.jsonl', 'upstream_response');
            self::assertSame('http', $trace['transport']);
            self::assertSame('upstream_response', $trace['phase']);
            self::assertSame(502, $trace['status']);
            self::assertSame('transient', $trace['classification']);
        } finally {
            $this->stopProcess($proxy ?? null);
        }
    }

    public function testRetriesAcrossAllAvailableAccountsBeforeReturningQuotaFailure(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-retry-all');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));
        $this->writeJson($accountsDir . '/beta.account.json', $this->accountFixture('beta'));
        $this->writeJson($accountsDir . '/gamma.account.json', $this->accountFixture('gamma'));

        $captureFile = $home . '/upstream-requests.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-quota-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $http = new GuzzleClient(['http_errors' => false, 'timeout' => 5]);
            $response = $http->post("http://127.0.0.1:{$proxyPort}/v1/responses/compact", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Request-Id' => 'integration-retry-all',
                    'X-Codex-Turn-State' => 'turn-retry-all',
                ],
                'body' => '{"input":"hello"}',
            ]);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('{"id":"resp_3","object":"response.compaction"}', (string) $response->getBody());

            $attempts = $this->waitForJsonLines($captureFile, 3);
            self::assertSame(['acct-alpha', 'acct-beta', 'acct-gamma'], array_column($attempts, 'account_id'));

            $state = $this->waitForJsonFile($home . '/state.json');
            self::assertSame('acct-gamma', $state['sessions']['x-codex-turn-state:turn-retry-all']);
            self::assertGreaterThan(time(), $state['accounts']['acct-alpha']['cooldown_until']);
            self::assertGreaterThan(time(), $state['accounts']['acct-beta']['cooldown_until']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testWritesTimingTraceForCompletedHttpRequest(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-http-timing');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-request.json';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home, 'true'], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $http = new GuzzleClient(['http_errors' => false, 'timeout' => 5]);
            $response = $http->post("http://127.0.0.1:{$proxyPort}/v1/responses/compact", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Request-Id' => 'integration-http-timing',
                ],
                'body' => '{"input":"hello"}',
            ]);

            self::assertSame(200, $response->getStatusCode());

            $trace = $this->waitForTrace($home . '/logs/trace.jsonl', 'request_completed');
            self::assertSame('http', $trace['transport']);
            self::assertSame('request_completed', $trace['phase']);
            self::assertSame('alpha', $trace['account']);
            self::assertSame(200, $trace['status']);
            self::assertSame('none', $trace['classification']);
            self::assertSame(1, $trace['attempts']);
            self::assertArrayHasKey('timings_ms', $trace);
            self::assertArrayHasKey('scheduler_reload', $trace['timings_ms']);
            self::assertArrayHasKey('account_prepare', $trace['timings_ms']);
            self::assertArrayHasKey('upstream', $trace['timings_ms']);
            self::assertArrayHasKey('total', $trace['timings_ms']);
            self::assertGreaterThanOrEqual(0, $trace['timings_ms']['scheduler_reload']);
            self::assertGreaterThanOrEqual(0, $trace['timings_ms']['account_prepare']);
            self::assertGreaterThan(0, $trace['timings_ms']['upstream']);
            self::assertGreaterThan(0, $trace['timings_ms']['total']);
            self::assertGreaterThanOrEqual($trace['timings_ms']['upstream'], $trace['timings_ms']['total']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testProxiesStreamingResponsesWithoutDroppingControlFieldsOrCompletedFrame(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-streaming');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-request.json';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-streaming-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $http = new GuzzleClient(['http_errors' => false, 'timeout' => 5]);
            $response = $http->post("http://127.0.0.1:{$proxyPort}/v1/responses", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Request-Id' => 'integration-streaming',
                ],
                'body' => json_encode([
                    'input' => 'hello',
                    'previous_response_id' => 'resp_prev_1',
                    'max_output_tokens' => 2048,
                    'max_completion_tokens' => 2048,
                    'truncation' => 'auto',
                    'service_tier' => 'default',
                    'temperature' => 0.2,
                    'top_p' => 0.9,
                    'context_management' => [
                        'compaction' => ['type' => 'auto'],
                    ],
                ], JSON_THROW_ON_ERROR),
            ]);

            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('text/event-stream', strtolower($response->getHeaderLine('Content-Type')));

            $body = (string) $response->getBody();
            self::assertStringContainsString('"delta":"Hello"', $body);
            self::assertStringContainsString('"delta":" world"', $body);
            self::assertStringContainsString('"type":"response.completed"', $body);
            self::assertTrue(strpos($body, '"delta":"Hello"') < strpos($body, '"delta":" world"'));
            self::assertTrue(strpos($body, '"delta":" world"') < strpos($body, '"type":"response.completed"'));

            $capture = $this->waitForJsonFile($captureFile);
            $forwarded = json_decode((string) ($capture['body'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('resp_prev_1', $forwarded['previous_response_id'] ?? null);
            self::assertSame(2048, $forwarded['max_output_tokens'] ?? null);
            self::assertSame(2048, $forwarded['max_completion_tokens'] ?? null);
            self::assertSame('auto', $forwarded['truncation'] ?? null);
            self::assertSame('default', $forwarded['service_tier'] ?? null);
            self::assertSame(0.2, $forwarded['temperature'] ?? null);
            self::assertSame(0.9, $forwarded['top_p'] ?? null);
            self::assertSame(['compaction' => ['type' => 'auto']], $forwarded['context_management'] ?? null);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testEmitsHttpStreamErrorWhenUpstreamClosesBeforeCompleted(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-incomplete-streaming');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-request.json';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-incomplete-stream-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $http = new GuzzleClient(['http_errors' => false, 'timeout' => 5]);
            $response = $http->post("http://127.0.0.1:{$proxyPort}/v1/responses", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Request-Id' => 'integration-incomplete-stream',
                ],
                'body' => '{"input":"hello"}',
            ]);

            self::assertSame(200, $response->getStatusCode());
            $body = (string) $response->getBody();
            self::assertStringContainsString('"delta":"Hello"', $body);
            self::assertStringContainsString('"delta":" world"', $body);
            self::assertStringContainsString('upstream_stream_incomplete', $body);
            self::assertStringNotContainsString('"type":"response.completed"', $body);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testDoesNotAppendGenericIncompleteErrorAfterExplicitStreamError(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-streaming-explicit-error');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-request.json';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-streaming-explicit-error-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $http = new GuzzleClient(['http_errors' => false, 'timeout' => 5]);
            $response = $http->post("http://127.0.0.1:{$proxyPort}/v1/responses", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Request-Id' => 'integration-streaming-explicit-error',
                ],
                'body' => '{"input":"hello"}',
            ]);

            self::assertSame(200, $response->getStatusCode());
            $body = (string) $response->getBody();
            self::assertStringContainsString('"delta":"Hello"', $body);
            self::assertStringContainsString('previous_response_not_found', $body);
            self::assertStringNotContainsString('upstream_stream_incomplete', $body);
            self::assertSame(1, substr_count($body, '"type":"error"'));
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testRefreshesInvalidatedTokenBeforeCoolingDownAccount(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-auth-refresh');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);

        $stale = $this->accountFixture('alpha');
        $freshPayload = [
            'iss' => 'https://auth.openai.com',
            'email' => 'alpha@example.com',
            'exp' => self::FIXTURE_EXP,
            'fresh' => true,
            'https://api.openai.com/auth' => [
                'chatgpt_account_id' => 'acct-alpha',
                'chatgpt_plan_type' => 'plus',
                'chatgpt_user_id' => 'user-alpha',
            ],
        ];
        $fresh = $this->accountFixture('alpha', [
            'tokens' => [
                'id_token' => $this->makeJwt($freshPayload),
                'access_token' => $this->makeJwt($freshPayload),
                'refresh_token' => 'rt-alpha-fresh',
            ],
        ]);
        $this->writeJson($accountsDir . '/alpha.account.json', $stale);

        $captureFile = $home . '/upstream-requests.jsonl';
        $refreshTokensFile = $home . '/refresh-tokens.json';
        $this->writeJson($refreshTokensFile, [
            'id_token' => $fresh['tokens']['id_token'],
            'access_token' => $fresh['tokens']['access_token'],
            'refresh_token' => $fresh['tokens']['refresh_token'],
        ]);

        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-auth-refresh-upstream.php', (string) $upstreamPort, $captureFile, $fresh['tokens']['access_token']], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy-with-refresh.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home, $refreshTokensFile], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $http = new GuzzleClient(['http_errors' => false, 'timeout' => 5]);
            $response = $http->post("http://127.0.0.1:{$proxyPort}/v1/responses/compact", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Request-Id' => 'integration-auth-refresh',
                    'X-Codex-Turn-State' => 'turn-auth-refresh',
                ],
                'body' => '{"input":"hello"}',
            ]);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('{"id":"resp_fresh","object":"response.compaction"}', (string) $response->getBody());

            $attempts = $this->waitForJsonLines($captureFile, 2);
            self::assertCount(2, $attempts);
            self::assertNotSame('Bearer ' . $fresh['tokens']['access_token'], $attempts[0]['authorization']);
            self::assertSame('Bearer ' . $fresh['tokens']['access_token'], $attempts[1]['authorization']);

            $state = $this->waitForJsonFile($home . '/state.json');
            self::assertSame('acct-alpha', $state['sessions']['x-codex-turn-state:turn-auth-refresh']);
            self::assertTrue(!isset($state['accounts']['acct-alpha']) || (int) ($state['accounts']['acct-alpha']['cooldown_until'] ?? 0) <= 0);

            $accountFile = json_decode((string) file_get_contents($accountsDir . '/alpha.account.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($fresh['tokens']['access_token'], $accountFile['tokens']['access_token']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testReloadsUpdatedAccountFileBeforeHandlingRequest(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-account-reload');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);

        $stale = $this->accountFixture('alpha');
        $freshPayload = [
            'iss' => 'https://auth.openai.com',
            'email' => 'alpha@example.com',
            'exp' => self::FIXTURE_EXP,
            'fresh' => true,
            'https://api.openai.com/auth' => [
                'chatgpt_account_id' => 'acct-alpha',
                'chatgpt_plan_type' => 'plus',
                'chatgpt_user_id' => 'user-alpha',
            ],
        ];
        $fresh = $this->accountFixture('alpha', [
            'tokens' => [
                'id_token' => $this->makeJwt($freshPayload),
                'access_token' => $this->makeJwt($freshPayload),
                'refresh_token' => 'rt-alpha-fresh',
            ],
        ]);
        $accountPath = $accountsDir . '/alpha.account.json';
        $this->writeJson($accountPath, $stale);

        $captureFile = $home . '/upstream-requests.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-auth-refresh-upstream.php', (string) $upstreamPort, $captureFile, $fresh['tokens']['access_token']], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $this->writeJson($accountPath, $fresh);

            $http = new GuzzleClient(['http_errors' => false, 'timeout' => 5]);
            $response = $http->post("http://127.0.0.1:{$proxyPort}/v1/responses/compact", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Request-Id' => 'integration-account-reload',
                ],
                'body' => '{"input":"hello"}',
            ]);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('{"id":"resp_fresh","object":"response.compaction"}', (string) $response->getBody());

            $attempts = $this->waitForJsonLines($captureFile, 1);
            self::assertCount(1, $attempts);
            self::assertSame('Bearer ' . $fresh['tokens']['access_token'], $attempts[0]['authorization']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testRecoversAuthCooldownAccountWhenNoAccountIsAvailable(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-auth-cooldown-recovery');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);

        $stale = $this->accountFixture('alpha');
        $freshPayload = [
            'iss' => 'https://auth.openai.com',
            'email' => 'alpha@example.com',
            'exp' => self::FIXTURE_EXP,
            'fresh' => true,
            'https://api.openai.com/auth' => [
                'chatgpt_account_id' => 'acct-alpha',
                'chatgpt_plan_type' => 'plus',
                'chatgpt_user_id' => 'user-alpha',
            ],
        ];
        $fresh = $this->accountFixture('alpha', [
            'tokens' => [
                'id_token' => $this->makeJwt($freshPayload),
                'access_token' => $this->makeJwt($freshPayload),
                'refresh_token' => 'rt-alpha-fresh',
            ],
        ]);
        $this->writeJson($accountsDir . '/alpha.account.json', $stale);
        $this->writeJson($home . '/state.json', [
            'accounts' => [
                'acct-alpha' => [
                    'cooldown_until' => time() + 1800,
                    'cooldown_reason' => 'auth',
                ],
            ],
            'sessions' => [],
            'cursor' => 0,
            'usage' => [
                'acct-alpha' => [
                    'plan_type' => 'plus',
                    'checked_at' => time(),
                    'error' => null,
                    'primary' => [
                        'used_percent' => 20.0,
                        'left_percent' => 80.0,
                        'window_minutes' => 300,
                        'resets_at' => time() + 1800,
                    ],
                    'secondary' => [
                        'used_percent' => 3.0,
                        'left_percent' => 97.0,
                        'window_minutes' => 10080,
                        'resets_at' => time() + 86_400,
                    ],
                ],
            ],
        ]);

        $captureFile = $home . '/upstream-requests.jsonl';
        $refreshTokensFile = $home . '/refresh-tokens.json';
        $this->writeJson($refreshTokensFile, [
            'id_token' => $fresh['tokens']['id_token'],
            'access_token' => $fresh['tokens']['access_token'],
            'refresh_token' => $fresh['tokens']['refresh_token'],
        ]);

        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-auth-refresh-upstream.php', (string) $upstreamPort, $captureFile, $fresh['tokens']['access_token']], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy-with-refresh.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home, $refreshTokensFile], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $http = new GuzzleClient(['http_errors' => false, 'timeout' => 5]);
            $response = $http->post("http://127.0.0.1:{$proxyPort}/v1/responses/compact", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Request-Id' => 'integration-auth-cooldown-recovery',
                    'X-Codex-Turn-State' => 'turn-auth-cooldown-recovery',
                ],
                'body' => '{"input":"hello"}',
            ]);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('{"id":"resp_fresh","object":"response.compaction"}', (string) $response->getBody());

            $attempts = $this->waitForJsonLines($captureFile, 1);
            self::assertCount(1, $attempts);
            self::assertSame('Bearer ' . $fresh['tokens']['access_token'], $attempts[0]['authorization']);

            $state = $this->waitForJsonFile($home . '/state.json');
            self::assertSame('acct-alpha', $state['sessions']['x-codex-turn-state:turn-auth-cooldown-recovery']);
            self::assertSame(0, (int) ($state['accounts']['acct-alpha']['cooldown_until'] ?? 0));
            self::assertFalse(isset($state['accounts']['acct-alpha']['cooldown_reason']));
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testTracesWebSocketUpstreamErrorFrame(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-trace');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-request.json';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $payload = null;
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$payload): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                if (!$client->upgrade('/v1/responses')) {
                    $payload = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"hello"}');
                $frame = $client->recv(5);
                $payload = is_object($frame) && property_exists($frame, 'data') ? (string) $frame->data : (string) $frame;
                $client->close();
            });

            self::assertStringContainsString('usage_limit_reached', (string) $payload);
            $trace = $this->waitForTrace($home . '/logs/trace.jsonl', 'upstream_error');
            self::assertSame('websocket', $trace['transport']);
            self::assertSame('upstream_error', $trace['phase']);
            self::assertSame('alpha', $trace['account']);
            self::assertSame('quota', $trace['classification']);
            self::assertStringContainsString('usage_limit_reached', $trace['message']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testWritesTimingTraceForOpenedWebSocket(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-timing');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-done-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home, 'true'], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $payload = null;
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$payload): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                if (!$client->upgrade('/v1/responses')) {
                    $payload = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"hello"}');
                $frame = $client->recv(5);
                $payload = is_object($frame) && property_exists($frame, 'data') ? (string) $frame->data : (string) $frame;
                $client->close();
            });

            self::assertStringContainsString('response.completed', (string) $payload);

            $trace = $this->waitForTrace($home . '/logs/trace.jsonl', 'websocket_opened');
            self::assertSame('websocket', $trace['transport']);
            self::assertSame('websocket_opened', $trace['phase']);
            self::assertSame('alpha', $trace['account']);
            self::assertSame(101, $trace['status']);
            self::assertSame('none', $trace['classification']);
            self::assertSame(1, $trace['attempts']);
            self::assertArrayHasKey('timings_ms', $trace);
            self::assertArrayHasKey('scheduler_reload', $trace['timings_ms']);
            self::assertArrayHasKey('account_prepare', $trace['timings_ms']);
            self::assertArrayHasKey('upstream_upgrade', $trace['timings_ms']);
            self::assertArrayHasKey('total', $trace['timings_ms']);
            self::assertGreaterThanOrEqual(0, $trace['timings_ms']['scheduler_reload']);
            self::assertGreaterThanOrEqual(0, $trace['timings_ms']['account_prepare']);
            self::assertGreaterThan(0, $trace['timings_ms']['upstream_upgrade']);
            self::assertGreaterThan(0, $trace['timings_ms']['total']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testFallsBackToHttpSseWhenWebSocketUpgradeIsRejected(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-http-fallback');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-requests.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-upgrade-fallback-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $frames = [];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$frames): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                if (!$client->upgrade('/v1/responses')) {
                    $frames[] = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"type":"response.create","model":"gpt-test","input":"hello"}');
                for ($i = 0; $i < 3; $i++) {
                    $frame = $client->recv(5);
                    if (is_object($frame) && property_exists($frame, 'data')) {
                        $frames[] = (string) $frame->data;
                        if (str_contains((string) $frame->data, 'response.completed')) {
                            break;
                        }
                        continue;
                    }
                    if ($frame !== false && $frame !== '') {
                        $frames[] = (string) $frame;
                    }
                    break;
                }
                $client->close();
            });

            self::assertNotSame([], $frames);
            self::assertStringContainsString('response.completed', implode("\n", $frames));
            self::assertStringContainsString('resp_http_fallback', implode("\n", $frames));
            self::assertStringNotContainsString('upstream_websocket_error', implode("\n", $frames));

            $attempts = $this->waitForJsonLines($captureFile, 2);
            self::assertSame('GET', $attempts[0]['method']);
            self::assertSame('websocket', $attempts[0]['upgrade']);
            self::assertSame('POST', $attempts[1]['method']);
            self::assertSame('text/event-stream', $attempts[1]['accept']);
            self::assertStringStartsWith('Bearer ', $attempts[1]['authorization']);
            self::assertStringNotContainsString('"type":"response.create"', $attempts[1]['body']);
            self::assertStringContainsString('"stream":true', $attempts[1]['body']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testForwardsHttpSseErrorAfterWebSocketUpgradeFallback(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-http-fallback-error');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-requests.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-upgrade-fallback-upstream.php', (string) $upstreamPort, $captureFile, 'error'], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $frames = [];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$frames): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                if (!$client->upgrade('/v1/responses')) {
                    $frames[] = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"type":"response.create","model":"gpt-test","input":"hello"}');
                $frame = $client->recv(5);
                if (is_object($frame) && property_exists($frame, 'data')) {
                    $frames[] = (string) $frame->data;
                } elseif ($frame !== false && $frame !== '') {
                    $frames[] = (string) $frame;
                }
                $client->close();
            });

            self::assertNotSame([], $frames);
            self::assertStringContainsString('"type":"error"', implode("\n", $frames));
            self::assertStringContainsString('server_error', implode("\n", $frames));
            self::assertStringNotContainsString('upstream_websocket_error', implode("\n", $frames));

            $attempts = $this->waitForJsonLines($captureFile, 2);
            self::assertSame('GET', $attempts[0]['method']);
            self::assertSame('POST', $attempts[1]['method']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testRetriesWebSocketAcrossAllAvailableAccountsBeforeForwardingSuccess(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-retry-all');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));
        $this->writeJson($accountsDir . '/beta.account.json', $this->accountFixture('beta'));
        $this->writeJson($accountsDir . '/gamma.account.json', $this->accountFixture('gamma'));

        $captureFile = $home . '/upstream-websocket-requests.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-quota-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $payload = null;
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$payload): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                $client->setHeaders([
                    'X-Codex-Turn-State' => 'turn-websocket-retry-all',
                ]);
                if (!$client->upgrade('/v1/responses')) {
                    $payload = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"hello"}');
                $frame = $client->recv(5);
                $payload = is_object($frame) && property_exists($frame, 'data') ? (string) $frame->data : (string) $frame;
                $client->close();
            });

            self::assertStringContainsString('resp_ws_gamma', (string) $payload);
            self::assertStringNotContainsString('usage_limit_reached', (string) $payload);

            $attempts = $this->waitForJsonLines($captureFile, 3);
            self::assertSame(['acct-alpha', 'acct-beta', 'acct-gamma'], array_column($attempts, 'account_id'));

            $state = $this->waitForJsonFile($home . '/state.json');
            self::assertSame('acct-gamma', $state['sessions']['x-codex-turn-state:turn-websocket-retry-all']);
            self::assertGreaterThan(time(), $state['accounts']['acct-alpha']['cooldown_until']);
            self::assertGreaterThan(time(), $state['accounts']['acct-beta']['cooldown_until']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testRebindsWebSocketSessionByProbingAccountsAfterPreviousResponseNotFound(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-lineage-probe');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));
        $this->writeJson($accountsDir . '/beta.account.json', $this->accountFixture('beta'));

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-previous-response-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $frames = [];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$frames): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                $client->setHeaders([
                    'X-Session-Id' => 'session-old-1',
                ]);
                if (!$client->upgrade('/v1/responses')) {
                    $frames[] = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"continue","previous_response_id":"resp_prev_beta"}');
                $frame = $client->recv(5);
                if (is_object($frame) && property_exists($frame, 'data')) {
                    $frames[] = (string) $frame->data;
                } elseif ($frame !== false && $frame !== '') {
                    $frames[] = (string) $frame;
                }
                $client->close();
            });

            self::assertCount(1, $frames);
            self::assertStringContainsString('resp_beta_next', $frames[0]);
            self::assertStringNotContainsString('previous_response_not_found', $frames[0]);

            $attempts = $this->waitForJsonLines($captureFile, 2);
            self::assertSame(['acct-alpha', 'acct-beta'], array_column($attempts, 'account_id'));

            $state = $this->waitForJsonFile($home . '/state.json');
            self::assertSame('acct-beta', $state['sessions']['x-session-id:session-old-1']);
            self::assertSame('lineage_switch', $state['session_meta']['x-session-id:session-old-1']['selection_source'] ?? null);
            self::assertSame('acct-beta', $state['responses']['resp_beta_next'] ?? null);
            self::assertSame(0, $state['accounts']['acct-alpha']['cooldown_until'] ?? 0);
            self::assertSame(0, $state['accounts']['acct-beta']['cooldown_until'] ?? 0);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testUsesStoredResponseAffinityBeforeExistingWebSocketBinding(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-response-affinity');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));
        $this->writeJson($accountsDir . '/beta.account.json', $this->accountFixture('beta'));
        $this->writeJson($home . '/state.json', [
            'accounts' => [],
            'sessions' => [
                'x-session-id:session-old-2' => 'acct-alpha',
            ],
            'session_meta' => [
                'x-session-id:session-old-2' => [
                    'selection_source' => 'new_session',
                    'bound_at' => 1000,
                    'last_seen_at' => 1000,
                ],
            ],
            'responses' => [
                'resp_prev_beta' => 'acct-beta',
            ],
            'cursor' => 0,
            'usage' => [],
        ]);

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-previous-response-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $frames = [];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$frames): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                $client->setHeaders([
                    'X-Session-Id' => 'session-old-2',
                ]);
                if (!$client->upgrade('/v1/responses')) {
                    $frames[] = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"continue","previous_response_id":"resp_prev_beta"}');
                $frame = $client->recv(5);
                if (is_object($frame) && property_exists($frame, 'data')) {
                    $frames[] = (string) $frame->data;
                } elseif ($frame !== false && $frame !== '') {
                    $frames[] = (string) $frame;
                }
                $client->close();
            });

            self::assertCount(1, $frames);
            self::assertStringContainsString('resp_beta_next', $frames[0]);

            $attempts = $this->waitForJsonLines($captureFile, 1);
            self::assertSame('acct-beta', $attempts[0]['account_id']);

            $state = $this->waitForJsonFile($home . '/state.json');
            self::assertSame('acct-beta', $state['sessions']['x-session-id:session-old-2']);
            self::assertSame('rebind_response_affinity', $state['session_meta']['x-session-id:session-old-2']['selection_source'] ?? null);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testReturnsExplicitPreviousResponseNotFoundWhenAllAccountsRejectLineage(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-lineage-all-miss');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));
        $this->writeJson($accountsDir . '/beta.account.json', $this->accountFixture('beta'));

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-previous-response-upstream.php', (string) $upstreamPort, $captureFile, 'all-miss'], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $frames = [];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$frames): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                $client->setHeaders([
                    'X-Session-Id' => 'session-lineage-all-miss',
                ]);
                if (!$client->upgrade('/v1/responses')) {
                    $frames[] = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"continue","previous_response_id":"resp_prev_beta"}');
                $frame = $client->recv(5);
                if (is_object($frame) && property_exists($frame, 'data')) {
                    $frames[] = (string) $frame->data;
                } elseif ($frame !== false && $frame !== '') {
                    $frames[] = (string) $frame;
                }
                $client->close();
            });

            self::assertCount(1, $frames);
            self::assertStringContainsString('previous_response_not_found', $frames[0]);
            self::assertStringNotContainsString('upstream_websocket_error', $frames[0]);

            $attempts = $this->waitForJsonLines($captureFile, 2);
            self::assertSame(['acct-alpha', 'acct-beta'], array_column($attempts, 'account_id'));

            $state = $this->waitForJsonFile($home . '/state.json');
            self::assertSame(0, $state['accounts']['acct-alpha']['cooldown_until'] ?? 0);
            self::assertSame(0, $state['accounts']['acct-beta']['cooldown_until'] ?? 0);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testSwitchesReusedUpstreamWebSocketWhenStoredResponseAffinityTargetsDifferentAccount(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-reuse-response-affinity');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));
        $this->writeJson($accountsDir . '/beta.account.json', $this->accountFixture('beta'));
        $this->writeJson($home . '/state.json', [
            'accounts' => [],
            'sessions' => [],
            'session_meta' => [],
            'responses' => [
                'resp_prev_beta' => 'acct-beta',
            ],
            'cursor' => 0,
            'usage' => [],
        ]);

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-previous-response-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $frames = [];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$frames): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                $client->setHeaders([
                    'X-Session-Id' => 'session-reuse-affinity',
                ]);
                if (!$client->upgrade('/v1/responses')) {
                    $frames[] = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"hello"}');
                $first = $client->recv(5);
                if (is_object($first) && property_exists($first, 'data')) {
                    $frames[] = (string) $first->data;
                } elseif ($first !== false && $first !== '') {
                    $frames[] = (string) $first;
                }

                $client->push('{"input":"continue","previous_response_id":"resp_prev_beta"}');
                $second = $client->recv(5);
                if (is_object($second) && property_exists($second, 'data')) {
                    $frames[] = (string) $second->data;
                } elseif ($second !== false && $second !== '') {
                    $frames[] = (string) $second;
                }
                $client->close();
            });

            self::assertCount(2, $frames);
            self::assertStringContainsString('resp_alpha_fresh', $frames[0]);
            self::assertStringContainsString('resp_beta_next', $frames[1]);
            self::assertStringNotContainsString('previous_response_not_found', $frames[1]);

            $attempts = $this->waitForJsonLines($captureFile, 2);
            self::assertSame(['acct-alpha', 'acct-beta'], array_column($attempts, 'account_id'));

            $state = $this->waitForJsonFile($home . '/state.json');
            self::assertSame('acct-beta', $state['sessions']['x-session-id:session-reuse-affinity']);
            self::assertSame('rebind_response_affinity', $state['session_meta']['x-session-id:session-reuse-affinity']['selection_source'] ?? null);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testRetriesHttpFallbackAcrossAccountsForLineageErrorsWithoutCooldown(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-http-fallback-lineage');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));
        $this->writeJson($accountsDir . '/beta.account.json', $this->accountFixture('beta'));

        $captureFile = $home . '/upstream-requests.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-upgrade-fallback-upstream.php', (string) $upstreamPort, $captureFile, 'lineage'], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $frames = [];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$frames): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                if (!$client->upgrade('/v1/responses')) {
                    $frames[] = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"type":"response.create","input":"continue","previous_response_id":"resp_prev_beta"}');
                for ($i = 0; $i < 3; $i++) {
                    $frame = $client->recv(5);
                    if (!is_object($frame) || !property_exists($frame, 'data')) {
                        continue;
                    }
                    $frames[] = (string) $frame->data;
                    if (str_contains((string) $frame->data, 'response.completed')) {
                        break;
                    }
                }
                $client->close();
            });

            self::assertNotSame([], $frames);
            self::assertStringContainsString('resp_http_beta_next', implode("\n", $frames));
            self::assertStringNotContainsString('previous_response_not_found', implode("\n", $frames));

            $attempts = $this->waitForJsonLines($captureFile, 3);
            self::assertSame('GET', $attempts[0]['method']);
            self::assertSame('POST', $attempts[1]['method']);
            self::assertSame('POST', $attempts[2]['method']);
            self::assertSame('acct-alpha', $attempts[1]['account_id']);
            self::assertSame('acct-beta', $attempts[2]['account_id']);

            $state = $this->waitForJsonFile($home . '/state.json');
            self::assertSame(0, $state['accounts']['acct-alpha']['cooldown_until'] ?? 0);
            self::assertSame('acct-beta', $state['sessions']['previous_response_id:resp_prev_beta']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testUsesStoredCompactResponseAffinityForSubsequentHttpResponseRequest(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-http-compact-affinity');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));
        $this->writeJson($accountsDir . '/beta.account.json', $this->accountFixture('beta'));
        $this->writeJson($home . '/state.json', [
            'accounts' => [],
            'sessions' => [
                'previous_response_id:resp_compact_beta' => 'acct-alpha',
            ],
            'session_meta' => [
                'previous_response_id:resp_compact_beta' => [
                    'selection_source' => 'new_session',
                    'bound_at' => 1000,
                    'last_seen_at' => 1000,
                ],
            ],
            'responses' => [],
            'cursor' => 1,
            'usage' => [],
        ]);

        $captureFile = $home . '/upstream-requests.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-http-response-affinity-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $http = new GuzzleClient(['http_errors' => false, 'timeout' => 5]);
            $compact = $http->post("http://127.0.0.1:{$proxyPort}/v1/responses/compact", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Request-Id' => 'integration-compact-affinity',
                ],
                'body' => '{"input":"hello compact"}',
            ]);
            self::assertSame(200, $compact->getStatusCode());
            self::assertSame('{"id":"resp_compact_beta","object":"response.compaction"}', (string) $compact->getBody());

            $stream = $http->post("http://127.0.0.1:{$proxyPort}/v1/responses", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Request-Id' => 'integration-compact-affinity-followup',
                ],
                'body' => '{"input":"continue","previous_response_id":"resp_compact_beta"}',
            ]);

            self::assertSame(200, $stream->getStatusCode());
            self::assertStringContainsString('resp_after_compact_beta', (string) $stream->getBody());

            $attempts = $this->waitForJsonLines($captureFile, 2);
            self::assertSame('acct-beta', $attempts[0]['account_id']);
            self::assertSame('acct-beta', $attempts[1]['account_id']);

            $state = $this->waitForJsonFile($home . '/state.json');
            self::assertSame('acct-beta', $state['responses']['resp_compact_beta'] ?? null);
            self::assertSame('acct-beta', $state['sessions']['previous_response_id:resp_compact_beta']);
            self::assertSame('rebind_response_affinity', $state['session_meta']['previous_response_id:resp_compact_beta']['selection_source'] ?? null);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testRoutesConcurrentSessionsWithSameStoredLineageToSameAccount(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-concurrent-lineage');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));
        $this->writeJson($accountsDir . '/beta.account.json', $this->accountFixture('beta'));
        $this->writeJson($home . '/state.json', [
            'accounts' => [],
            'sessions' => [],
            'session_meta' => [],
            'responses' => [
                'resp_prev_beta' => 'acct-beta',
            ],
            'cursor' => 0,
            'usage' => [],
        ]);

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-previous-response-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $frames = [];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$frames): void {
                $runClient = static function (string $sessionId) use ($proxyPort, &$frames): void {
                    $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                    $client->set(['timeout' => 5]);
                    $client->setHeaders([
                        'X-Session-Id' => $sessionId,
                    ]);
                    if (!$client->upgrade('/v1/responses')) {
                        $frames[] = 'upgrade failed: ' . (string) $client->errCode;
                        $client->close();
                        return;
                    }

                    $client->push('{"input":"continue","previous_response_id":"resp_prev_beta"}');
                    $frame = $client->recv(5);
                    if (is_object($frame) && property_exists($frame, 'data')) {
                        $frames[] = (string) $frame->data;
                    } elseif ($frame !== false && $frame !== '') {
                        $frames[] = (string) $frame;
                    }
                    $client->close();
                };

                \Swoole\Coroutine::create(static fn () => $runClient('session-concurrent-1'));
                \Swoole\Coroutine::create(static fn () => $runClient('session-concurrent-2'));
            });

            self::assertCount(2, $frames);
            self::assertStringContainsString('resp_beta_next', $frames[0]);
            self::assertStringContainsString('resp_beta_next', $frames[1]);

            $attempts = $this->waitForJsonLines($captureFile, 2);
            self::assertSame(['acct-beta', 'acct-beta'], array_column($attempts, 'account_id'));
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testDoesNotStoreLateCompletedFrameAsResponseAffinity(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-late-completed-affinity');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-late-frame-upstream.php', (string) $upstreamPort, $captureFile, 'late-completed'], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $frames = [];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$frames): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                if (!$client->upgrade('/v1/responses')) {
                    $frames[] = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"hello"}');
                $first = $client->recv(5);
                if (is_object($first) && property_exists($first, 'data')) {
                    $frames[] = (string) $first->data;
                }

                \Swoole\Coroutine::sleep(0.3);

                $client->push('{"input":"hello again"}');
                $second = $client->recv(5);
                if (is_object($second) && property_exists($second, 'data')) {
                    $frames[] = (string) $second->data;
                }
                $client->close();
            });

            self::assertCount(2, $frames);
            self::assertStringContainsString('resp_ws_first', $frames[0]);
            self::assertStringContainsString('resp_ws_second', $frames[1]);
            self::assertStringNotContainsString('resp_ws_late_after_done', implode("\n", $frames));

            $state = $this->waitForJsonFile($home . '/state.json');
            self::assertSame('acct-alpha', $state['responses']['resp_ws_first'] ?? null);
            self::assertSame('acct-alpha', $state['responses']['resp_ws_second'] ?? null);
            self::assertArrayNotHasKey('resp_ws_late_after_done', $state['responses'] ?? []);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testReturnsExplicitPreviousResponseNotFoundForHttpWhenAllAccountsRejectLineage(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-http-lineage-all-miss');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));
        $this->writeJson($accountsDir . '/beta.account.json', $this->accountFixture('beta'));

        $captureFile = $home . '/upstream-requests.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-http-response-affinity-upstream.php', (string) $upstreamPort, $captureFile, 'all-miss'], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $http = new GuzzleClient(['http_errors' => false, 'timeout' => 5]);
            $response = $http->post("http://127.0.0.1:{$proxyPort}/v1/responses", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Request-Id' => 'integration-http-lineage-all-miss',
                ],
                'body' => '{"input":"continue","previous_response_id":"resp_compact_beta"}',
            ]);

            self::assertSame(400, $response->getStatusCode());
            self::assertStringContainsString('application/json', strtolower($response->getHeaderLine('Content-Type')));
            self::assertStringContainsString('previous_response_not_found', (string) $response->getBody());

            $attempts = $this->waitForJsonLines($captureFile, 2);
            self::assertSame(['acct-alpha', 'acct-beta'], array_column($attempts, 'account_id'));

            $state = $this->waitForJsonFile($home . '/state.json');
            self::assertSame(0, $state['accounts']['acct-alpha']['cooldown_until'] ?? 0);
            self::assertSame(0, $state['accounts']['acct-beta']['cooldown_until'] ?? 0);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testUsesResponseDoneAffinityToOverrideWrongBindingOnFollowupWebSocketRequest(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-response-done-affinity');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));
        $this->writeJson($accountsDir . '/beta.account.json', $this->accountFixture('beta'));

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-done-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $frames = [];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$frames): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                $client->setHeaders([
                    'X-Session-Id' => 'session-done-source',
                ]);
                if (!$client->upgrade('/v1/responses')) {
                    $frames[] = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"hello"}');
                $frame = $client->recv(5);
                if (is_object($frame) && property_exists($frame, 'data')) {
                    $frames[] = (string) $frame->data;
                }
                $client->close();
            });

            self::assertCount(1, $frames);
            self::assertStringContainsString('resp_ws_done', $frames[0]);

            $state = $this->waitForJsonFile($home . '/state.json');
            $state['sessions']['previous_response_id:resp_ws_done'] = 'acct-beta';
            $state['session_meta']['previous_response_id:resp_ws_done'] = [
                'selection_source' => 'new_session',
                'bound_at' => 1000,
                'last_seen_at' => 1000,
            ];
            $this->writeJson($home . '/state.json', $state);

            $moreFrames = [];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$moreFrames): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                if (!$client->upgrade('/v1/responses')) {
                    $moreFrames[] = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"continue","previous_response_id":"resp_ws_done"}');
                $frame = $client->recv(5);
                if (is_object($frame) && property_exists($frame, 'data')) {
                    $moreFrames[] = (string) $frame->data;
                }
                $client->close();
            });

            self::assertCount(1, $moreFrames);
            self::assertStringContainsString('resp_after_done_alpha', $moreFrames[0]);
            self::assertStringNotContainsString('previous_response_not_found', $moreFrames[0]);

            $attempts = $this->waitForJsonLines($captureFile, 2);
            self::assertSame('acct-alpha', $attempts[0]['account_id']);
            self::assertSame('acct-alpha', $attempts[1]['account_id']);

            $finalState = $this->waitForJsonFile($home . '/state.json');
            self::assertSame('acct-alpha', $finalState['responses']['resp_ws_done'] ?? null);
            self::assertSame('acct-alpha', $finalState['sessions']['previous_response_id:resp_ws_done']);
            self::assertSame('rebind_response_affinity', $finalState['session_meta']['previous_response_id:resp_ws_done']['selection_source'] ?? null);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testRetriesWebSocketUpgradeAcrossAllAvailableAccountsBeforeForwardingSuccess(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-upgrade-retry-all');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));
        $this->writeJson($accountsDir . '/beta.account.json', $this->accountFixture('beta'));
        $this->writeJson($accountsDir . '/gamma.account.json', $this->accountFixture('gamma'));

        $captureFile = $home . '/upstream-websocket-upgrades.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-upgrade-quota-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $payload = null;
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$payload): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                $client->setHeaders([
                    'X-Codex-Turn-State' => 'turn-websocket-upgrade-retry-all',
                ]);
                if (!$client->upgrade('/v1/responses')) {
                    $payload = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"hello"}');
                $frame = $client->recv(5);
                $payload = is_object($frame) && property_exists($frame, 'data') ? (string) $frame->data : (string) $frame;
                $client->close();
            });

            self::assertStringContainsString('resp_ws_upgrade_gamma', (string) $payload);

            $attempts = $this->waitForJsonLines($captureFile, 3);
            self::assertSame(['acct-alpha', 'acct-beta', 'acct-gamma'], array_column($attempts, 'account_id'));

            $state = $this->waitForJsonFile($home . '/state.json');
            self::assertSame('acct-gamma', $state['sessions']['x-codex-turn-state:turn-websocket-upgrade-retry-all']);
            self::assertGreaterThan(time(), $state['accounts']['acct-alpha']['cooldown_until']);
            self::assertGreaterThan(time(), $state['accounts']['acct-beta']['cooldown_until']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testRetriesReplacementWebSocketUpgradeAcrossAllAvailableAccountsBeforeForwardingSuccess(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-replacement-upgrade-retry-all');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));
        $this->writeJson($accountsDir . '/beta.account.json', $this->accountFixture('beta'));
        $this->writeJson($accountsDir . '/gamma.account.json', $this->accountFixture('gamma'));

        $captureFile = $home . '/upstream-websocket-upgrades.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-stream-then-upgrade-quota-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $payload = null;
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$payload): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                $client->setHeaders([
                    'X-Codex-Turn-State' => 'turn-websocket-replacement-upgrade-retry-all',
                ]);
                if (!$client->upgrade('/v1/responses')) {
                    $payload = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"hello"}');
                $frame = $client->recv(5);
                $payload = is_object($frame) && property_exists($frame, 'data') ? (string) $frame->data : (string) $frame;
                $client->close();
            });

            self::assertStringContainsString('resp_ws_stream_upgrade_gamma', (string) $payload);
            self::assertStringNotContainsString('upstream_websocket_error', (string) $payload);

            $attempts = $this->waitForJsonLines($captureFile, 3);
            self::assertSame(['acct-alpha', 'acct-beta', 'acct-gamma'], array_column($attempts, 'account_id'));

            $state = $this->waitForJsonFile($home . '/state.json');
            self::assertSame('acct-gamma', $state['sessions']['x-codex-turn-state:turn-websocket-replacement-upgrade-retry-all']);
            self::assertGreaterThan(time(), $state['accounts']['acct-alpha']['cooldown_until']);
            self::assertGreaterThan(time(), $state['accounts']['acct-beta']['cooldown_until']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testPreservesWebSocketQueryStringWhenUpgradingUpstream(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-query');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-quota-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $payload = null;
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$payload): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                if (!$client->upgrade('/v1/responses?client_version=0.122.0&foo=bar')) {
                    $payload = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"hello"}');
                $frame = $client->recv(5);
                $payload = is_object($frame) && property_exists($frame, 'data') ? (string) $frame->data : (string) $frame;
                $client->close();
            });

            self::assertStringContainsString('usage_limit_reached', (string) $payload);
            $attempts = $this->waitForJsonLines($captureFile, 1);
            self::assertSame('/responses?client_version=0.122.0&foo=bar', $attempts[0]['target']);
            self::assertSame('client_version=0.122.0&foo=bar', $attempts[0]['query']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testEmitsWebSocketErrorWhenUpstreamClosesBeforeCompleted(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-incomplete');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-incomplete-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $frames = [];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$frames): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                if (!$client->upgrade('/v1/responses')) {
                    $frames[] = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"hello"}');
                for ($i = 0; $i < 2; $i++) {
                    $frame = $client->recv(5);
                    if (is_object($frame) && property_exists($frame, 'data')) {
                        $frames[] = (string) $frame->data;
                        continue;
                    }
                    $frames[] = (string) $frame;
                    break;
                }
                $client->close();
            });

            self::assertCount(2, $frames);
            self::assertStringContainsString('"delta":"Hello"', $frames[0]);
            self::assertStringContainsString('upstream_websocket_error', $frames[1]);
            self::assertStringContainsString('response.completed', $frames[1]);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testDoesNotAppendIncompleteWebSocketErrorAfterTerminalUpstreamError(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-terminal-error');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-websocket-request.json';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-terminal-error-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $frames = [];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$frames): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                if (!$client->upgrade('/v1/responses')) {
                    $frames[] = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"hello"}');
                for ($i = 0; $i < 2; $i++) {
                    $frame = $client->recv(5);
                    if (is_object($frame) && property_exists($frame, 'data')) {
                        $frames[] = (string) $frame->data;
                        continue;
                    }
                    break;
                }
                $client->close();
            });

            self::assertCount(1, $frames);
            self::assertStringContainsString('server_error', $frames[0]);
            self::assertStringNotContainsString('stream disconnected before response.completed', $frames[0]);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testRetriesWebSocketWhenUpstreamClosesBeforeAnyPayload(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-bootstrap-retry');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-bootstrap-retry-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $frames = [];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$frames): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                if (!$client->upgrade('/v1/responses')) {
                    $frames[] = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"hello"}');
                $frame = $client->recv(5);
                if (is_object($frame) && property_exists($frame, 'data')) {
                    $frames[] = (string) $frame->data;
                } elseif ($frame !== false && $frame !== '') {
                    $frames[] = (string) $frame;
                }
                $client->close();
            });

            self::assertNotSame([], $frames);
            self::assertCount(1, $frames);
            self::assertStringContainsString('resp_ws_bootstrap_retry', $frames[0]);
            self::assertStringContainsString('"type":"response.completed"', $frames[0]);
            self::assertStringNotContainsString('upstream_websocket_error', $frames[0]);

            $attempts = $this->waitForJsonLines($captureFile, 2);
            self::assertSame([
                'input' => 'hello',
                'type' => 'response.create',
            ], json_decode($attempts[0]['payload'], true, flags: JSON_THROW_ON_ERROR));
            self::assertSame([
                'input' => 'hello',
                'type' => 'response.create',
            ], json_decode($attempts[1]['payload'], true, flags: JSON_THROW_ON_ERROR));
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testKeepsDownstreamWebSocketOpenAcrossSequentialRequests(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-sequential');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-done-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $result = [
                'frames' => [],
                'second_push' => null,
            ];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$result): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                if (!$client->upgrade('/v1/responses')) {
                    $result['frames'][] = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"hello 1"}');
                $first = $client->recv(5);
                if (is_object($first) && property_exists($first, 'data')) {
                    $result['frames'][] = (string) $first->data;
                } elseif ($first !== false && $first !== '') {
                    $result['frames'][] = (string) $first;
                }

                $result['second_push'] = $client->push('{"input":"hello 2"}');
                $second = $client->recv(5);
                if (is_object($second) && property_exists($second, 'data')) {
                    $result['frames'][] = (string) $second->data;
                } elseif ($second !== false && $second !== '') {
                    $result['frames'][] = (string) $second;
                }
                $client->close();
            });

            self::assertTrue($result['second_push'] === true, 'Expected second downstream push to succeed');
            self::assertCount(2, $result['frames']);
            self::assertStringContainsString('"type":"response.completed"', $result['frames'][0]);
            self::assertStringContainsString('"type":"response.completed"', $result['frames'][1]);

            $attempts = $this->waitForJsonLines($captureFile, 2);
            self::assertSame([
                'input' => 'hello 1',
                'type' => 'response.create',
            ], json_decode($attempts[0]['payload'], true, flags: JSON_THROW_ON_ERROR));
            self::assertSame([
                'input' => 'hello 2',
                'type' => 'response.create',
            ], json_decode($attempts[1]['payload'], true, flags: JSON_THROW_ON_ERROR));
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testReopensUpstreamForSequentialRequestAfterUpstreamIdleClose(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-sequential-reopen');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-done-upstream.php', (string) $upstreamPort, $captureFile, 'close'], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $result = [
                'frames' => [],
                'second_push' => null,
            ];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$result): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                if (!$client->upgrade('/v1/responses')) {
                    $result['frames'][] = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"hello 1"}');
                $first = $client->recv(5);
                if (is_object($first) && property_exists($first, 'data')) {
                    $result['frames'][] = (string) $first->data;
                } elseif ($first !== false && $first !== '') {
                    $result['frames'][] = (string) $first;
                }

                \Swoole\Coroutine::sleep(0.2);

                $result['second_push'] = $client->push('{"input":"hello 2"}');
                $second = $client->recv(5);
                if (is_object($second) && property_exists($second, 'data')) {
                    $result['frames'][] = (string) $second->data;
                } elseif ($second !== false && $second !== '') {
                    $result['frames'][] = (string) $second;
                }
                $client->close();
            });

            self::assertTrue($result['second_push'] === true, 'Expected second downstream push to succeed after upstream close');
            self::assertCount(2, $result['frames']);
            self::assertStringContainsString('"type":"response.completed"', $result['frames'][0]);
            self::assertStringContainsString('"type":"response.completed"', $result['frames'][1]);

            $attempts = $this->waitForJsonLines($captureFile, 2);
            self::assertSame([
                'input' => 'hello 1',
                'type' => 'response.create',
            ], json_decode($attempts[0]['payload'], true, flags: JSON_THROW_ON_ERROR));
            self::assertSame([
                'input' => 'hello 2',
                'type' => 'response.create',
            ], json_decode($attempts[1]['payload'], true, flags: JSON_THROW_ON_ERROR));
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testQueuesSequentialRequestsOnSameDownstreamWebSocketUntilPreviousResponseCompletes(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-queued');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-queued-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $frames = [];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$frames): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                if (!$client->upgrade('/v1/responses')) {
                    $frames[] = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"hello 1"}');
                $client->push('{"input":"hello 2"}');

                for ($i = 0; $i < 2; $i++) {
                    $frame = $client->recv(5);
                    if (is_object($frame) && property_exists($frame, 'data')) {
                        $frames[] = (string) $frame->data;
                    } elseif ($frame !== false && $frame !== '') {
                        $frames[] = (string) $frame;
                    }
                }
                $client->close();
            });

            self::assertCount(2, $frames);
            self::assertStringContainsString('resp_ws_first', $frames[0]);
            self::assertStringContainsString('resp_ws_second', $frames[1]);
            self::assertStringNotContainsString('resp_ws_out_of_order', implode("\n", $frames));

            $attempts = $this->waitForJsonLines($captureFile, 2);
            self::assertFalse((bool) ($attempts[0]['out_of_order'] ?? false));
            self::assertFalse((bool) ($attempts[1]['out_of_order'] ?? false));
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testIgnoresLateUpstreamFrameAfterResponseCompleted(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-late-frame');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-late-frame-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $result = [
                'frames' => [],
                'late_frame' => null,
            ];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$result): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                if (!$client->upgrade('/v1/responses')) {
                    $result['frames'][] = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"hello 1"}');
                $first = $client->recv(5);
                if (is_object($first) && property_exists($first, 'data')) {
                    $result['frames'][] = (string) $first->data;
                } elseif ($first !== false && $first !== '') {
                    $result['frames'][] = (string) $first;
                }

                $late = $client->recv(0.35);
                if (is_object($late) && property_exists($late, 'data')) {
                    $result['late_frame'] = (string) $late->data;
                } elseif ($late !== false && $late !== '') {
                    $result['late_frame'] = (string) $late;
                }

                $client->push('{"input":"hello 2"}');
                $second = $client->recv(5);
                if (is_object($second) && property_exists($second, 'data')) {
                    $result['frames'][] = (string) $second->data;
                } elseif ($second !== false && $second !== '') {
                    $result['frames'][] = (string) $second;
                }
                $client->close();
            });

            self::assertCount(2, $result['frames']);
            self::assertStringContainsString('resp_ws_first', $result['frames'][0]);
            self::assertNull($result['late_frame'], 'Expected no extra downstream frame after response.completed');
            self::assertStringContainsString('resp_ws_second', $result['frames'][1]);

            $attempts = $this->waitForJsonLines($captureFile, 2);
            self::assertCount(2, $attempts);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testReusesUpstreamWebSocketAcrossDownstreamReconnectForSameSession(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-session-reuse');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-session-reuse-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $frames = [];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$frames): void {
                $firstClient = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $firstClient->set(['timeout' => 5]);
                $firstClient->setHeaders([
                    'X-Codex-Turn-State' => 'turn-reuse',
                ]);
                if (!$firstClient->upgrade('/v1/responses')) {
                    $frames[] = 'upgrade failed: ' . (string) $firstClient->errCode;
                    $firstClient->close();
                    return;
                }

                $firstClient->push('{"input":"hello 1"}');
                $first = $firstClient->recv(5);
                if (is_object($first) && property_exists($first, 'data')) {
                    $frames[] = (string) $first->data;
                } elseif ($first !== false && $first !== '') {
                    $frames[] = (string) $first;
                }
                $firstClient->close();

                $secondClient = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $secondClient->set(['timeout' => 5]);
                $secondClient->setHeaders([
                    'X-Codex-Turn-State' => 'turn-reuse',
                ]);
                if (!$secondClient->upgrade('/v1/responses')) {
                    $frames[] = 'upgrade failed: ' . (string) $secondClient->errCode;
                    $secondClient->close();
                    return;
                }

                $secondClient->push('{"input":"hello 2"}');
                $second = $secondClient->recv(5);
                if (is_object($second) && property_exists($second, 'data')) {
                    $frames[] = (string) $second->data;
                } elseif ($second !== false && $second !== '') {
                    $frames[] = (string) $second;
                }
                $secondClient->close();
            });

            self::assertCount(2, $frames);
            self::assertStringContainsString('resp_ws_conn_1_msg_1', $frames[0]);
            self::assertStringContainsString('resp_ws_conn_1_msg_2', $frames[1]);

            $attempts = $this->waitForJsonLines($captureFile, 3);
            self::assertSame('open', $attempts[0]['event']);
            self::assertSame('message', $attempts[1]['event']);
            self::assertSame('message', $attempts[2]['event']);
            self::assertSame(1, $attempts[1]['connection_index']);
            self::assertSame(1, $attempts[2]['connection_index']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testClosesActiveUpstreamWhenDownstreamDisconnectsBeforeCompleted(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-abort-active-upstream');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-disconnect-reconnect-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $payload = null;
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$payload): void {
                $firstClient = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $firstClient->set(['timeout' => 5]);
                $firstClient->setHeaders(['X-Codex-Turn-State' => 'turn-abort-active']);
                if (!$firstClient->upgrade('/v1/responses')) {
                    $payload = 'first upgrade failed: ' . (string) $firstClient->errCode;
                    $firstClient->close();
                    return;
                }
                $firstClient->push('{"input":"hello 1"}');
                $firstClient->close();

                $secondClient = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $secondClient->set(['timeout' => 3]);
                $secondClient->setHeaders(['X-Codex-Turn-State' => 'turn-abort-active']);
                if (!$secondClient->upgrade('/v1/responses')) {
                    $payload = 'second upgrade failed: ' . (string) $secondClient->errCode;
                    $secondClient->close();
                    return;
                }

                $secondClient->push('{"input":"hello 2"}');
                $frame = $secondClient->recv(3);
                if (is_object($frame) && property_exists($frame, 'data')) {
                    $payload = (string) $frame->data;
                } elseif ($frame !== false && $frame !== '') {
                    $payload = (string) $frame;
                }
                $secondClient->close();
            });

            self::assertNotNull($payload);
            self::assertStringContainsString('resp_ws_new_after_reconnect', (string) $payload);
            self::assertStringNotContainsString('resp_ws_old_late', (string) $payload);

            $attempts = $this->waitForJsonLines($captureFile, 4);
            self::assertSame('open', $attempts[0]['event']);
            self::assertSame('message', $attempts[1]['event']);
            self::assertSame('open', $attempts[2]['event']);
            self::assertSame('message', $attempts[3]['event']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testReusesUpstreamWebSocketAcrossTurnStateChangesWhenStableSessionIdExists(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-session-stable-id');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-session-reuse-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $frames = [];
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$frames): void {
                $firstClient = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $firstClient->set(['timeout' => 5]);
                $firstClient->setHeaders([
                    'X-Session-Id' => 'session-stable-1',
                    'X-Codex-Turn-State' => 'turn-1',
                ]);
                if (!$firstClient->upgrade('/v1/responses')) {
                    $frames[] = 'upgrade failed: ' . (string) $firstClient->errCode;
                    $firstClient->close();
                    return;
                }

                $firstClient->push('{"input":"hello 1"}');
                $first = $firstClient->recv(5);
                if (is_object($first) && property_exists($first, 'data')) {
                    $frames[] = (string) $first->data;
                } elseif ($first !== false && $first !== '') {
                    $frames[] = (string) $first;
                }
                $firstClient->close();

                $secondClient = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $secondClient->set(['timeout' => 5]);
                $secondClient->setHeaders([
                    'X-Session-Id' => 'session-stable-1',
                    'X-Codex-Turn-State' => 'turn-2',
                ]);
                if (!$secondClient->upgrade('/v1/responses')) {
                    $frames[] = 'upgrade failed: ' . (string) $secondClient->errCode;
                    $secondClient->close();
                    return;
                }

                $secondClient->push('{"input":"hello 2"}');
                $second = $secondClient->recv(5);
                if (is_object($second) && property_exists($second, 'data')) {
                    $frames[] = (string) $second->data;
                } elseif ($second !== false && $second !== '') {
                    $frames[] = (string) $second;
                }
                $secondClient->close();
            });

            self::assertCount(2, $frames);
            self::assertStringContainsString('resp_ws_conn_1_msg_1', $frames[0]);
            self::assertStringContainsString('resp_ws_conn_1_msg_2', $frames[1]);

            $attempts = $this->waitForJsonLines($captureFile, 3);
            self::assertSame('open', $attempts[0]['event']);
            self::assertSame('message', $attempts[1]['event']);
            self::assertSame('message', $attempts[2]['event']);
            self::assertSame(1, $attempts[1]['connection_index']);
            self::assertSame(1, $attempts[2]['connection_index']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testNormalizesWebSocketResponseDoneToCompleted(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-done');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-websocket-request.jsonl';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-websocket-done-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $payload = null;
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$payload): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                if (!$client->upgrade('/v1/responses')) {
                    $payload = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"hello"}');
                $frame = $client->recv(5);
                $payload = is_object($frame) && property_exists($frame, 'data') ? (string) $frame->data : (string) $frame;
                $client->close();
            });

            self::assertNotNull($payload);
            self::assertStringContainsString('"type":"response.completed"', (string) $payload);
            self::assertStringNotContainsString('"type":"response.done"', (string) $payload);
            self::assertStringContainsString('resp_ws_done', (string) $payload);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    private function freePortOrSkip(): int
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $port = random_int(20_000, 60_000);
            $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);
            if ($socket === false) {
                continue;
            }
            fclose($socket);

            return $port;
        }

        self::markTestSkipped('Local TCP bind is not available in this environment');
    }

    private function waitForHttp(string $url, ?string $errorLog = null): void
    {
        $http = new GuzzleClient(['http_errors' => false, 'timeout' => 0.2]);
        $deadline = microtime(true) + 5.0;
        do {
            try {
                $response = $http->get($url);
                if ($response->getStatusCode() < 500) {
                    return;
                }
            } catch (\Throwable) {
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        $suffix = $errorLog !== null && is_file($errorLog) ? ': ' . (string) file_get_contents($errorLog) : '';
        self::fail('Timed out waiting for ' . $url . $suffix);
    }

    /** @return array<string,mixed> */
    private function waitForJsonFile(string $path): array
    {
        $deadline = microtime(true) + 5.0;
        do {
            if (is_file($path)) {
                $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                if (is_array($data)) {
                    return $data;
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail('Timed out waiting for JSON file: ' . $path);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function waitForJsonLines(string $path, int $count): array
    {
        $deadline = microtime(true) + 5.0;
        do {
            if (is_file($path)) {
                $rows = [];
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $data = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    if (is_array($data)) {
                        $rows[] = $data;
                    }
                }
                if (count($rows) >= $count) {
                    return $rows;
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail('Timed out waiting for JSON lines: ' . $path);
    }

    /** @return array<string,mixed> */
    private function waitForTrace(string $path, ?string $phase = null): array
    {
        $deadline = microtime(true) + 5.0;
        do {
            if (is_file($path)) {
                $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    if (!is_array($record)) {
                        continue;
                    }
                    $context = $record['context'] ?? null;
                    if (!is_array($context)) {
                        continue;
                    }
                    if ($phase !== null && ($context['phase'] ?? null) !== $phase) {
                        continue;
                    }

                    return $context;
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail('Timed out waiting for trace in: ' . $path . ($phase !== null ? ' phase=' . $phase : ''));
    }

    /** @param list<string> $command */
    private function startProcess(array $command, string $stderrFile): mixed
    {
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $stderrFile, 'a'],
            2 => ['file', $stderrFile, 'a'],
        ], $pipes);
        if (!is_resource($process)) {
            self::fail('Failed to start process: ' . implode(' ', $command));
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return $process;
    }

    private function stopProcess(mixed $process): void
    {
        if (!is_resource($process)) {
            return;
        }

        proc_terminate($process, SIGTERM);
        proc_close($process);
    }
}
