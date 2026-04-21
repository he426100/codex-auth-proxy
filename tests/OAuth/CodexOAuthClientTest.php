<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\OAuth;

use CodexAuthProxy\OAuth\CodexOAuthHttpClient;
use CodexAuthProxy\OAuth\PkcePair;
use CodexAuthProxy\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

final class CodexOAuthClientTest extends TestCase
{
    public function testBuildsCodexPkceAuthorizationUrl(): void
    {
        $client = new CodexOAuthHttpClient(new Client());
        $url = $client->authorizationUrl('state-1', new PkcePair('verifier-1', 'challenge-1'), 'http://127.0.0.1:1455/auth/callback');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('https', parse_url($url, PHP_URL_SCHEME));
        self::assertSame('auth.openai.com', parse_url($url, PHP_URL_HOST));
        self::assertSame('/oauth/authorize', parse_url($url, PHP_URL_PATH));
        self::assertSame('app_EMoamEEZ73f0CkXaXp7hrann', $query['client_id']);
        self::assertSame('code', $query['response_type']);
        self::assertSame('http://127.0.0.1:1455/auth/callback', $query['redirect_uri']);
        self::assertSame('openid email profile offline_access', $query['scope']);
        self::assertSame('state-1', $query['state']);
        self::assertSame('challenge-1', $query['code_challenge']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('login', $query['prompt']);
        self::assertSame('true', $query['id_token_add_organizations']);
        self::assertSame('true', $query['codex_cli_simplified_flow']);
    }

    public function testExchangesAuthorizationCodeForTokensWithGuzzle(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id_token' => 'id-token',
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
            ], JSON_THROW_ON_ERROR)),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $client = new CodexOAuthHttpClient(new Client(['handler' => $stack]));

        $tokens = $client->exchangeCode('code-1', new PkcePair('verifier-1', 'challenge-1'), 'http://127.0.0.1:1455/auth/callback');

        self::assertSame([
            'id_token' => 'id-token',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
        ], $tokens);
        self::assertCount(1, $history);

        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        parse_str((string) $request->getBody(), $form);

        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://auth.openai.com/oauth/token', (string) $request->getUri());
        self::assertSame('authorization_code', $form['grant_type']);
        self::assertSame('app_EMoamEEZ73f0CkXaXp7hrann', $form['client_id']);
        self::assertSame('code-1', $form['code']);
        self::assertSame('verifier-1', $form['code_verifier']);
    }

    public function testExchangeCodeUsesConfiguredGuzzleProxy(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id_token' => 'id-token',
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
            ], JSON_THROW_ON_ERROR)),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $client = new CodexOAuthHttpClient(new Client(['handler' => $stack]), ['https' => 'http://proxy.local:8443']);

        $client->exchangeCode('code-1', new PkcePair('verifier-1', 'challenge-1'), 'http://127.0.0.1:1455/auth/callback');

        self::assertSame('http://proxy.local:8443', $history[0]['options']['proxy']['https']);
    }
}
