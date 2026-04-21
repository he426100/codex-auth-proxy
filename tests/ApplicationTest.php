<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests;

use CodexAuthProxy\Console\Application;
use CodexAuthProxy\OAuth\AuthorizationCode;
use CodexAuthProxy\OAuth\CallbackServer;
use CodexAuthProxy\OAuth\CodexOAuthClient;
use CodexAuthProxy\OAuth\PkcePair;
use Symfony\Component\Console\Tester\CommandTester;

final class ApplicationTest extends TestCase
{
    public function testPrintsMinimalCodexConfigSnippet(): void
    {
        $application = new Application('/tmp/cap-home');
        $tester = new CommandTester($application->find('config'));

        $code = $tester->execute(['--port' => '1777']);

        self::assertSame(0, $code);
        self::assertStringContainsString('openai_base_url = "http://127.0.0.1:1777/v1"', $tester->getDisplay());
    }

    public function testImportsAccountFileThroughCliApplication(): void
    {
        $home = $this->tempDir('cap-home');
        $source = $home . '/auth.json';
        $this->writeJson($source, [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('alpha')['tokens'],
        ]);

        $application = new Application($home);
        $tester = new CommandTester($application->find('import'));
        $code = $tester->execute(['name' => 'alpha', '--from' => $source]);

        self::assertSame(0, $code);
        self::assertStringContainsString('alpha.account.json', $tester->getDisplay());
        self::assertFileExists($home . '/.config/codex-auth-proxy/accounts/alpha.account.json');
    }

    public function testLoginSavesOAuthTokensAsProxyAccountFile(): void
    {
        $home = $this->tempDir('cap-home');
        $tokens = $this->accountFixture('alpha')['tokens'];
        unset($tokens['account_id']);

        $oauthClient = new FakeOAuthClient($tokens);
        $callbackServer = new FakeCallbackServer('code-alpha');
        $application = new Application($home, oauthClient: $oauthClient, callbackServer: $callbackServer);
        $tester = new CommandTester($application->find('login'));

        $code = $tester->execute(['name' => 'alpha', '--callback-port' => '1455']);

        self::assertSame(0, $code);
        self::assertSame('http://127.0.0.1:1455/auth/callback', $oauthClient->redirectUri);
        self::assertSame('code-alpha', $oauthClient->code);
        self::assertFileExists($home . '/.config/codex-auth-proxy/accounts/alpha.account.json');
        self::assertStringContainsString('Open this URL', $tester->getDisplay());
    }
}

final class FakeOAuthClient implements CodexOAuthClient
{
    public string $redirectUri = '';
    public string $code = '';

    /** @param array<string,string> $tokens */
    public function __construct(private readonly array $tokens)
    {
    }

    public function authorizationUrl(string $state, PkcePair $pkce, string $redirectUri): string
    {
        $this->redirectUri = $redirectUri;

        return 'https://auth.openai.com/oauth/authorize?state=' . rawurlencode($state);
    }

    /** @return array{id_token:string,access_token:string,refresh_token:string} */
    public function exchangeCode(string $code, PkcePair $pkce, string $redirectUri): array
    {
        $this->code = $code;
        $this->redirectUri = $redirectUri;

        return [
            'id_token' => $this->tokens['id_token'],
            'access_token' => $this->tokens['access_token'],
            'refresh_token' => $this->tokens['refresh_token'],
        ];
    }
}

final class FakeCallbackServer implements CallbackServer
{
    public function __construct(private readonly string $code)
    {
    }

    public function waitForCode(string $host, int $port, string $path, string $expectedState, int $timeoutSeconds): AuthorizationCode
    {
        return new AuthorizationCode($this->code, $expectedState);
    }
}
