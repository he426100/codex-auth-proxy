<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests;

use CodexAuthProxy\Account\AccountFileValidator;
use CodexAuthProxy\Routing\Scheduler;
use CodexAuthProxy\Routing\StateStore;
use CodexAuthProxy\Usage\CachedAccountUsage;
use CodexAuthProxy\Usage\CachedRateLimitWindow;

final class SchedulerTest extends TestCase
{
    public function testKeepsSameAccountForSameSession(): void
    {
        $validator = new AccountFileValidator();
        $accounts = [
            $validator->validate($this->accountFixture('alpha')),
            $validator->validate($this->accountFixture('beta')),
        ];
        $scheduler = new Scheduler($accounts, StateStore::memory(), static fn (): int => 1000);

        $first = $scheduler->accountForSession('thread-1');
        $second = $scheduler->accountForSession('thread-1');

        self::assertSame('acct-alpha', $first->accountId());
        self::assertSame('acct-alpha', $second->accountId());
    }

    public function testSkipsCooldownAccountsForNewSessions(): void
    {
        $validator = new AccountFileValidator();
        $accounts = [
            $validator->validate($this->accountFixture('alpha')),
            $validator->validate($this->accountFixture('beta')),
        ];
        $state = StateStore::memory();
        $state->setCooldownUntil('acct-alpha', 2000);
        $scheduler = new Scheduler($accounts, $state, static fn (): int => 1000);

        $account = $scheduler->accountForSession('thread-2');

        self::assertSame('acct-beta', $account->accountId());
    }

    public function testMarksCurrentSessionAccountAsCoolingDownBeforeSwitching(): void
    {
        $validator = new AccountFileValidator();
        $accounts = [
            $validator->validate($this->accountFixture('alpha')),
            $validator->validate($this->accountFixture('beta')),
        ];
        $state = StateStore::memory();
        $scheduler = new Scheduler($accounts, $state, static fn (): int => 1000);

        $scheduler->accountForSession('thread-3');
        $replacement = $scheduler->switchAfterHardFailure('thread-3', 300);

        self::assertSame(1300, $state->cooldownUntil('acct-alpha'));
        self::assertSame('acct-beta', $replacement->accountId());
        self::assertSame('acct-beta', $scheduler->accountForSession('thread-3')->accountId());
    }

    public function testInheritsFallbackSessionBindingForMessageHashEvolution(): void
    {
        $validator = new AccountFileValidator();
        $accounts = [
            $validator->validate($this->accountFixture('alpha')),
            $validator->validate($this->accountFixture('beta')),
        ];
        $scheduler = new Scheduler($accounts, StateStore::memory(), static fn (): int => 1000);

        $first = $scheduler->accountForSession('msg:short');
        $second = $scheduler->accountForSession('msg:full', 'msg:short');

        self::assertSame('acct-alpha', $first->accountId());
        self::assertSame('acct-alpha', $second->accountId());
    }

    public function testSkipsAccountsWhoseCachedUsageIsExhausted(): void
    {
        $validator = new AccountFileValidator();
        $accounts = [
            $validator->validate($this->accountFixture('alpha')),
            $validator->validate($this->accountFixture('beta')),
        ];
        $state = StateStore::memory();
        $state->setAccountUsage('acct-alpha', new \CodexAuthProxy\Usage\CachedAccountUsage(
            'plus',
            1000,
            null,
            new \CodexAuthProxy\Usage\CachedRateLimitWindow(100.0, 0.0, 300, null),
            null,
        ));
        $scheduler = new Scheduler($accounts, $state, static fn (): int => 1000);

        $account = $scheduler->accountForSession('thread-4');

        self::assertSame('acct-beta', $account->accountId());
    }

    public function testSelectsAccountsWithoutUsageCache(): void
    {
        $validator = new AccountFileValidator();
        $accounts = [
            $validator->validate($this->accountFixture('alpha')),
            $validator->validate($this->accountFixture('beta')),
        ];
        $state = StateStore::memory();
        $state->setAccountUsage('acct-beta', new \CodexAuthProxy\Usage\CachedAccountUsage(
            'plus',
            1000,
            null,
            new \CodexAuthProxy\Usage\CachedRateLimitWindow(100.0, 0.0, 300, null),
            null,
        ));
        $scheduler = new Scheduler($accounts, $state, static fn (): int => 1000);

        $account = $scheduler->accountForSession('thread-5');

        self::assertSame('acct-alpha', $account->accountId());
    }

    public function testSelectsAccountsWhoseExhaustedUsageHasExpired(): void
    {
        $validator = new AccountFileValidator();
        $accounts = [
            $validator->validate($this->accountFixture('alpha')),
            $validator->validate($this->accountFixture('beta')),
        ];
        $state = StateStore::memory();
        $state->setAccountUsage('acct-alpha', new CachedAccountUsage(
            'plus',
            1000,
            null,
            new CachedRateLimitWindow(100.0, 0.0, 300, 1000),
            null,
        ));
        $scheduler = new Scheduler($accounts, $state, static fn (): int => 1000);

        $account = $scheduler->accountForSession('thread-6');

        self::assertSame('acct-alpha', $account->accountId());
    }

    public function testDemotesLowQuotaAccountsForNewSessions(): void
    {
        $validator = new AccountFileValidator();
        $accounts = [
            $validator->validate($this->accountFixture('alpha')),
            $validator->validate($this->accountFixture('beta')),
        ];
        $state = StateStore::memory();
        $state->setAccountUsage('acct-alpha', new CachedAccountUsage(
            'plus',
            1000,
            null,
            new CachedRateLimitWindow(97.0, 3.0, 300, 1300),
            new CachedRateLimitWindow(40.0, 60.0, 3600, 4600),
        ));
        $state->setAccountUsage('acct-beta', new CachedAccountUsage(
            'plus',
            1000,
            null,
            new CachedRateLimitWindow(10.0, 90.0, 300, 1300),
            new CachedRateLimitWindow(40.0, 60.0, 3600, 4600),
        ));
        $scheduler = new Scheduler($accounts, $state, static fn (): int => 1000);

        $account = $scheduler->accountForSession('thread-low-quota');

        self::assertSame('acct-beta', $account->accountId());
    }

    public function testStillSelectsLowQuotaAccountsWhenAllCandidatesAreLowQuota(): void
    {
        $validator = new AccountFileValidator();
        $accounts = [
            $validator->validate($this->accountFixture('alpha')),
            $validator->validate($this->accountFixture('beta')),
        ];
        $state = StateStore::memory();
        foreach (['acct-alpha', 'acct-beta'] as $accountId) {
            $state->setAccountUsage($accountId, new CachedAccountUsage(
                'plus',
                1000,
                null,
                new CachedRateLimitWindow(97.0, 3.0, 300, 1300),
                new CachedRateLimitWindow(40.0, 60.0, 3600, 4600),
            ));
        }
        $scheduler = new Scheduler($accounts, $state, static fn (): int => 1000);

        $account = $scheduler->accountForSession('thread-all-low-quota');

        self::assertSame('acct-alpha', $account->accountId());
    }

    public function testDoesNotSkipExhaustedAccountsAfterRefreshError(): void
    {
        $validator = new AccountFileValidator();
        $accounts = [
            $validator->validate($this->accountFixture('alpha')),
            $validator->validate($this->accountFixture('beta')),
        ];
        $state = StateStore::memory();
        $state->setAccountUsage('acct-alpha', new CachedAccountUsage(
            'plus',
            1000,
            null,
            new CachedRateLimitWindow(100.0, 0.0, 300, 1100),
            null,
        ));
        $state->setAccountUsageError('acct-alpha', 'upstream unavailable', 1001);
        $scheduler = new Scheduler($accounts, $state, static fn (): int => 1000);

        $availability = \CodexAuthProxy\Usage\AccountAvailability::from(
            $accounts[0],
            $state->cooldownUntil('acct-alpha'),
            $state->accountUsage('acct-alpha'),
            1000,
        );
        $account = $scheduler->accountForSession('thread-6');

        self::assertSame('usage_error', $availability->reason);
        self::assertFalse($availability->isConfirmedAvailable);
        self::assertTrue($availability->routable);
        self::assertSame('acct-alpha', $account->accountId());
    }

    public function testSelectsAccountsWithErrorOnlyUsageSnapshots(): void
    {
        $validator = new AccountFileValidator();
        $accounts = [
            $validator->validate($this->accountFixture('alpha')),
            $validator->validate($this->accountFixture('beta')),
        ];
        $state = StateStore::memory();
        $state->setAccountUsage('acct-alpha', new CachedAccountUsage(
            'plus',
            1000,
            'upstream unavailable',
            null,
            null,
        ));
        $scheduler = new Scheduler($accounts, $state, static fn (): int => 1000);

        $availability = \CodexAuthProxy\Usage\AccountAvailability::from(
            $accounts[0],
            $state->cooldownUntil('acct-alpha'),
            $state->accountUsage('acct-alpha'),
            1000,
        );
        $account = $scheduler->accountForSession('thread-7');

        self::assertSame('usage_error', $availability->reason);
        self::assertFalse($availability->isConfirmedAvailable);
        self::assertTrue($availability->routable);
        self::assertSame('acct-alpha', $account->accountId());
    }

    public function testReplacesBoundSessionAccountWithReloadedTokens(): void
    {
        $validator = new AccountFileValidator();
        $stale = $validator->validate($this->accountFixture('alpha'));
        $fresh = $stale->withTokens('id-fresh', 'access-fresh', 'refresh-fresh');
        $scheduler = new Scheduler([$stale], StateStore::memory(), static fn (): int => 1000);

        $bound = $scheduler->accountForSession('thread-reload');
        self::assertNotSame('access-fresh', $bound->accessToken());

        $scheduler->replaceAccounts([$fresh]);
        $reloaded = $scheduler->accountForSession('thread-reload');

        self::assertSame('acct-alpha', $reloaded->accountId());
        self::assertSame('access-fresh', $reloaded->accessToken());
    }
}
