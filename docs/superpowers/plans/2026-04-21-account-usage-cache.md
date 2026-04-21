# Account Usage Cache Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a local usage cache so `accounts` shows which accounts are actually usable, and let the scheduler skip accounts whose cached 5h or weekly quota is exhausted.

**Architecture:** Keep live quota fetching in `CodexUsageClient`, but persist the latest known quota snapshot in `StateStore` and compute availability from account metadata, cooldown state, and cached usage. `serve` stays a direct upstream proxy; only `accounts status` and `accounts refresh` touch the live usage reader.

**Tech Stack:** PHP 8.3, Symfony Console, JSON state file, existing PHPUnit/PHPStan/Box tooling.

---

### Task 1: Persist Usage Snapshots in StateStore

**Files:**
- Create: `src/Usage/CachedRateLimitWindow.php`
- Create: `src/Usage/CachedAccountUsage.php`
- Create: `tests/Routing/StateStoreUsageTest.php`
- Modify: `src/Routing/StateStore.php`

- [ ] **Step 1: Write the failing state persistence tests**

```php
<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Routing;

use CodexAuthProxy\Routing\StateStore;
use CodexAuthProxy\Tests\TestCase;
use CodexAuthProxy\Usage\CachedAccountUsage;
use CodexAuthProxy\Usage\CachedRateLimitWindow;

final class StateStoreUsageTest extends TestCase
{
    public function testKeepsBackwardCompatibilityWhenUsageSectionIsMissing(): void
    {
        $path = $this->tempDir('cap-state') . '/state.json';
        file_put_contents($path, json_encode([
            'accounts' => ['acct-alpha' => ['cooldown_until' => 123]],
            'sessions' => ['session-1' => 'acct-alpha'],
            'cursor' => 2,
        ], JSON_THROW_ON_ERROR));

        $store = StateStore::file($path);

        self::assertNull($store->accountUsage('acct-alpha'));
        self::assertSame([], $store->allAccountUsage());
        self::assertSame(123, $store->cooldownUntil('acct-alpha'));
        self::assertSame('acct-alpha', $store->sessionAccount('session-1'));
    }

    public function testPersistsCachedUsageSnapshots(): void
    {
        $path = $this->tempDir('cap-state') . '/state.json';
        $store = StateStore::file($path);
        $usage = new CachedAccountUsage(
            'plus',
            1776753000,
            null,
            new CachedRateLimitWindow(93.0, 7.0, 300, 1776756600),
            new CachedRateLimitWindow(15.0, 85.0, 10080, 1777338600),
        );

        $store->setAccountUsage('acct-alpha', $usage);
        $reloaded = StateStore::file($path);
        $snapshot = $reloaded->accountUsage('acct-alpha');

        self::assertSame('plus', $snapshot?->planType);
        self::assertSame(7.0, $snapshot?->primary?->leftPercent);
        self::assertSame(85.0, $snapshot?->secondary?->leftPercent);
        self::assertNull($snapshot?->error);
    }

    public function testUpdatesOnlyErrorMetadataWhenRefreshFailsAfterSuccess(): void
    {
        $path = $this->tempDir('cap-state') . '/state.json';
        $store = StateStore::file($path);
        $store->setAccountUsage('acct-alpha', new CachedAccountUsage(
            'plus',
            1776753000,
            null,
            new CachedRateLimitWindow(93.0, 7.0, 300, 1776756600),
            new CachedRateLimitWindow(15.0, 85.0, 10080, 1777338600),
        ));

        $store->setAccountUsageError('acct-alpha', 'refresh failed', 1776753600);
        $snapshot = StateStore::file($path)->accountUsage('acct-alpha');

        self::assertSame('refresh failed', $snapshot?->error);
        self::assertSame(1776753600, $snapshot?->checkedAt);
        self::assertSame(7.0, $snapshot?->primary?->leftPercent);
    }
}
```

- [ ] **Step 2: Run the state persistence tests to verify RED**

Run: `vendor/bin/phpunit tests/Routing/StateStoreUsageTest.php`

Expected: FAIL with missing `CachedAccountUsage`, missing `CachedRateLimitWindow`, and missing `StateStore::accountUsage()` methods.

- [ ] **Step 3: Implement immutable cached usage DTOs**

```php
<?php

declare(strict_types=1);

namespace CodexAuthProxy\Usage;

final class CachedRateLimitWindow
{
    public function __construct(
        public readonly float $usedPercent,
        public readonly float $leftPercent,
        public readonly int $windowMinutes,
        public readonly ?int $resetsAt,
    ) {
    }

    /** @return array{used_percent:float,left_percent:float,window_minutes:int,resets_at:?int} */
    public function toArray(): array
    {
        return [
            'used_percent' => $this->usedPercent,
            'left_percent' => $this->leftPercent,
            'window_minutes' => $this->windowMinutes,
            'resets_at' => $this->resetsAt,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): ?self
    {
        if (!isset($data['used_percent'], $data['left_percent'], $data['window_minutes'])) {
            return null;
        }

        return new self(
            (float) $data['used_percent'],
            (float) $data['left_percent'],
            (int) $data['window_minutes'],
            isset($data['resets_at']) ? (int) $data['resets_at'] : null,
        );
    }
}
```

```php
<?php

declare(strict_types=1);

namespace CodexAuthProxy\Usage;

final class CachedAccountUsage
{
    public function __construct(
        public readonly string $planType,
        public readonly int $checkedAt,
        public readonly ?string $error,
        public readonly ?CachedRateLimitWindow $primary,
        public readonly ?CachedRateLimitWindow $secondary,
    ) {
    }

    public static function fromLive(AccountUsage $usage, int $checkedAt): self
    {
        return new self(
            $usage->planType,
            $checkedAt,
            null,
            $usage->primary === null ? null : new CachedRateLimitWindow(
                $usage->primary->usedPercent,
                $usage->primary->leftPercent(),
                $usage->primary->windowMinutes,
                $usage->primary->resetsAt,
            ),
            $usage->secondary === null ? null : new CachedRateLimitWindow(
                $usage->secondary->usedPercent,
                $usage->secondary->leftPercent(),
                $usage->secondary->windowMinutes,
                $usage->secondary->resetsAt,
            ),
        );
    }

    public function withError(string $error, int $checkedAt): self
    {
        return new self($this->planType, $checkedAt, $error, $this->primary, $this->secondary);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'plan_type' => $this->planType,
            'checked_at' => $this->checkedAt,
            'error' => $this->error,
            'primary' => $this->primary?->toArray(),
            'secondary' => $this->secondary?->toArray(),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['plan_type'] ?? ''),
            (int) ($data['checked_at'] ?? 0),
            isset($data['error']) && is_string($data['error']) ? $data['error'] : null,
            isset($data['primary']) && is_array($data['primary']) ? CachedRateLimitWindow::fromArray($data['primary']) : null,
            isset($data['secondary']) && is_array($data['secondary']) ? CachedRateLimitWindow::fromArray($data['secondary']) : null,
        );
    }
}
```

- [ ] **Step 4: Extend `StateStore` with usage read/write methods**

```php
/** @return array<string,CachedAccountUsage> */
public function allAccountUsage(): array
{
    $usage = $this->state['usage'] ?? [];
    if (!is_array($usage)) {
        return [];
    }

    $snapshots = [];
    foreach ($usage as $accountId => $row) {
        if (!is_string($accountId) || !is_array($row)) {
            continue;
        }
        $snapshots[$accountId] = CachedAccountUsage::fromArray($row);
    }

    return $snapshots;
}

public function accountUsage(string $accountId): ?CachedAccountUsage
{
    return $this->allAccountUsage()[$accountId] ?? null;
}

public function setAccountUsage(string $accountId, CachedAccountUsage $usage): void
{
    $this->state['usage'][$accountId] = $usage->toArray();
    $this->save();
}

public function setAccountUsageError(string $accountId, string $error, int $checkedAt): void
{
    $previous = $this->accountUsage($accountId);
    $usage = $previous === null
        ? new CachedAccountUsage('', $checkedAt, $error, null, null)
        : $previous->withError($error, $checkedAt);

    $this->setAccountUsage($accountId, $usage);
}
```

- [ ] **Step 5: Run the state persistence tests to verify GREEN**

Run: `vendor/bin/phpunit tests/Routing/StateStoreUsageTest.php`

Expected: PASS with 3 tests green.

- [ ] **Step 6: Commit**

```bash
git add tests/Routing/StateStoreUsageTest.php src/Usage/CachedRateLimitWindow.php src/Usage/CachedAccountUsage.php src/Routing/StateStore.php
git commit -m "Add cached account usage state"
```

### Task 2: Add Availability Evaluation and Scheduler Filtering

**Files:**
- Create: `src/Usage/AccountAvailability.php`
- Create: `tests/Usage/AccountAvailabilityTest.php`
- Modify: `src/Routing/Scheduler.php`
- Modify: `tests/SchedulerTest.php`

- [ ] **Step 1: Write failing tests for availability reasons**

```php
<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Usage;

use CodexAuthProxy\Account\AccountFileValidator;
use CodexAuthProxy\Tests\TestCase;
use CodexAuthProxy\Usage\AccountAvailability;
use CodexAuthProxy\Usage\CachedAccountUsage;
use CodexAuthProxy\Usage\CachedRateLimitWindow;

final class AccountAvailabilityTest extends TestCase
{
    public function testMarksUsageUnknownWhenNoSnapshotExists(): void
    {
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));
        $availability = AccountAvailability::from($account, 0, null, 1776753000);

        self::assertFalse($availability->isConfirmedAvailable);
        self::assertSame('usage_unknown', $availability->reason);
        self::assertTrue($availability->routable);
    }

    public function testMarksUsageExhaustedWhenPrimaryQuotaIsZero(): void
    {
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));
        $usage = new CachedAccountUsage(
            'plus',
            1776753000,
            null,
            new CachedRateLimitWindow(100.0, 0.0, 300, 1776756600),
            new CachedRateLimitWindow(15.0, 85.0, 10080, 1777338600),
        );

        $availability = AccountAvailability::from($account, 0, $usage, 1776753000);

        self::assertFalse($availability->isConfirmedAvailable);
        self::assertFalse($availability->routable);
        self::assertSame('usage_exhausted', $availability->reason);
    }
}
```

- [ ] **Step 2: Run availability tests to verify RED**

Run: `vendor/bin/phpunit tests/Usage/AccountAvailabilityTest.php`

Expected: FAIL because `AccountAvailability` does not exist.

- [ ] **Step 3: Implement `AccountAvailability`**

```php
<?php

declare(strict_types=1);

namespace CodexAuthProxy\Usage;

use CodexAuthProxy\Account\CodexAccount;

final class AccountAvailability
{
    public function __construct(
        public readonly bool $routable,
        public readonly bool $isConfirmedAvailable,
        public readonly string $reason,
        public readonly ?int $cooldownUntil,
        public readonly ?CachedAccountUsage $usage,
    ) {
    }

    public static function from(CodexAccount $account, int $cooldownUntil, ?CachedAccountUsage $usage, int $now): self
    {
        if (!$account->enabled()) {
            return new self(false, false, 'disabled', $cooldownUntil > 0 ? $cooldownUntil : null, $usage);
        }
        if ($cooldownUntil > $now) {
            return new self(false, false, 'cooldown', $cooldownUntil, $usage);
        }
        if ($usage === null) {
            return new self(true, false, 'usage_unknown', null, null);
        }
        if ($usage->error !== null && $usage->primary === null && $usage->secondary === null) {
            return new self(false, false, 'usage_error', null, $usage);
        }
        $primaryLeft = $usage->primary?->leftPercent;
        $secondaryLeft = $usage->secondary?->leftPercent;
        if (($primaryLeft !== null && $primaryLeft <= 0.0) || ($secondaryLeft !== null && $secondaryLeft <= 0.0)) {
            return new self(false, false, 'usage_exhausted', null, $usage);
        }

        return new self(true, true, 'ok', null, $usage);
    }
}
```

- [ ] **Step 4: Teach `Scheduler` to consult cached usage**

```php
private function isAvailable(CodexAccount $account): bool
{
    $availability = AccountAvailability::from(
        $account,
        $this->state->cooldownUntil($account->accountId()),
        $this->state->accountUsage($account->accountId()),
        $this->now(),
    );

    return $availability->routable;
}
```

- [ ] **Step 5: Add scheduler tests for exhausted versus unknown accounts**

```php
public function testSkipsCachedExhaustedAccountsButAllowsUnknownAccounts(): void
{
    $state = StateStore::memory();
    $state->setAccountUsage('acct-alpha', new CachedAccountUsage(
        'plus',
        1776753000,
        null,
        new CachedRateLimitWindow(100.0, 0.0, 300, 1776756600),
        new CachedRateLimitWindow(15.0, 85.0, 10080, 1777338600),
    ));
    $accounts = [
        (new AccountFileValidator())->validate($this->accountFixture('alpha')),
        (new AccountFileValidator())->validate($this->accountFixture('beta')),
    ];

    $scheduler = new Scheduler($accounts, $state, static fn (): int => 1776753000);

    self::assertSame('acct-beta', $scheduler->accountForSession('session-1')->accountId());
}
```

- [ ] **Step 6: Run availability and scheduler tests to verify GREEN**

Run: `vendor/bin/phpunit tests/Usage/AccountAvailabilityTest.php tests/SchedulerTest.php`

Expected: PASS with new exhausted/unknown routing behavior covered.

- [ ] **Step 7: Commit**

```bash
git add src/Usage/AccountAvailability.php tests/Usage/AccountAvailabilityTest.php src/Routing/Scheduler.php tests/SchedulerTest.php
git commit -m "Add cached account availability rules"
```

### Task 3: Turn `accounts` into a Cached Dashboard and Add Refresh

**Files:**
- Modify: `src/Console/Command/AccountsCommand.php`
- Modify: `src/Console/Application.php`
- Modify: `tests/ApplicationTest.php`

- [ ] **Step 1: Write failing CLI tests for cached dashboard and refresh**

```php
public function testAccountsDefaultShowsCachedAvailabilityWithoutCallingUsageClient(): void
{
    $home = $this->tempDir('cap-home');
    $application = new Application($home, usageClient: new ExplodingUsageClient());
    $source = $home . '/auth.json';
    $this->writeJson($source, [
        'auth_mode' => 'chatgpt',
        'tokens' => $this->accountFixture('alpha')['tokens'],
    ]);
    (new CommandTester($application->find('import')))->execute(['name' => 'alpha', '--from' => $source]);

    $stateFile = $home . '/.config/codex-auth-proxy/state.json';
    file_put_contents($stateFile, json_encode([
        'accounts' => [],
        'sessions' => [],
        'cursor' => 0,
        'usage' => [
            'acct-alpha' => [
                'plan_type' => 'plus',
                'checked_at' => 1776753000,
                'error' => null,
                'primary' => ['used_percent' => 93.0, 'left_percent' => 7.0, 'window_minutes' => 300, 'resets_at' => 1776756600],
                'secondary' => ['used_percent' => 15.0, 'left_percent' => 85.0, 'window_minutes' => 10080, 'resets_at' => 1777338600],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $tester = new CommandTester($application->find('accounts'));
    $tester->execute([]);

    self::assertStringContainsString('7%', $tester->getDisplay());
    self::assertStringContainsString('ok', strtolower($tester->getDisplay()));
}

public function testAccountsRefreshUpdatesCacheAndContinuesAfterOneFailure(): void
{
    $home = $this->tempDir('cap-home');
    $application = new Application($home, usageClient: new RefreshingUsageClient([
        'acct-alpha' => new AccountUsage(
            'plus',
            new RateLimitWindow(93.0, 300, 1776756600),
            new RateLimitWindow(15.0, 10080, 1777338600),
        ),
    ], [
        'acct-beta' => 'quota fetch failed',
    ]));
    foreach (['alpha', 'beta'] as $name) {
        $source = $home . '/' . $name . '.json';
        $this->writeJson($source, [
            'auth_mode' => 'chatgpt',
            'tokens' => $this->accountFixture($name)['tokens'],
        ]);
        (new CommandTester($application->find('import')))->execute(['name' => $name, '--from' => $source]);
    }

    $tester = new CommandTester($application->find('accounts'));
    $code = $tester->execute(['action' => 'refresh']);
    $state = json_decode((string) file_get_contents($home . '/.config/codex-auth-proxy/state.json'), true, flags: JSON_THROW_ON_ERROR);

    self::assertSame(1, $code);
    self::assertSame(7.0, $state['usage']['acct-alpha']['primary']['left_percent']);
    self::assertSame('quota fetch failed', $state['usage']['acct-beta']['error']);
    self::assertStringContainsString('Refreshed alpha', $tester->getDisplay());
    self::assertStringContainsString('Failed beta: quota fetch failed', $tester->getDisplay());
}
```

- [ ] **Step 2: Run the CLI tests to verify RED**

Run: `vendor/bin/phpunit tests/ApplicationTest.php --filter "Accounts(DefaultShowsCachedAvailability|RefreshUpdatesCacheAndContinuesAfterOneFailure|StatusUpdatesCacheAfterLiveFetch)"`

Expected: FAIL because `accounts` still defaults to the old list view and has no `refresh` action.

- [ ] **Step 3: Make `accounts` load `StateStore` and render cached availability**

```php
private function listAccounts(AccountRepository $repository, AppConfig $config, InputInterface $input, OutputInterface $output): int
{
    $accounts = $repository->load();
    $state = StateStore::file($config->stateFile);

    $rows = array_map(function (CodexAccount $account) use ($state): array {
        $availability = AccountAvailability::from(
            $account,
            $state->cooldownUntil($account->accountId()),
            $state->accountUsage($account->accountId()),
            time(),
        );

        return [
            'name' => $account->name(),
            'email' => $account->email(),
            'plan' => $this->displayPlan($account->planType()),
            'enabled' => $account->enabled(),
            'cooldown_until' => $availability->cooldownUntil,
            'primary_left' => $availability->usage?->primary?->leftPercent,
            'secondary_left' => $availability->usage?->secondary?->leftPercent,
            'available' => $availability->isConfirmedAvailable,
            'reason' => $availability->reason,
            'checked_at' => $availability->usage?->checkedAt,
        ];
    }, $accounts);

    if ((bool) $input->getOption('json')) {
        $output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }

    $table = new Table($output);
    $table->setHeaders(['Name', 'Email', 'Plan', 'Enabled', 'Cooldown', '5h left', 'Weekly left', 'Available', 'Reason', 'Checked at']);
    foreach ($rows as $row) {
        $table->addRow([
            $row['name'],
            $row['email'] !== '' ? $row['email'] : '-',
            $row['plan'],
            $row['enabled'] ? 'yes' : 'no',
            $row['cooldown_until'] === null ? '-' : date('Y-m-d H:i', $row['cooldown_until']),
            $row['primary_left'] === null ? '-' : rtrim(rtrim(number_format((float) $row['primary_left'], 1, '.', ''), '0'), '.') . '%',
            $row['secondary_left'] === null ? '-' : rtrim(rtrim(number_format((float) $row['secondary_left'], 1, '.', ''), '0'), '.') . '%',
            $row['available'] ? 'yes' : 'no',
            $row['reason'],
            $row['checked_at'] === null ? '-' : date('Y-m-d H:i', (int) $row['checked_at']),
        ]);
    }
    $table->render();

    return self::SUCCESS;
}
```

- [ ] **Step 4: Add `refresh` action and make live fetches update the cache**

```php
private function refresh(AccountRepository $repository, AppConfig $config, InputInterface $input, OutputInterface $output): int
{
    $accounts = $this->selectedAccounts($repository, $this->stringArgument($input, 'name'));
    $state = StateStore::file($config->stateFile);
    $client = $this->usageClient ?? new CodexUsageClient();
    $failed = false;

    foreach ($accounts as $account) {
        try {
            $account = $this->refreshAccountIfNeeded($repository, $account);
            $usage = $client->fetch($account);
            $state->setAccountUsage($account->accountId(), CachedAccountUsage::fromLive($usage, time()));
            $output->writeln('Refreshed ' . $account->name());
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $failed = true;
            $state->setAccountUsageError($account->accountId(), $exception->getMessage(), time());
            $output->writeln('Failed ' . $account->name() . ': ' . $exception->getMessage());
        }
    }

    return $failed ? self::FAILURE : self::SUCCESS;
}

protected function execute(InputInterface $input, OutputInterface $output): int
{
    $action = strtolower((string) $input->getArgument('action'));
    $config = $this->appConfig($input);
    $repository = new AccountRepository($config->accountsDir);

    return match ($action) {
        'list' => $this->listAccounts($repository, $config, $input, $output),
        'refresh' => $this->refresh($repository, $config, $input, $output),
        'status' => $this->status($repository, $config, $input, $output),
        'delete' => $this->delete($repository, $input, $output),
        default => throw new InvalidArgumentException('Action must be list, refresh, status, or delete'),
    };
}
```

- [ ] **Step 5: Make `status` write through to the cache**

```php
try {
    $account = $this->refreshAccountIfNeeded($repository, $account);
    $usage = $client->fetch($account);
    $state->setAccountUsage($account->accountId(), CachedAccountUsage::fromLive($usage, time()));
    $results[] = ['account' => $account, 'usage' => $usage, 'error' => null];
} catch (InvalidArgumentException|RuntimeException $exception) {
    $state->setAccountUsageError($account->accountId(), $exception->getMessage(), time());
    if ($exception instanceof RuntimeException && $this->isInvalidatedTokenFailure($exception)) {
        $account = $this->refreshAccount($repository, $account);
        $usage = $client->fetch($account);
        $state->setAccountUsage($account->accountId(), CachedAccountUsage::fromLive($usage, time()));
        $results[] = ['account' => $account, 'usage' => $usage, 'error' => null];
        continue;
    }
    $failed = true;
    $results[] = ['account' => $account, 'usage' => null, 'error' => $exception->getMessage()];
}
```

- [ ] **Step 6: Add test-only `UsageClient` fakes**

```php
final class ExplodingUsageClient implements UsageClient
{
    public function fetch(\CodexAuthProxy\Account\CodexAccount $account): AccountUsage
    {
        throw new RuntimeException('list should not perform live usage fetch');
    }
}

final class RefreshingUsageClient implements UsageClient
{
    /** @param array<string,AccountUsage> $success @param array<string,string> $failures */
    public function __construct(
        private readonly array $success,
        private readonly array $failures,
    ) {
    }

    public function fetch(\CodexAuthProxy\Account\CodexAccount $account): AccountUsage
    {
        $accountId = $account->accountId();
        if (isset($this->failures[$accountId])) {
            throw new RuntimeException($this->failures[$accountId]);
        }
        if (!isset($this->success[$accountId])) {
            throw new RuntimeException('unexpected account ' . $accountId);
        }

        return $this->success[$accountId];
    }
}
```

- [ ] **Step 7: Run the CLI tests to verify GREEN**

Run: `vendor/bin/phpunit tests/ApplicationTest.php`

Expected: PASS with cached dashboard, refresh, and status write-through behavior covered.

- [ ] **Step 8: Commit**

```bash
git add src/Console/Command/AccountsCommand.php src/Console/Application.php tests/ApplicationTest.php
git commit -m "Add cached accounts dashboard and refresh command"
```

### Task 4: Update Docs and Run Full Verification

**Files:**
- Modify: `README.md`
- Modify: `README.zh-CN.md`

- [ ] **Step 1: Update docs for cached dashboard behavior**

````md
Manage imported accounts:

```bash
bin/codex-auth-proxy accounts
bin/codex-auth-proxy accounts refresh
bin/codex-auth-proxy accounts refresh account-a
bin/codex-auth-proxy accounts status account-a
```

`accounts` shows cached cooldown and quota availability.
`accounts refresh` fetches live Codex quota through the existing usage reader and updates local state.
`serve` still routes requests directly and only uses cached availability plus cooldown state.
````

- [ ] **Step 2: Run focused verification first**

Run: `vendor/bin/phpunit tests/Routing/StateStoreUsageTest.php tests/Usage/AccountAvailabilityTest.php tests/SchedulerTest.php tests/ApplicationTest.php`

Expected: PASS.

- [ ] **Step 3: Run full project verification**

Run: `composer test`
Expected: `OK` with the full PHPUnit suite.

Run: `composer analyse`
Expected: `[OK] No errors`

Run: `composer box:validate`
Expected: `[OK] The configuration file passed the validation.`

Run: `git diff --check`
Expected: no output.

- [ ] **Step 4: Commit**

```bash
git add README.md README.zh-CN.md
git commit -m "Document cached account availability workflow"
```

---

Self-review notes:
- Spec coverage: usage cache persistence, availability rules, cached dashboard, refresh action, scheduler filtering, and docs are all mapped to tasks.
- Placeholder scan: no unfinished markers remain; every task has exact files and commands.
- Type consistency: `CachedAccountUsage`, `CachedRateLimitWindow`, `AccountAvailability`, and `StateStore` method names are reused consistently across all tasks.
