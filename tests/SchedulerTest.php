<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests;

use CodexAuthProxy\Account\AccountFileValidator;
use CodexAuthProxy\Routing\Scheduler;
use CodexAuthProxy\Routing\StateStore;

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
}
