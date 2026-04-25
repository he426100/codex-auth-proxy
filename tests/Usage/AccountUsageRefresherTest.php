<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Usage;

use CodexAuthProxy\Account\AccountFileValidator;
use CodexAuthProxy\Account\AccountRepository;
use CodexAuthProxy\Account\CodexAccount;
use CodexAuthProxy\Routing\StateStore;
use CodexAuthProxy\Tests\TestCase;
use CodexAuthProxy\Usage\AccountUsage;
use CodexAuthProxy\Usage\AccountUsageRefresher;
use CodexAuthProxy\Usage\RateLimitWindow;
use CodexAuthProxy\Usage\UsageClient;
use RuntimeException;

final class AccountUsageRefresherTest extends TestCase
{
    public function testRefreshAllRecordsUsageAndClearsCooldown(): void
    {
        $repository = new AccountRepository($this->tempDir('cap-usage-refresh-accounts'));
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));
        $repository->saveAccount($account);
        $state = StateStore::memory();
        $state->setCooldownUntil('acct-alpha', 2_000);

        $summary = (new AccountUsageRefresher(new RefresherFakeUsageClient()))->refreshAll($repository, $state, 1_000);

        $usage = $state->accountUsage('acct-alpha');
        self::assertSame(['success' => 1, 'failure' => 0, 'skipped' => 0], $summary);
        self::assertSame(0, $state->cooldownUntil('acct-alpha'));
        self::assertSame(93.0, $usage?->primary?->usedPercent);
        self::assertSame(7.0, $usage?->primary?->leftPercent);
    }

    public function testRefreshAllRecordsFailuresWithoutDroppingPreviousSnapshot(): void
    {
        $repository = new AccountRepository($this->tempDir('cap-usage-refresh-failure-accounts'));
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));
        $repository->saveAccount($account);
        $state = StateStore::memory();
        $state->setAccountUsage('acct-alpha', \CodexAuthProxy\Usage\CachedAccountUsage::fromLive(new AccountUsage(
            'plus',
            new RateLimitWindow(10.0, 300, 1_300),
            null,
        ), 900));

        $summary = (new AccountUsageRefresher(new RefresherFailingUsageClient()))->refreshAll($repository, $state, 1_000);

        $usage = $state->accountUsage('acct-alpha');
        self::assertSame(['success' => 0, 'failure' => 1, 'skipped' => 0], $summary);
        self::assertSame('usage down', $usage?->error);
        self::assertSame(10.0, $usage?->primary?->usedPercent);
    }

    public function testRefreshAllDoesNotClearCooldownForIncompleteUsageSnapshot(): void
    {
        $repository = new AccountRepository($this->tempDir('cap-usage-refresh-incomplete-accounts'));
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));
        $repository->saveAccount($account);
        $state = StateStore::memory();
        $state->setCooldown('acct-alpha', 2_000, 'quota', 900);

        $summary = (new AccountUsageRefresher(new RefresherIncompleteUsageClient()))->refreshAll($repository, $state, 1_000);

        $usage = $state->accountUsage('acct-alpha');
        self::assertSame(['success' => 0, 'failure' => 1, 'skipped' => 0], $summary);
        self::assertSame(2_000, $state->cooldownUntil('acct-alpha'));
        self::assertSame('quota', $state->cooldownReason('acct-alpha'));
        self::assertSame('usage endpoint returned incomplete Codex rate limit snapshot', $usage?->error);
    }

    public function testRefreshAllPersistsRefreshedTokensBeforeUsageFetch(): void
    {
        $repository = new AccountRepository($this->tempDir('cap-usage-refresh-token-accounts'));
        $account = (new AccountFileValidator())->validate($this->accountFixture('alpha'));
        $repository->saveAccount($account);
        $state = StateStore::memory();
        $usageClient = new RefresherFakeUsageClient();
        $freshAccessToken = $this->makeJwt([
            'iss' => 'https://auth.openai.com',
            'email' => 'alpha@example.com',
            'exp' => self::FIXTURE_EXP,
            'https://api.openai.com/auth' => [
                'chatgpt_account_id' => 'acct-alpha',
                'chatgpt_plan_type' => 'plus',
            ],
        ]);
        $fresh = $account->withTokens($account->idToken(), $freshAccessToken, 'refresh-fresh');

        $summary = (new AccountUsageRefresher($usageClient, static fn (CodexAccount $account): CodexAccount => $fresh))->refreshAll($repository, $state, 1_000);

        $stored = $repository->findByName('alpha');
        self::assertSame(['success' => 1, 'failure' => 0, 'skipped' => 0], $summary);
        self::assertSame($freshAccessToken, $usageClient->accessTokens[0] ?? null);
        self::assertSame($freshAccessToken, $stored?->accessToken());
    }
}

final class RefresherFakeUsageClient implements UsageClient
{
    /** @var list<string> */
    public array $accessTokens = [];

    public function fetch(CodexAccount $account): AccountUsage
    {
        $this->accessTokens[] = $account->accessToken();

        return new AccountUsage(
            'plus',
            new RateLimitWindow(93.0, 300, 1_300),
            new RateLimitWindow(15.0, 10080, 2_000),
        );
    }
}

final class RefresherFailingUsageClient implements UsageClient
{
    public function fetch(CodexAccount $account): AccountUsage
    {
        throw new RuntimeException('usage down');
    }
}

final class RefresherIncompleteUsageClient implements UsageClient
{
    public function fetch(CodexAccount $account): AccountUsage
    {
        return new AccountUsage('plus', new RateLimitWindow(93.0, 300, 1_300), null);
    }
}
