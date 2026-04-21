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

    public function testImportsAccountFileWithInferredNameWhenArgumentIsOmitted(): void
    {
        $home = $this->tempDir('cap-home');
        $source = $home . '/auth.json';
        $this->writeJson($source, [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('alpha')['tokens'],
        ]);

        $application = new Application($home);
        $tester = new CommandTester($application->find('import'));
        $code = $tester->execute(['--from' => $source]);

        self::assertSame(0, $code);
        self::assertStringContainsString('alpha-example.com.account.json', $tester->getDisplay());
        self::assertFileExists($home . '/.config/codex-auth-proxy/accounts/alpha-example.com.account.json');
    }

    public function testImportsDefaultCodexAuthJsonWhenFromOptionIsOmitted(): void
    {
        $home = $this->tempDir('cap-home');
        $source = $home . '/.codex/auth.json';
        if (!mkdir(dirname($source), 0700, true) && !is_dir(dirname($source))) {
            self::fail('Failed to create Codex home fixture');
        }
        $this->writeJson($source, [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('alpha')['tokens'],
        ]);

        $application = new Application($home);
        $tester = new CommandTester($application->find('import'));
        $code = $tester->execute([]);

        self::assertSame(0, $code);
        self::assertFileExists($home . '/.config/codex-auth-proxy/accounts/alpha-example.com.account.json');
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
        self::assertSame('http://localhost:1455/auth/callback', $oauthClient->redirectUri);
        self::assertSame('code-alpha', $oauthClient->code);
        self::assertFileExists($home . '/.config/codex-auth-proxy/accounts/alpha.account.json');
        self::assertStringContainsString('Open this URL', $tester->getDisplay());
    }

    public function testLoginSavesOAuthTokensWithInferredNameWhenArgumentIsOmitted(): void
    {
        $home = $this->tempDir('cap-home');
        $tokens = $this->accountFixture('alpha')['tokens'];
        unset($tokens['account_id']);

        $oauthClient = new FakeOAuthClient($tokens);
        $callbackServer = new FakeCallbackServer('code-alpha');
        $application = new Application($home, oauthClient: $oauthClient, callbackServer: $callbackServer);
        $tester = new CommandTester($application->find('login'));

        $code = $tester->execute(['--callback-port' => '1455']);

        self::assertSame(0, $code);
        self::assertFileExists($home . '/.config/codex-auth-proxy/accounts/alpha-example.com.account.json');
    }

    public function testExportsConfigTomlFromDefaultCodexConfig(): void
    {
        $home = $this->tempDir('cap-home');
        $codexConfig = $home . '/.codex/config.toml';
        if (!mkdir(dirname($codexConfig), 0700, true) && !is_dir(dirname($codexConfig))) {
            self::fail('Failed to create Codex config fixture');
        }
        file_put_contents($codexConfig, "openai_base_url = \"https://api.openai.com/v1\"\n\n[projects.demo]\ntrust_level = \"trusted\"\n");

        $application = new Application($home);
        $tester = new CommandTester($application->find('export'));
        $code = $tester->execute(['target' => 'config', '--port' => '1777']);

        self::assertSame(0, $code);
        $exported = (string) file_get_contents($home . '/.config/codex-auth-proxy/config.toml');
        self::assertStringStartsWith("openai_base_url = \"http://127.0.0.1:1777/v1\"\n", $exported);
        self::assertStringContainsString("[projects.demo]\ntrust_level = \"trusted\"", $exported);
    }

    public function testExportsAuthJsonForSelectedProxyAccount(): void
    {
        $home = $this->tempDir('cap-home');
        $application = new Application($home);
        $importer = new CommandTester($application->find('import'));
        $source = $home . '/auth.json';
        $this->writeJson($source, [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('alpha')['tokens'],
        ]);
        $importer->execute(['name' => 'alpha', '--from' => $source]);

        $tester = new CommandTester($application->find('export'));
        $code = $tester->execute(['target' => 'auth', 'name' => 'alpha']);

        self::assertSame(0, $code);
        $exported = json_decode((string) file_get_contents($home . '/.config/codex-auth-proxy/auth.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('chatgpt', $exported['auth_mode']);
        self::assertSame($this->accountFixture('alpha')['tokens']['access_token'], $exported['tokens']['access_token']);
    }

    public function testApplyExportsBacksUpAndOverwritesCodexFilesAfterConfirmation(): void
    {
        $home = $this->tempDir('cap-home');
        $codexDir = $home . '/.codex';
        if (!mkdir($codexDir, 0700, true) && !is_dir($codexDir)) {
            self::fail('Failed to create Codex home fixture');
        }
        file_put_contents($codexDir . '/config.toml', "[mcp_servers.demo]\ncommand = \"demo\"\n");
        $this->writeJson($codexDir . '/auth.json', [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('beta')['tokens'],
        ]);

        $application = new Application($home);
        $source = $home . '/alpha-auth.json';
        $this->writeJson($source, [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('alpha')['tokens'],
        ]);
        (new CommandTester($application->find('import')))->execute(['name' => 'alpha', '--from' => $source]);

        $tester = new CommandTester($application->find('export'));
        $tester->setInputs(['yes']);
        $code = $tester->execute(['target' => 'all', 'name' => 'alpha', '--apply' => true]);

        self::assertSame(0, $code);
        self::assertStringStartsWith('openai_base_url = "http://127.0.0.1:1456/v1"', (string) file_get_contents($codexDir . '/config.toml'));
        $auth = json_decode((string) file_get_contents($codexDir . '/auth.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($this->accountFixture('alpha')['tokens']['access_token'], $auth['tokens']['access_token']);
        self::assertCount(1, glob($codexDir . '/config.toml.bak.*') ?: []);
        self::assertCount(1, glob($codexDir . '/auth.json.bak.*') ?: []);
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
