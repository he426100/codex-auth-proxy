<?php

declare(strict_types=1);

namespace CodexAuthProxy\Routing;

use CodexAuthProxy\Account\CodexAccount;
use CodexAuthProxy\Usage\AccountAvailability;
use RuntimeException;

final class Scheduler
{
    /** @var list<CodexAccount> */
    private array $accounts;

    /** @var array<string,CodexAccount> */
    private array $accountsById = [];

    /** @param list<CodexAccount> $accounts */
    public function __construct(array $accounts, private readonly StateStore $state, private readonly mixed $clock = null)
    {
        $this->accounts = $accounts;
        foreach ($this->accounts as $account) {
            $this->accountsById[$account->accountId()] = $account;
        }
    }

    public function accountForSession(string $sessionKey, ?string $fallbackSessionKey = null): CodexAccount
    {
        $boundAccountId = $this->state->sessionAccount($sessionKey);
        if ($boundAccountId !== null) {
            $bound = $this->accountsById[$boundAccountId] ?? null;
            if ($bound !== null && $this->isAvailable($bound)) {
                return $bound;
            }
        }

        if ($fallbackSessionKey !== null && $fallbackSessionKey !== $sessionKey) {
            $fallbackAccountId = $this->state->sessionAccount($fallbackSessionKey);
            $fallback = $fallbackAccountId !== null ? ($this->accountsById[$fallbackAccountId] ?? null) : null;
            if ($fallback !== null && $this->isAvailable($fallback)) {
                $this->state->bindSession($sessionKey, $fallback->accountId());
                return $fallback;
            }
        }

        $account = $this->selectAvailable();
        $this->state->bindSession($sessionKey, $account->accountId());

        return $account;
    }

    public function replaceAccount(CodexAccount $account): void
    {
        $this->accountsById[$account->accountId()] = $account;
        foreach ($this->accounts as $index => $existing) {
            if ($existing->accountId() === $account->accountId()) {
                $this->accounts[$index] = $account;
                return;
            }
        }
    }

    public function switchAfterHardFailure(string $sessionKey, int $cooldownSeconds): CodexAccount
    {
        $failedAccountId = $this->state->sessionAccount($sessionKey);
        if ($failedAccountId !== null) {
            $this->state->setCooldownUntil($failedAccountId, $this->now() + $cooldownSeconds);
        }

        $replacement = $this->selectAvailable($failedAccountId);
        $this->state->bindSession($sessionKey, $replacement->accountId());

        return $replacement;
    }

    private function selectAvailable(?string $excludeAccountId = null): CodexAccount
    {
        $count = count($this->accounts);
        if ($count === 0) {
            throw new RuntimeException('No Codex accounts configured');
        }

        $start = $this->state->cursor() % $count;
        for ($offset = 0; $offset < $count; $offset++) {
            $index = ($start + $offset) % $count;
            $account = $this->accounts[$index];
            if ($account->accountId() === $excludeAccountId) {
                continue;
            }
            if (!$this->isAvailable($account)) {
                continue;
            }

            $this->state->setCursor($index + 1);
            return $account;
        }

        throw new RuntimeException('No available Codex account');
    }

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

    private function now(): int
    {
        return is_callable($this->clock) ? (int) ($this->clock)() : time();
    }
}
