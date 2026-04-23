<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Usage;

use CodexAuthProxy\Account\AccountFileValidator;
use CodexAuthProxy\Tests\TestCase;
use CodexAuthProxy\Usage\CodexUsageClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use RuntimeException;

final class CodexUsageClientTest extends TestCase
{
    public function testFetchesRateLimitsThroughDirectUsageEndpoint(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'plan_type' => 'plus',
                'rate_limit' => [
                    'primary_window' => [
                        'used_percent' => 93.0,
                        'limit_window_seconds' => 18_000,
                        'reset_at' => 1_776_756_600,
                    ],
                    'secondary_window' => [
                        'used_percent' => 15.0,
                        'limit_window_seconds' => 604_800,
                        'reset_at' => 1_777_338_600,
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));

        $usage = (new CodexUsageClient(
            'https://chatgpt.com/backend-api',
            timeoutSeconds: 2,
            http: new Client(['handler' => $stack]),
            originator: 'codex-originator-test',
            userAgent: 'codex-user-agent-test',
            residency: 'global',
        ))->fetch($account);

        self::assertSame('plus', $usage->planType);
        self::assertSame(93.0, $usage->primary?->usedPercent);
        self::assertSame(15.0, $usage->secondary?->usedPercent);
        self::assertCount(1, $history);
        /** @var array{request:Request,options:array<string,mixed>} $transaction */
        $transaction = $history[0];
        $request = $transaction['request'];
        self::assertSame('https://chatgpt.com/backend-api/wham/usage', (string) $request->getUri());
        self::assertSame('Bearer ' . $account->accessToken(), $request->getHeaderLine('Authorization'));
        self::assertSame('acct-alpha', $request->getHeaderLine('ChatGPT-Account-ID'));
        self::assertSame('codex-originator-test', $request->getHeaderLine('originator'));
        self::assertSame('codex-user-agent-test', $request->getHeaderLine('User-Agent'));
        self::assertSame('global', $request->getHeaderLine('x-openai-internal-codex-residency'));
        self::assertSame([], $transaction['options']['proxy'] ?? null);
    }

    public function testTranslatesConfiguredProxyEnvironmentIntoGuzzleProxyOptions(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'rateLimits' => [
                    'primary' => ['usedPercent' => 10.0, 'windowDurationMins' => 300],
                    'planType' => 'plus',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));

        (new CodexUsageClient(
            'https://chatgpt.com/backend-api',
            timeoutSeconds: 2,
            proxyEnv: [
                'HTTP_PROXY' => 'http://proxy.local:8080',
                'HTTPS_PROXY' => 'http://secure-proxy.local:8443',
                'NO_PROXY' => 'localhost,127.0.0.1',
            ],
            http: new Client(['handler' => $stack]),
        ))->fetch($account);

        /** @var array{options:array<string,mixed>} $transaction */
        $transaction = $history[0];
        self::assertSame([
            'http' => 'http://proxy.local:8080',
            'https' => 'http://secure-proxy.local:8443',
            'no' => ['localhost', '127.0.0.1'],
        ], $transaction['options']['proxy'] ?? null);
    }

    public function testFallsBackToHttpProxyForHttpsUsageEndpoint(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'rateLimits' => [
                    'primary' => ['usedPercent' => 10.0, 'windowDurationMins' => 300],
                    'planType' => 'plus',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));

        (new CodexUsageClient(
            'https://chatgpt.com/backend-api',
            timeoutSeconds: 2,
            proxyEnv: [
                'HTTP_PROXY' => 'http://proxy.local:8080',
                'NO_PROXY' => 'localhost',
            ],
            http: new Client(['handler' => $stack]),
        ))->fetch($account);

        /** @var array{options:array<string,mixed>} $transaction */
        $transaction = $history[0];
        self::assertSame([
            'http' => 'http://proxy.local:8080',
            'https' => 'http://proxy.local:8080',
            'no' => ['localhost'],
        ], $transaction['options']['proxy'] ?? null);
    }

    public function testDoesNotReadParentProcessProxyEnvironment(): void
    {
        $snapshot = [
            'HTTP_PROXY' => getenv('HTTP_PROXY') === false ? null : getenv('HTTP_PROXY'),
            'HTTPS_PROXY' => getenv('HTTPS_PROXY') === false ? null : getenv('HTTPS_PROXY'),
            'ALL_PROXY' => getenv('ALL_PROXY') === false ? null : getenv('ALL_PROXY'),
            'http_proxy' => getenv('http_proxy') === false ? null : getenv('http_proxy'),
            'https_proxy' => getenv('https_proxy') === false ? null : getenv('https_proxy'),
            'all_proxy' => getenv('all_proxy') === false ? null : getenv('all_proxy'),
        ];

        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'rateLimits' => [
                    'primary' => ['usedPercent' => 10.0, 'windowDurationMins' => 300],
                    'planType' => 'plus',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));

        try {
            foreach (array_keys($snapshot) as $name) {
                putenv($name . '=http://standard-proxy.local:8080');
            }

            (new CodexUsageClient(
                'https://chatgpt.com/backend-api',
                timeoutSeconds: 2,
                proxyEnv: ['NO_PROXY' => 'localhost'],
                http: new Client(['handler' => $stack]),
            ))->fetch($account);
        } finally {
            foreach ($snapshot as $name => $value) {
                if ($value === null) {
                    putenv($name);
                    continue;
                }
                putenv($name . '=' . $value);
            }
        }

        /** @var array{options:array<string,mixed>} $transaction */
        $transaction = $history[0];
        self::assertSame(['no' => ['localhost']], $transaction['options']['proxy'] ?? null);
    }

    public function testFallsBackToDefaultUsageBaseUrlWhenBaseUrlIsNotUrl(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'rateLimits' => [
                    'primary' => ['usedPercent' => 10.0, 'windowDurationMins' => 300],
                    'planType' => 'plus',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));

        (new CodexUsageClient('/usr/bin/codex', http: new Client(['handler' => $stack])))->fetch($account);

        /** @var array{request:Request} $transaction */
        $transaction = $history[0];
        self::assertSame('https://chatgpt.com/backend-api/wham/usage', (string) $transaction['request']->getUri());
    }

    public function testUsesCodexUsageEndpointForNonChatGptBaseUrl(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'rateLimits' => [
                    'primary' => ['usedPercent' => 10.0, 'windowDurationMins' => 300],
                    'planType' => 'plus',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));

        (new CodexUsageClient(
            baseUrl: 'https://proxy.example.test',
            http: new Client(['handler' => $stack]),
        ))->fetch($account);

        /** @var array{request:Request} $transaction */
        $transaction = $history[0];
        self::assertSame('https://proxy.example.test/api/codex/usage', (string) $transaction['request']->getUri());
    }

    public function testSummarizesHttpFailures(): void
    {
        $http = new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(401, ['Content-Type' => 'application/json', 'x-request-id' => 'req-1'], json_encode([
                    'error' => [
                        'code' => 'token_invalidated',
                        'message' => 'Your authentication token has been invalidated.',
                    ],
                ], JSON_THROW_ON_ERROR)),
            ])),
        ]);
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('usage endpoint returned HTTP 401');
        $this->expectExceptionMessage('token_invalidated');
        $this->expectExceptionMessage('req-1');

        (new CodexUsageClient(http: $http))->fetch($account);
    }

    public function testWrapsTransportFailures(): void
    {
        $http = new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new RequestException('network down', new Request('GET', 'https://chatgpt.com/backend-api/wham/usage')),
            ])),
        ]);
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('usage endpoint request failed: network down');

        (new CodexUsageClient(http: $http))->fetch($account);
    }
}
