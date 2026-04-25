# Codex Runtime Defaults Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 收口 Codex 相关硬编码，明确区分应用默认值、协议常量和运行时 profile，避免默认值在配置层、装配层和底层实现中重复漂移。

**Architecture:** 保留现有 `AppConfig` 作为应用配置入口，但把 Codex 专属“身份值”收进单独的 runtime profile，把协议级常量和端点拼接规则收进单独类。`ServeCommand`/`AccountsCommand` 负责装配；`CodexProxyServer`、`CodexUsageClient` 和 `UpstreamHeaderFactory` 只消费显式依赖，不再自带重复默认值。

**Tech Stack:** PHP 8.3, Symfony Console/Config, PHPUnit 11, PHPStan 2, Swoole

---

### Task 1: 引入 Codex runtime profile

**Files:**
- Create: `src/Codex/CodexRuntimeProfile.php`
- Modify: `src/Config/AppConfig.php`
- Modify: `src/Console/Command/ServeCommand.php`
- Modify: `src/Console/Command/AccountsCommand.php`
- Test: `tests/Codex/CodexRuntimeProfileTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Codex;

use CodexAuthProxy\Codex\CodexRuntimeProfile;
use CodexAuthProxy\Config\AppConfig;
use CodexAuthProxy\Tests\TestCase;

final class CodexRuntimeProfileTest extends TestCase
{
    public function testBuildsProfileFromAppConfig(): void
    {
        $config = new AppConfig(
            home: '/tmp/home',
            accountsDir: '/tmp/accounts',
            stateFile: '/tmp/state.json',
            host: '127.0.0.1',
            port: 1456,
            cooldownSeconds: 18000,
            callbackHost: 'localhost',
            callbackPort: 1455,
            callbackTimeoutSeconds: 300,
            codexUserAgent: 'ua-test',
            codexBetaFeatures: 'beta-test',
            codexOriginator: 'originator-test',
            codexResidency: 'global',
            codexUpstreamBaseUrl: 'https://chatgpt.com/backend-api/codex',
            usageBaseUrl: 'https://chatgpt.com/backend-api',
            usageRefreshIntervalSeconds: 600,
            traceMutations: true,
            traceTimings: false,
            httpProxy: null,
            httpsProxy: null,
            noProxy: 'localhost',
        );

        $profile = CodexRuntimeProfile::fromAppConfig($config);

        self::assertSame('ua-test', $profile->userAgent);
        self::assertSame('beta-test', $profile->betaFeatures);
        self::assertSame('originator-test', $profile->originator);
        self::assertSame('global', $profile->residency);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Codex/CodexRuntimeProfileTest.php`
Expected: FAIL with `Class "CodexAuthProxy\\Codex\\CodexRuntimeProfile" not found` or `Unknown named parameter $codexUpstreamBaseUrl`

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace CodexAuthProxy\Codex;

use CodexAuthProxy\Config\AppConfig;

final class CodexRuntimeProfile
{
    public function __construct(
        public readonly string $userAgent,
        public readonly string $betaFeatures,
        public readonly string $originator,
        public readonly string $residency,
    ) {
    }

    public static function fromAppConfig(AppConfig $config): self
    {
        return new self(
            $config->codexUserAgent,
            $config->codexBetaFeatures,
            $config->codexOriginator,
            $config->codexResidency,
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- tests/Codex/CodexRuntimeProfileTest.php`
Expected: PASS

- [ ] **Step 5: Wire the profile into commands**

Use `CodexRuntimeProfile::fromAppConfig($config)` in:
- `src/Console/Command/ServeCommand.php`
- `src/Console/Command/AccountsCommand.php`

Expected result: commands stop spreading `codexUserAgent/codexBetaFeatures/codexOriginator/codexResidency` as four separate runtime defaults.

### Task 2: 收口协议常量与端点规则

**Files:**
- Create: `src/Codex/CodexProtocol.php`
- Modify: `src/Proxy/UpstreamHeaderFactory.php`
- Modify: `src/Usage/CodexUsageClient.php`
- Test: `tests/Codex/CodexProtocolTest.php`
- Test: `tests/Proxy/UpstreamHeaderFactoryTest.php`
- Test: `tests/Usage/CodexUsageClientTest.php`

- [ ] **Step 1: Write the failing tests**

```php
public function testDefaultResponsesWebsocketBetaHeaderIsExposedByProtocol(): void
{
    self::assertSame(
        'responses_websockets=2026-02-06',
        \CodexAuthProxy\Codex\CodexProtocol::responsesWebsocketBetaHeader()
    );
}

public function testChatGptUsageEndpointUsesWhamUsage(): void
{
    self::assertSame(
        'https://chatgpt.com/backend-api/wham/usage',
        \CodexAuthProxy\Codex\CodexProtocol::usageEndpoint('https://chatgpt.com/backend-api')
    );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- tests/Codex/CodexProtocolTest.php`
Expected: FAIL with missing class or missing method

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace CodexAuthProxy\Codex;

final class CodexProtocol
{
    public const DEFAULT_RESPONSES_WEBSOCKET_BETA = 'responses_websockets=2026-02-06';

    public static function responsesWebsocketBetaHeader(): string
    {
        return self::DEFAULT_RESPONSES_WEBSOCKET_BETA;
    }

    public static function usageEndpoint(string $baseUrl): string
    {
        $base = rtrim($baseUrl, '/');
        $host = parse_url($base, PHP_URL_HOST);
        if (is_string($host) && in_array($host, ['chatgpt.com', 'chat.openai.com'], true) && !str_contains($base, '/backend-api')) {
            $base .= '/backend-api';
        }

        if (str_contains($base, '/backend-api')) {
            return $base . '/wham/usage';
        }

        return $base . '/api/codex/usage';
    }
}
```

- [ ] **Step 4: Refactor consumers to use protocol helpers**

Replace:
- `src/Proxy/UpstreamHeaderFactory.php` local WS beta constant
- `src/Usage/CodexUsageClient.php` local usage endpoint path logic

Expected result: protocol constants live in exactly one place.

- [ ] **Step 5: Run focused tests**

Run: `composer test -- tests/Codex/CodexProtocolTest.php tests/Proxy/UpstreamHeaderFactoryTest.php tests/Usage/CodexUsageClientTest.php`
Expected: PASS

### Task 3: 移除 `CodexProxyServer` 与 `CodexUsageClient` 的重复默认值

**Files:**
- Modify: `src/Proxy/CodexProxyServer.php`
- Modify: `src/Usage/CodexUsageClient.php`
- Modify: `src/Proxy/UpstreamHeaderFactory.php`
- Test: `tests/Proxy/CodexProxyServerTest.php`
- Test: `tests/Usage/CodexUsageClientTest.php`

- [ ] **Step 1: Write the failing regression test**

```php
public function testServerConstructorDoesNotHideRuntimeDefaults(): void
{
    $reflection = new \ReflectionMethod(\CodexAuthProxy\Proxy\CodexProxyServer::class, '__construct');
    $params = array_column($reflection->getParameters(), 'name');

    self::assertContains('runtimeProfile', $params);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Proxy/CodexProxyServerTest.php`
Expected: FAIL because constructor still takes duplicated scalar defaults

- [ ] **Step 3: Write minimal implementation**

Implementation target:
- `CodexProxyServer` consumes `CodexRuntimeProfile $runtimeProfile`
- `UpstreamHeaderFactory` consumes `CodexRuntimeProfile $runtimeProfile`
- `CodexUsageClient` consumes `CodexRuntimeProfile $runtimeProfile`

Expected result: `userAgent/betaFeatures/originator/residency` stop appearing as repeated scalar constructor defaults in runtime classes.

- [ ] **Step 4: Run focused tests**

Run: `composer test -- tests/Proxy/CodexProxyServerTest.php tests/Usage/CodexUsageClientTest.php tests/Proxy/UpstreamHeaderFactoryTest.php`
Expected: PASS

### Task 4: 让 upstream base URL 成为显式应用配置

**Files:**
- Modify: `config/defaults.php`
- Modify: `src/Config/AppConfiguration.php`
- Modify: `src/Config/AppConfig.php`
- Modify: `src/Config/AppConfigLoader.php`
- Modify: `src/Console/Command/ServeCommand.php`
- Modify: `src/Proxy/CodexProxyServer.php`
- Test: `tests/Config/AppConfigLoaderTest.php`
- Test: `tests/Proxy/CodexProxyServerTest.php`
- Test: `tests/ApplicationTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function testLoadsConfiguredCodexUpstreamBaseUrl(): void
{
    $config = (new AppConfigLoader(home: '/tmp/home', defaultsFile: $this->defaultsFile([
        'home' => '/tmp/home',
        'accounts_dir' => '/tmp/accounts',
        'state_file' => '/tmp/state.json',
        'host' => '127.0.0.1',
        'port' => 1456,
        'cooldown_seconds' => 18000,
        'callback_host' => 'localhost',
        'callback_port' => 1455,
        'callback_timeout_seconds' => 300,
        'codex_user_agent' => 'ua',
        'codex_beta_features' => 'beta',
        'codex_originator' => 'originator',
        'codex_residency' => '',
        'codex_upstream_base_url' => 'https://proxy.example.test/codex',
        'usage_base_url' => 'https://proxy.example.test',
        'usage_refresh_interval_seconds' => 600,
        'trace_mutations' => true,
        'trace_timings' => false,
        'http_proxy' => null,
        'https_proxy' => null,
        'no_proxy' => 'localhost',
    ])))->load();

    self::assertSame('https://proxy.example.test/codex', $config->codexUpstreamBaseUrl);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- tests/Config/AppConfigLoaderTest.php`
Expected: FAIL because config field does not exist yet

- [ ] **Step 3: Implement config plumbing**

Add:
- `CODEX_AUTH_PROXY_CODEX_UPSTREAM_BASE_URL`
- `AppConfig::$codexUpstreamBaseUrl`
- loader / configuration validation

Expected result: `CodexProxyServer` no longer hardcodes `https://chatgpt.com/backend-api/codex`.

- [ ] **Step 4: Run focused tests**

Run: `composer test -- tests/Config/AppConfigLoaderTest.php tests/Proxy/CodexProxyServerTest.php tests/ApplicationTest.php`
Expected: PASS

### Task 5: 更新文档并做全量验证

**Files:**
- Modify: `README.md`
- Modify: `README.zh-CN.md`
- Modify: `.env.example`

- [ ] **Step 1: Update docs**

Document:
- runtime profile fallback behavior
- `CODEX_AUTH_PROXY_CODEX_UPSTREAM_BASE_URL`
- protocol constants no longer duplicated in runtime classes

- [ ] **Step 2: Run verification**

Run: `composer analyse`
Expected: `[OK] No errors`

Run: `composer test`
Expected: `OK`

Run: `git diff --check`
Expected: no output

- [ ] **Step 3: Commit**

```bash
git add config/defaults.php .env.example README.md README.zh-CN.md src/Codex src/Config src/Console/Command src/Proxy src/Usage tests
git commit -m "refactor: centralize codex runtime defaults"
```

