<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Usage;

use CodexAuthProxy\Account\AccountFileValidator;
use CodexAuthProxy\Tests\TestCase;
use CodexAuthProxy\Usage\CodexUsageClient;

final class CodexUsageClientTest extends TestCase
{
    public function testFetchesRateLimitsThroughCodexAppServerWithIsolatedCodexHome(): void
    {
        $dir = $this->tempDir('cap-codex-app-server');
        $fakeCodex = $dir . '/fake-codex';
        file_put_contents($fakeCodex, <<<'PHP'
#!/usr/bin/env php
<?php
if (($argv[1] ?? '') !== 'app-server' || ($argv[2] ?? '') !== '--listen' || ($argv[3] ?? '') !== 'stdio://') {
    fwrite(STDERR, "unexpected args\n");
    exit(2);
}
$home = getenv('CODEX_HOME');
$auth = json_decode(file_get_contents($home . '/auth.json'), true);
if (($auth['tokens']['account_id'] ?? null) !== 'acct-alpha') {
    fwrite(STDERR, "missing isolated auth\n");
    exit(3);
}
if (!array_key_exists('OPENAI_API_KEY', $auth) || $auth['OPENAI_API_KEY'] !== null) {
    fwrite(STDERR, "missing openai api key placeholder\n");
    exit(4);
}
if (!is_string($auth['last_refresh'] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{9}Z$/', $auth['last_refresh']) !== 1) {
    fwrite(STDERR, "missing codex last_refresh\n");
    exit(5);
}
mkdir($home . '/skills/.system/nested', 0700, true);
file_put_contents($home . '/skills/.system/nested/cache.json', '{}');
while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true);
    if (($message['method'] ?? '') === 'initialize') {
        echo json_encode(['id' => $message['id'], 'result' => [
            'userAgent' => 'fake',
            'codexHome' => $home,
            'platformFamily' => 'unix',
            'platformOs' => 'linux',
        ]]) . "\n";
        continue;
    }
    if (($message['method'] ?? '') === 'account/rateLimits/read') {
        echo json_encode(['id' => $message['id'], 'result' => [
            'rateLimits' => [
                'limitId' => 'codex',
                'limitName' => null,
                'primary' => ['usedPercent' => 93.0, 'windowDurationMins' => 300, 'resetsAt' => 1776756600],
                'secondary' => ['usedPercent' => 15.0, 'windowDurationMins' => 10080, 'resetsAt' => 1777338600],
                'credits' => null,
                'planType' => 'plus',
                'rateLimitReachedType' => null,
            ],
            'rateLimitsByLimitId' => null,
        ]]) . "\n";
        continue;
    }
    echo json_encode(['id' => $message['id'] ?? null, 'error' => ['message' => 'unexpected method']]) . "\n";
}
PHP);
        chmod($fakeCodex, 0700);
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));

        $usage = (new CodexUsageClient($fakeCodex, timeoutSeconds: 1, tempRoot: $dir))->fetch($account);

        self::assertSame('plus', $usage->planType);
        self::assertSame(93.0, $usage->primary?->usedPercent);
        self::assertSame(15.0, $usage->secondary?->usedPercent);
        self::assertCount(0, glob($dir . '/codex-home-*') ?: []);
    }

    public function testFetchInjectsConfiguredProxyEnvironmentIntoCodexAppServer(): void
    {
        $dir = $this->tempDir('cap-codex-app-server-proxy');
        $fakeCodex = $dir . '/fake-codex';
        file_put_contents($fakeCodex, <<<'PHP'
#!/usr/bin/env php
<?php
if (getenv('HTTP_PROXY') !== 'http://proxy.local:8080') {
    fwrite(STDERR, "missing HTTP_PROXY\n");
    exit(6);
}
if (getenv('HTTPS_PROXY') !== 'http://secure-proxy.local:8443') {
    fwrite(STDERR, "missing HTTPS_PROXY\n");
    exit(7);
}
if (getenv('NO_PROXY') !== 'localhost,127.0.0.1') {
    fwrite(STDERR, "missing NO_PROXY\n");
    exit(8);
}
while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true);
    if (($message['method'] ?? '') === 'initialize') {
        echo json_encode(['id' => $message['id'], 'result' => []]) . "\n";
        continue;
    }
    echo json_encode(['id' => $message['id'], 'result' => [
        'rateLimits' => [
            'primary' => ['usedPercent' => 10.0, 'windowDurationMins' => 300, 'resetsAt' => null],
            'secondary' => null,
            'planType' => 'plus',
        ],
    ]]) . "\n";
}
PHP);
        chmod($fakeCodex, 0700);
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));

        $usage = (new CodexUsageClient(
            $fakeCodex,
            timeoutSeconds: 1,
            tempRoot: $dir,
            proxyEnv: [
                'HTTP_PROXY' => 'http://proxy.local:8080',
                'HTTPS_PROXY' => 'http://secure-proxy.local:8443',
                'NO_PROXY' => 'localhost,127.0.0.1',
            ],
        ))->fetch($account);

        self::assertSame('plus', $usage->planType);
        self::assertSame(10.0, $usage->primary?->usedPercent);
    }

    public function testFetchDoesNotLeakParentProcessProxyEnvironmentIntoCodexAppServer(): void
    {
        $snapshot = [
            'HTTP_PROXY' => getenv('HTTP_PROXY') === false ? null : getenv('HTTP_PROXY'),
            'HTTPS_PROXY' => getenv('HTTPS_PROXY') === false ? null : getenv('HTTPS_PROXY'),
            'http_proxy' => getenv('http_proxy') === false ? null : getenv('http_proxy'),
            'https_proxy' => getenv('https_proxy') === false ? null : getenv('https_proxy'),
        ];
        $dir = $this->tempDir('cap-codex-app-server-proxy-isolation');
        $fakeCodex = $dir . '/fake-codex';
        file_put_contents($fakeCodex, <<<'PHP'
#!/usr/bin/env php
<?php
foreach (['HTTP_PROXY', 'HTTPS_PROXY', 'http_proxy', 'https_proxy'] as $name) {
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        fwrite(STDERR, "leaked $name\n");
        exit(9);
    }
}
if (getenv('NO_PROXY') !== 'localhost') {
    fwrite(STDERR, "missing explicit NO_PROXY\n");
    exit(10);
}
while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true);
    echo json_encode(['id' => $message['id'], 'result' => [
        'rateLimits' => [
            'primary' => ['usedPercent' => 10.0, 'windowDurationMins' => 300, 'resetsAt' => null],
            'secondary' => null,
            'planType' => 'plus',
        ],
    ]]) . "\n";
}
PHP);
        chmod($fakeCodex, 0700);
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));

        try {
            foreach (array_keys($snapshot) as $name) {
                putenv($name . '=http://standard-proxy.local:8080');
            }

            $usage = (new CodexUsageClient(
                $fakeCodex,
                timeoutSeconds: 1,
                tempRoot: $dir,
                proxyEnv: ['NO_PROXY' => 'localhost'],
            ))->fetch($account);

            self::assertSame('plus', $usage->planType);
        } finally {
            foreach ($snapshot as $name => $value) {
                if ($value === null) {
                    putenv($name);
                    continue;
                }
                putenv($name . '=' . $value);
            }
        }
    }
}
