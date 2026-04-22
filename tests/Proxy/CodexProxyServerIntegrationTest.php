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
            $trace = $this->waitForTrace($home . '/traces');
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
    private function waitForTrace(string $dir): array
    {
        $deadline = microtime(true) + 5.0;
        do {
            $files = glob($dir . '/*.json') ?: [];
            if ($files !== []) {
                $data = json_decode((string) file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
                if (is_array($data)) {
                    return $data;
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail('Timed out waiting for trace in: ' . $dir);
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
