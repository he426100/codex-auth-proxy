<?php

declare(strict_types=1);

namespace CodexAuthProxy\Usage;

use CodexAuthProxy\Account\AccountRepository;
use CodexAuthProxy\Account\CodexAccount;
use CodexAuthProxy\Routing\StateStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

final class AccountUsageRefresher
{
    /** @param null|callable(CodexAccount):CodexAccount $refreshAccount */
    public function __construct(
        private readonly UsageClient $usageClient,
        private readonly mixed $refreshAccount = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    private readonly LoggerInterface $logger;

    /** @return array{success:int, failure:int, skipped:int} */
    public function refreshAll(AccountRepository $repository, StateStore $state, int $checkedAt): array
    {
        $summary = ['success' => 0, 'failure' => 0, 'skipped' => 0];
        foreach ($repository->load() as $account) {
            if (!$account->enabled()) {
                $summary['skipped']++;
                continue;
            }

            try {
                $account = $this->refreshAccountIfNeeded($repository, $account);
                $usage = $this->usageClient->fetch($account);
                $state->setAccountUsage($account->accountId(), CachedAccountUsage::fromLive($usage, $checkedAt));
                $state->setCooldownUntil($account->accountId(), 0);
                $summary['success']++;
            } catch (Throwable $throwable) {
                $state->setAccountUsageError($account->accountId(), $throwable->getMessage(), $checkedAt);
                $this->logger->warning('Failed to refresh Codex account usage', [
                    'account' => $account->name(),
                    'error' => $throwable->getMessage(),
                ]);
                $summary['failure']++;
            }
        }

        return $summary;
    }

    private function refreshAccountIfNeeded(AccountRepository $repository, CodexAccount $account): CodexAccount
    {
        if (!is_callable($this->refreshAccount)) {
            return $account;
        }

        $refreshed = ($this->refreshAccount)($account);
        if ($refreshed !== $account) {
            $repository->saveAccount($refreshed);
        }

        return $refreshed;
    }
}
