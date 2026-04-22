<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests;

use CodexAuthProxy\Console\Application;
use CodexAuthProxy\OAuth\AuthorizationCode;
use CodexAuthProxy\OAuth\CallbackServer;
use CodexAuthProxy\OAuth\CodexOAuthClient;
use CodexAuthProxy\OAuth\PkcePair;
use CodexAuthProxy\Auth\TokenRefresher;
use CodexAuthProxy\Config\AppConfigLoader;
use CodexAuthProxy\Console\Command\AccountsCommand;
use CodexAuthProxy\Usage\AccountUsage;
use CodexAuthProxy\Usage\RateLimitWindow;
use CodexAuthProxy\Usage\UsageClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

final class ApplicationTest extends TestCase
{
    public function testPrintsMinimalCodexConfigSnippet(): void
    {
        $application = new Application('/tmp/cap-home');
        $tester = new CommandTester($application->find('config'));

        $code = $tester->execute(['--port' => '1777']);

        self::assertSame(0, $code);
        self::assertStringContainsString('model_provider = "openai"', $tester->getDisplay());
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
        self::assertStringStartsWith("model_provider = \"openai\"\nopenai_base_url = \"http://127.0.0.1:1777/v1\"\n", $exported);
        self::assertStringContainsString("[projects.demo]\ntrust_level = \"trusted\"", $exported);
    }

    public function testExportsConfigTomlReplacesRootOpenAiBaseUrlWithoutDuplicatingKeys(): void
    {
        $home = $this->tempDir('cap-home');
        $codexConfig = $home . '/.codex/config.toml';
        if (!mkdir(dirname($codexConfig), 0700, true) && !is_dir(dirname($codexConfig))) {
            self::fail('Failed to create Codex config fixture');
        }
        file_put_contents($codexConfig, "# existing root config\nmodel = \"gpt-5.4\"\nopenai_base_url = \"https://api.openai.com/v1\"\n\n[projects.demo]\ntrust_level = \"trusted\"\n");

        $application = new Application($home);
        $tester = new CommandTester($application->find('export'));
        $code = $tester->execute(['target' => 'config', '--port' => '1777']);

        self::assertSame(0, $code);
        $exported = (string) file_get_contents($home . '/.config/codex-auth-proxy/config.toml');
        self::assertSame(1, substr_count($exported, 'model_provider'));
        self::assertSame(1, substr_count($exported, 'openai_base_url'));
        self::assertStringContainsString("model = \"gpt-5.4\"\nmodel_provider = \"openai\"\nopenai_base_url = \"http://127.0.0.1:1777/v1\"", $exported);
        self::assertStringContainsString("[projects.demo]\ntrust_level = \"trusted\"", $exported);
    }

    public function testExportsConfigTomlReplacesRootModelProviderWithBuiltinOpenai(): void
    {
        $home = $this->tempDir('cap-home');
        $codexConfig = $home . '/.codex/config.toml';
        if (!mkdir(dirname($codexConfig), 0700, true) && !is_dir(dirname($codexConfig))) {
            self::fail('Failed to create Codex config fixture');
        }
        file_put_contents($codexConfig, "model_provider = \"OpenAI\"\nopenai_base_url = \"https://icoe.pp.ua\"\n\n[model_providers.OpenAI]\nname = \"OpenAI\"\nbase_url = \"https://icoe.pp.ua\"\nrequires_openai_auth = true\n");

        $application = new Application($home);
        $tester = new CommandTester($application->find('export'));
        $code = $tester->execute(['target' => 'config', '--port' => '1777']);

        self::assertSame(0, $code);
        $exported = (string) file_get_contents($home . '/.config/codex-auth-proxy/config.toml');
        self::assertSame(1, substr_count($exported, 'model_provider = "openai"'));
        self::assertStringNotContainsString("model_provider = \"OpenAI\"", $exported);
        self::assertStringContainsString("[model_providers.OpenAI]\nname = \"OpenAI\"", $exported);
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
        self::assertArrayHasKey('OPENAI_API_KEY', $exported);
        self::assertNull($exported['OPENAI_API_KEY']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{9}Z$/', $exported['last_refresh']);
        self::assertSame($this->accountFixture('alpha')['tokens']['access_token'], $exported['tokens']['access_token']);
    }

    public function testApplyExportsOnlyBacksUpAndOverwritesCodexConfigAfterConfirmation(): void
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
        self::assertStringStartsWith("model_provider = \"openai\"\nopenai_base_url = \"http://127.0.0.1:1456/v1\"", (string) file_get_contents($codexDir . '/config.toml'));
        $codexAuth = json_decode((string) file_get_contents($codexDir . '/auth.json'), true, flags: JSON_THROW_ON_ERROR);
        $proxyAuth = json_decode((string) file_get_contents($home . '/.config/codex-auth-proxy/auth.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($this->accountFixture('beta')['tokens']['access_token'], $codexAuth['tokens']['access_token']);
        self::assertSame($this->accountFixture('alpha')['tokens']['access_token'], $proxyAuth['tokens']['access_token']);
        self::assertCount(1, glob($codexDir . '/config.toml.bak.*') ?: []);
        self::assertSame([], glob($codexDir . '/auth.json.bak.*') ?: []);
    }

    public function testAccountsListShowsPlanType(): void
    {
        $home = $this->tempDir('cap-home');
        $source = $home . '/auth.json';
        $this->writeJson($source, [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('alpha')['tokens'],
        ]);

        mkdir($home . '/.config/codex-auth-proxy', 0700, true);
        $this->writeJson($home . '/.config/codex-auth-proxy/state.json', [
            'accounts' => [
                'acct-alpha' => [
                    'cooldown_until' => 1776756600,
                ],
            ],
            'usage' => [
                'acct-alpha' => [
                    'plan_type' => 'plus',
                    'checked_at' => 1776753000,
                    'error' => 'stale cache',
                    'primary' => [
                        'used_percent' => 93.0,
                        'left_percent' => 7.0,
                        'window_minutes' => 300,
                        'resets_at' => 1776756600,
                    ],
                    'secondary' => [
                        'used_percent' => 15.0,
                        'left_percent' => 85.0,
                        'window_minutes' => 10080,
                        'resets_at' => 1777338600,
                    ],
                ],
            ],
        ]);

        $usageClient = new CountingUsageClient();
        $application = new Application($home, usageClient: $usageClient);
        (new CommandTester($application->find('import')))->execute(['name' => 'alpha', '--from' => $source]);

        $tester = new CommandTester($application->find('accounts'));
        $code = $tester->execute([]);

        self::assertSame(0, $code);
        self::assertSame(0, $usageClient->calls);
        self::assertStringContainsString('alpha', $tester->getDisplay());
        self::assertStringContainsString('alpha@example.com', $tester->getDisplay());
        self::assertStringContainsString('Plus', $tester->getDisplay());
        self::assertStringContainsString('7%', $tester->getDisplay());
        self::assertStringContainsString('85%', $tester->getDisplay());
        self::assertStringContainsString('yes', $tester->getDisplay());
        self::assertStringContainsString('stale cache', $tester->getDisplay());
    }

    public function testAccountsListWithoutUsageCacheFallsBackToAccountMetadata(): void
    {
        $home = $this->tempDir('cap-home');
        $source = $home . '/auth.json';
        $this->writeJson($source, [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('alpha')['tokens'],
        ]);

        $application = new Application($home);
        (new CommandTester($application->find('import')))->execute(['name' => 'alpha', '--from' => $source]);

        $tester = new CommandTester($application->find('accounts'));
        $code = $tester->execute([]);

        self::assertSame(0, $code);
        self::assertStringContainsString('alpha', $tester->getDisplay());
        self::assertStringContainsString('alpha@example.com', $tester->getDisplay());
        self::assertStringContainsString('Plus', $tester->getDisplay());
    }

    public function testAccountsListJsonUsesStructuredFields(): void
    {
        $home = $this->tempDir('cap-home');
        $source = $home . '/auth.json';
        $this->writeJson($source, [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('alpha')['tokens'],
        ]);

        mkdir($home . '/.config/codex-auth-proxy', 0700, true);
        $this->writeJson($home . '/.config/codex-auth-proxy/state.json', [
            'accounts' => [
                'acct-alpha' => [
                    'cooldown_until' => 1776756600,
                ],
            ],
            'usage' => [
                'acct-alpha' => [
                    'plan_type' => 'plus',
                    'checked_at' => 1776753000,
                    'error' => 'cached failure',
                    'primary' => [
                        'used_percent' => 93.0,
                        'left_percent' => 7.0,
                        'window_minutes' => 300,
                        'resets_at' => 1776756600,
                    ],
                    'secondary' => [
                        'used_percent' => 15.0,
                        'left_percent' => 85.0,
                        'window_minutes' => 10080,
                        'resets_at' => 1777338600,
                    ],
                ],
            ],
        ]);

        $application = new Application($home);
        (new CommandTester($application->find('import')))->execute(['name' => 'alpha', '--from' => $source]);

        $tester = new CommandTester($application->find('accounts'));
        $code = $tester->execute(['--json' => true]);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $code);
        self::assertIsArray($payload);
        self::assertCount(1, $payload);
        self::assertTrue($payload[0]['enabled']);
        self::assertSame(1776756600, $payload[0]['cooldown_until']);
        self::assertEquals(7.0, $payload[0]['primary_left_percent']);
        self::assertSame('ok', $payload[0]['availability_reason']);
        self::assertSame('cached failure', $payload[0]['error']);
        self::assertIsBool($payload[0]['enabled']);
        self::assertIsInt($payload[0]['cooldown_until']);
        self::assertTrue(is_int($payload[0]['primary_left_percent']) || is_float($payload[0]['primary_left_percent']));
        self::assertIsString($payload[0]['availability_reason']);
        self::assertIsString($payload[0]['error']);
        self::assertNotSame('yes', $payload[0]['enabled']);
        self::assertNotSame('7%', $payload[0]['primary_left_percent']);
    }

    public function testAccountsBindingsShowsSessionMappingsAndCooldownReason(): void
    {
        $home = $this->tempDir('cap-home');
        $alphaSource = $home . '/alpha.json';
        $betaSource = $home . '/beta.json';
        $cooldownUntil = time() + 1800;
        $this->writeJson($alphaSource, [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('alpha')['tokens'],
        ]);
        $this->writeJson($betaSource, [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('beta')['tokens'],
        ]);

        mkdir($home . '/.config/codex-auth-proxy', 0700, true);
        $this->writeJson($home . '/.config/codex-auth-proxy/state.json', [
            'accounts' => [
                'acct-alpha' => [
                    'cooldown_until' => $cooldownUntil,
                    'cooldown_reason' => 'auth',
                ],
            ],
            'sessions' => [
                'thread-1' => 'acct-alpha',
                'thread-2' => 'acct-beta',
            ],
            'cursor' => 0,
            'usage' => [
                'acct-alpha' => [
                    'plan_type' => 'plus',
                    'checked_at' => time(),
                    'error' => null,
                    'primary' => [
                        'used_percent' => 93.0,
                        'left_percent' => 7.0,
                        'window_minutes' => 300,
                        'resets_at' => $cooldownUntil,
                    ],
                    'secondary' => [
                        'used_percent' => 15.0,
                        'left_percent' => 85.0,
                        'window_minutes' => 10080,
                        'resets_at' => time() + 86_400,
                    ],
                ],
            ],
        ]);

        $application = new Application($home);
        (new CommandTester($application->find('import')))->execute(['name' => 'alpha', '--from' => $alphaSource]);
        (new CommandTester($application->find('import')))->execute(['name' => 'beta', '--from' => $betaSource]);

        $tester = new CommandTester($application->find('accounts'));
        $code = $tester->execute(['action' => 'bindings']);

        self::assertSame(0, $code);
        self::assertStringContainsString('thread-1', $tester->getDisplay());
        self::assertStringContainsString('thread-2', $tester->getDisplay());
        self::assertStringContainsString('alpha', $tester->getDisplay());
        self::assertStringContainsString('beta', $tester->getDisplay());
        self::assertStringContainsString('alpha@example.com', $tester->getDisplay());
        self::assertStringContainsString('cooldown (auth)', $tester->getDisplay());
    }

    public function testAccountsBindingsJsonUsesStructuredFieldsAndSessionFilter(): void
    {
        $home = $this->tempDir('cap-home');
        $source = $home . '/alpha.json';
        $cooldownUntil = time() + 1800;
        $this->writeJson($source, [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('alpha')['tokens'],
        ]);

        mkdir($home . '/.config/codex-auth-proxy', 0700, true);
        $this->writeJson($home . '/.config/codex-auth-proxy/state.json', [
            'accounts' => [
                'acct-alpha' => [
                    'cooldown_until' => $cooldownUntil,
                    'cooldown_reason' => 'auth',
                ],
            ],
            'sessions' => [
                'thread-1' => 'acct-alpha',
            ],
            'cursor' => 0,
            'usage' => [
                'acct-alpha' => [
                    'plan_type' => 'plus',
                    'checked_at' => time(),
                    'error' => null,
                    'primary' => [
                        'used_percent' => 93.0,
                        'left_percent' => 7.0,
                        'window_minutes' => 300,
                        'resets_at' => $cooldownUntil,
                    ],
                    'secondary' => [
                        'used_percent' => 15.0,
                        'left_percent' => 85.0,
                        'window_minutes' => 10080,
                        'resets_at' => time() + 86_400,
                    ],
                ],
            ],
        ]);

        $application = new Application($home);
        (new CommandTester($application->find('import')))->execute(['name' => 'alpha', '--from' => $source]);

        $tester = new CommandTester($application->find('accounts'));
        $code = $tester->execute(['action' => 'bindings', 'name' => 'thread-1', '--json' => true]);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $code);
        self::assertIsArray($payload);
        self::assertCount(1, $payload);
        self::assertSame('thread-1', $payload[0]['session_key']);
        self::assertSame('acct-alpha', $payload[0]['account_id']);
        self::assertSame('alpha', $payload[0]['account_name']);
        self::assertSame('alpha@example.com', $payload[0]['email']);
        self::assertSame('plus', $payload[0]['plan_type']);
        self::assertSame($cooldownUntil, $payload[0]['cooldown_until']);
        self::assertSame('auth', $payload[0]['cooldown_reason']);
        self::assertSame('cooldown', $payload[0]['availability_reason']);
        self::assertFalse($payload[0]['routable']);
    }

    public function testAccountsStatusShowsAccountPlanAndQuota(): void
    {
        $home = $this->tempDir('cap-home');
        $source = $home . '/auth.json';
        $this->writeJson($source, [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('alpha')['tokens'],
        ]);

        $usageClient = new FakeUsageClient(new AccountUsage(
            'plus',
            new RateLimitWindow(93.0, 300, 1776756600),
            new RateLimitWindow(15.0, 10080, 1777338600),
        ));
        $application = new Application($home, usageClient: $usageClient);
        (new CommandTester($application->find('import')))->execute(['name' => 'alpha', '--from' => $source]);

        $tester = new CommandTester($application->find('accounts'));
        $code = $tester->execute(['action' => 'status', 'name' => 'alpha']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Account: alpha@example.com (Plus)', $tester->getDisplay());
        self::assertStringContainsString('5h limit: 7% left', $tester->getDisplay());
        self::assertStringContainsString('Weekly limit: 85% left', $tester->getDisplay());

        $state = json_decode((string) file_get_contents($home . '/.config/codex-auth-proxy/state.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('plus', $state['usage']['acct-alpha']['plan_type']);
        self::assertEquals(7.0, $state['usage']['acct-alpha']['primary']['left_percent']);
        self::assertNull($state['usage']['acct-alpha']['error']);
    }

    public function testAccountsRefreshWritesUsageCacheAndErrorMetadata(): void
    {
        $home = $this->tempDir('cap-home');
        $alphaSource = $home . '/alpha.json';
        $betaSource = $home . '/beta.json';
        $this->writeJson($alphaSource, [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('alpha')['tokens'],
        ]);
        $this->writeJson($betaSource, [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('beta')['tokens'],
        ]);

        $usageClient = new NamedUsageClient([
            'alpha' => new AccountUsage(
                'plus',
                new RateLimitWindow(93.0, 300, 1776756600),
                new RateLimitWindow(15.0, 10080, 1777338600),
            ),
            'beta' => new RuntimeException('backend timeout'),
        ]);
        $application = new Application($home, usageClient: $usageClient);
        (new CommandTester($application->find('import')))->execute(['name' => 'alpha', '--from' => $alphaSource]);
        (new CommandTester($application->find('import')))->execute(['name' => 'beta', '--from' => $betaSource]);

        $tester = new CommandTester($application->find('accounts'));
        $code = $tester->execute(['action' => 'refresh']);

        $state = json_decode((string) file_get_contents($home . '/.config/codex-auth-proxy/state.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $code);
        self::assertSame('plus', $state['usage']['acct-alpha']['plan_type']);
        self::assertEquals(7.0, $state['usage']['acct-alpha']['primary']['left_percent']);
        self::assertSame('backend timeout', $state['usage']['acct-beta']['error']);
        self::assertNull($state['usage']['acct-beta']['primary']);
        self::assertNull($state['usage']['acct-beta']['secondary']);
        self::assertStringContainsString('alpha', $tester->getDisplay());
        self::assertStringContainsString('success', $tester->getDisplay());
        self::assertStringContainsString('beta', $tester->getDisplay());
        self::assertStringContainsString('failure', $tester->getDisplay());
    }

    public function testAccountsRefreshClearsCooldownAfterSuccessfulLiveUsageUpdate(): void
    {
        $home = $this->tempDir('cap-home');
        $source = $home . '/alpha.json';
        $this->writeJson($source, [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('alpha')['tokens'],
        ]);

        mkdir($home . '/.config/codex-auth-proxy', 0700, true);
        $this->writeJson($home . '/.config/codex-auth-proxy/state.json', [
            'accounts' => [
                'acct-alpha' => [
                    'cooldown_until' => time() + 1800,
                ],
            ],
        ]);

        $usageClient = new FakeUsageClient(new AccountUsage(
            'plus',
            new RateLimitWindow(20.0, 300, time() + 1800),
            new RateLimitWindow(3.0, 10080, time() + 86_400),
        ));
        $application = new Application($home, usageClient: $usageClient);
        (new CommandTester($application->find('import')))->execute(['name' => 'alpha', '--from' => $source]);

        $tester = new CommandTester($application->find('accounts'));
        $code = $tester->execute(['action' => 'refresh']);

        $state = json_decode((string) file_get_contents($home . '/.config/codex-auth-proxy/state.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $code);
        self::assertSame(0, $state['accounts']['acct-alpha']['cooldown_until']);
        self::assertNull($state['usage']['acct-alpha']['error']);
    }

    public function testAccountsStatusWritesErrorMetadataOnFailure(): void
    {
        $home = $this->tempDir('cap-home');
        $source = $home . '/auth.json';
        $this->writeJson($source, [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('alpha')['tokens'],
        ]);

        $usageClient = new NamedUsageClient([
            'alpha' => new RuntimeException('usage endpoint unavailable'),
        ]);
        $application = new Application($home, usageClient: $usageClient);
        (new CommandTester($application->find('import')))->execute(['name' => 'alpha', '--from' => $source]);

        $tester = new CommandTester($application->find('accounts'));
        $code = $tester->execute(['action' => 'status', 'name' => 'alpha']);

        $state = json_decode((string) file_get_contents($home . '/.config/codex-auth-proxy/state.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $code);
        self::assertSame('usage endpoint unavailable', $state['usage']['acct-alpha']['error']);
        self::assertStringContainsString('Status: unavailable (usage endpoint unavailable)', $tester->getDisplay());
    }

    public function testAccountsStatusRefreshesInvalidatedTokenAndRetries(): void
    {
        $home = $this->tempDir('cap-home');
        $source = $home . '/auth.json';
        $old = $this->accountFixture('alpha');
        $new = $this->accountFixture('alpha', [
            'tokens' => [
                'access_token' => $this->makeJwt(['exp' => self::FIXTURE_EXP, 'fresh' => true]),
                'refresh_token' => 'rt-alpha-fresh',
            ],
        ]);
        $this->writeJson($source, [
            'auth_mode' => 'chatgpt',
            'tokens' => $old['tokens'],
        ]);

        $application = new Application($home);
        (new CommandTester($application->find('import')))->execute(['name' => 'alpha', '--from' => $source]);

        $usageClient = new InvalidatedThenSuccessfulUsageClient(new AccountUsage(
            'plus',
            new RateLimitWindow(93.0, 300, 1776756600),
            new RateLimitWindow(15.0, 10080, 1777338600),
        ), $new['tokens']['access_token']);
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id_token' => $new['tokens']['id_token'],
                'access_token' => $new['tokens']['access_token'],
                'refresh_token' => $new['tokens']['refresh_token'],
            ], JSON_THROW_ON_ERROR)),
        ]))]);
        $command = new AccountsCommand(new AppConfigLoader($home), $usageClient, new TokenRefresher($http));

        $tester = new CommandTester($command);
        $code = $tester->execute(['action' => 'status', 'name' => 'alpha']);

        $accountFile = $home . '/.config/codex-auth-proxy/accounts/alpha.account.json';
        $stored = json_decode((string) file_get_contents($accountFile), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $code);
        self::assertSame(2, $usageClient->calls);
        self::assertSame($new['tokens']['access_token'], $stored['tokens']['access_token']);
        self::assertStringContainsString('5h limit: 7% left', $tester->getDisplay());
    }

    public function testAccountsDeleteArchivesAccountAfterConfirmation(): void
    {
        $home = $this->tempDir('cap-home');
        $source = $home . '/auth.json';
        $this->writeJson($source, [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture('alpha')['tokens'],
        ]);

        $application = new Application($home);
        (new CommandTester($application->find('import')))->execute(['name' => 'alpha', '--from' => $source]);

        $tester = new CommandTester($application->find('accounts'));
        $tester->setInputs(['yes']);
        $code = $tester->execute(['action' => 'delete', 'name' => 'alpha']);

        $accountsDir = $home . '/.config/codex-auth-proxy/accounts';
        self::assertSame(0, $code);
        self::assertFileDoesNotExist($accountsDir . '/alpha.account.json');
        self::assertCount(1, glob($accountsDir . '/alpha.account.json.deleted.*') ?: []);
        self::assertStringContainsString('Archived account alpha', $tester->getDisplay());
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

final class FakeUsageClient implements UsageClient
{
    public function __construct(private readonly AccountUsage $usage)
    {
    }

    public function fetch(\CodexAuthProxy\Account\CodexAccount $account): AccountUsage
    {
        return $this->usage;
    }
}

final class CountingUsageClient implements UsageClient
{
    public int $calls = 0;

    public function fetch(\CodexAuthProxy\Account\CodexAccount $account): AccountUsage
    {
        $this->calls++;

        throw new RuntimeException('live usage should not be called');
    }
}

final class NamedUsageClient implements UsageClient
{
    /** @param array<string,AccountUsage|RuntimeException> $results */
    public function __construct(private readonly array $results)
    {
    }

    public function fetch(\CodexAuthProxy\Account\CodexAccount $account): AccountUsage
    {
        $result = $this->results[$account->name()] ?? new RuntimeException('missing fake result');
        if ($result instanceof RuntimeException) {
            throw $result;
        }

        return $result;
    }
}

final class InvalidatedThenSuccessfulUsageClient implements UsageClient
{
    public int $calls = 0;

    public function __construct(
        private readonly AccountUsage $usage,
        private readonly string $expectedAccessToken,
    )
    {
    }

    public function fetch(\CodexAuthProxy\Account\CodexAccount $account): AccountUsage
    {
        $this->calls++;
        if ($this->calls === 1) {
            throw new RuntimeException('Codex app-server request failed: {"code":-32603,"message":"failed to fetch codex rate limits: GET https://chatgpt.com/backend-api/wham/usage failed: 401 Unauthorized; body={\"error\":{\"code\":\"token_invalidated\"}}"}');
        }
        if ($account->accessToken() !== $this->expectedAccessToken) {
            throw new RuntimeException('retry did not use refreshed token');
        }

        return $this->usage;
    }
}
