<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Auth;

use CodexAuthProxy\Account\AccountFileValidator;
use CodexAuthProxy\Auth\TokenRefresher;
use CodexAuthProxy\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;

final class TokenRefresherTest extends TestCase
{
    public function testRefreshesExpiringAccountToken(): void
    {
        $old = $this->accountFixture('alpha', [
            'tokens' => [
                'access_token' => $this->makeJwt(['exp' => time() - 60]),
            ],
        ]);
        $new = $this->accountFixture('alpha');
        $account = (new AccountFileValidator())->validate($old);
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id_token' => $new['tokens']['id_token'],
                'access_token' => $new['tokens']['access_token'],
                'refresh_token' => $new['tokens']['refresh_token'],
            ], JSON_THROW_ON_ERROR)),
        ]))]);

        $refreshed = (new TokenRefresher($http))->refreshIfExpiring($account, 300);

        self::assertNotNull($refreshed);
        self::assertSame($new['tokens']['access_token'], $refreshed->accessToken());
    }

    public function testRefreshUsesConfiguredGuzzleProxy(): void
    {
        $old = $this->accountFixture('alpha', [
            'tokens' => [
                'access_token' => $this->makeJwt(['exp' => time() - 60]),
            ],
        ]);
        $new = $this->accountFixture('alpha');
        $account = (new AccountFileValidator())->validate($old);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id_token' => $new['tokens']['id_token'],
                'access_token' => $new['tokens']['access_token'],
                'refresh_token' => $new['tokens']['refresh_token'],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack]);

        (new TokenRefresher($http, ['https' => 'http://proxy.local:8443']))->refresh($account);

        self::assertSame('http://proxy.local:8443', $history[0]['options']['proxy']['https']);
    }

    public function testKeepsFreshAccountToken(): void
    {
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));

        $refreshed = (new TokenRefresher(new Client()))->refreshIfExpiring($account, 300);

        self::assertNull($refreshed);
    }

    public function testRejectsRefreshTokensForDifferentChatGptAccount(): void
    {
        $old = $this->accountFixture('alpha', [
            'tokens' => [
                'access_token' => $this->makeJwt(['exp' => time() - 60]),
            ],
        ]);
        $new = $this->accountFixture('beta');
        $account = (new AccountFileValidator())->validate($old);
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id_token' => $new['tokens']['id_token'],
                'access_token' => $new['tokens']['access_token'],
                'refresh_token' => $new['tokens']['refresh_token'],
            ], JSON_THROW_ON_ERROR)),
        ]))]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('account id');

        (new TokenRefresher($http))->refreshIfExpiring($account, 300);
    }
}
