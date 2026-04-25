<?php

declare(strict_types=1);

namespace CodexAuthProxy\Routing;

use CodexAuthProxy\Account\CodexAccount;
use CodexAuthProxy\Usage\AccountAvailability;
use RuntimeException;

final class Scheduler
{
    private const DEFAULT_LOW_QUOTA_LEFT_THRESHOLD_PERCENT = 5.0;

    /** @var list<CodexAccount> */
    private array $accounts;

    /** @var array<string,CodexAccount> */
    private array $accountsById = [];

    /**
     * @var array{
     *   revision:string,
     *   by_index: array<int,array{
     *     index:int,
     *     account:CodexAccount,
     *     availability:AccountAvailability,
     *     low_quota:bool,
     *     quota_score:?float
     *   }>,
     *   by_account_id: array<string,array{
     *     index:int,
     *     account:CodexAccount,
     *     availability:AccountAvailability,
     *     low_quota:bool,
     *     quota_score:?float
     *   }>
     * }|null
     */
    private ?array $candidateSnapshot = null;

    /** @param list<CodexAccount> $accounts */
    public function __construct(
        array $accounts,
        private readonly StateStore $state,
        private readonly mixed $clock = null,
        private readonly float $lowQuotaLeftThresholdPercent = self::DEFAULT_LOW_QUOTA_LEFT_THRESHOLD_PERCENT,
    )
    {
        $this->accounts = $accounts;
        foreach ($this->accounts as $account) {
            $this->accountsById[$account->accountId()] = $account;
        }
    }

    public function accountForSession(string $sessionKey, ?string $fallbackSessionKey = null, array &$selection = [], ?string $preferredAccountId = null): CodexAccount
    {
        $snapshot = $this->candidateSnapshot();
        $boundAccountId = $this->state->sessionAccount($sessionKey);
        if ($preferredAccountId !== null && ($boundAccountId === null || $this->canResponseAffinityOverrideBinding($sessionKey))) {
            $preferred = $this->accountsById[$preferredAccountId] ?? null;
            if ($preferred !== null && $this->isAvailableInSnapshot($snapshot, $preferred->accountId())) {
                if ($boundAccountId === $preferred->accountId()) {
                    $this->state->touchSession($sessionKey, $this->now());
                    $selection = $this->selectionReport('bound_session', $preferred);
                    return $preferred;
                }

                $source = $boundAccountId !== null ? 'rebind_response_affinity' : 'response_affinity';
                $this->state->bindSession($sessionKey, $preferred->accountId(), $source, $this->now());
                $selection = $this->selectionReport($source, $preferred, $boundAccountId !== null ? [
                    'previous_account_id' => $boundAccountId,
                ] : []);

                return $preferred;
            }
        }

        if ($boundAccountId !== null) {
            $bound = $this->accountsById[$boundAccountId] ?? null;
            if ($bound !== null && $this->isAvailableInSnapshot($snapshot, $bound->accountId())) {
                $this->state->touchSession($sessionKey, $this->now());
                $selection = $this->selectionReport('bound_session', $bound);
                return $bound;
            }
        }

        if ($fallbackSessionKey !== null && $fallbackSessionKey !== $sessionKey) {
            $fallbackAccountId = $this->state->sessionAccount($fallbackSessionKey);
            $fallback = $fallbackAccountId !== null ? ($this->accountsById[$fallbackAccountId] ?? null) : null;
            if ($fallback !== null && $this->isAvailableInSnapshot($snapshot, $fallback->accountId())) {
                $source = 'fallback_binding';
                $this->state->bindSession($sessionKey, $fallback->accountId(), $source, $this->now());
                $selection = $this->selectionReport($source, $fallback, [
                    'fallback_session_key' => $fallbackSessionKey,
                ]);
                return $fallback;
            }
        }

        $orderedCandidates = [];
        $account = $this->selectAvailable(snapshot: $snapshot, orderedCandidates: $orderedCandidates, roundRobinWithinPriority: true);
        $source = $boundAccountId !== null ? 'rebind_unavailable_session' : 'new_session';
        $this->state->bindSession($sessionKey, $account->accountId(), $source, $this->now());
        $selection = $this->selectionReport(
            $source,
            $account,
            $boundAccountId !== null ? ['previous_account_id' => $boundAccountId] : [],
            $orderedCandidates,
        );

        return $account;
    }

    public function replaceAccount(CodexAccount $account): void
    {
        $accounts = $this->accounts;
        foreach ($this->accounts as $index => $existing) {
            if ($existing->accountId() === $account->accountId()) {
                $accounts[$index] = $account;
                $this->replaceAccounts($accounts);
                return;
            }
        }

        $accounts[] = $account;
        $this->replaceAccounts($accounts);
    }

    /** @param list<CodexAccount> $accounts */
    public function replaceAccounts(array $accounts): void
    {
        $this->accounts = $accounts;
        $this->accountsById = [];
        $this->candidateSnapshot = null;
        foreach ($this->accounts as $account) {
            $this->accountsById[$account->accountId()] = $account;
        }
    }

    public function switchAfterHardFailure(string $sessionKey, int $cooldownSeconds, ?string $cooldownReason = null, array &$selection = []): CodexAccount
    {
        $failedAccountId = $this->state->sessionAccount($sessionKey);
        if ($failedAccountId !== null) {
            $now = $this->now();
            $this->state->setCooldown($failedAccountId, $now + $cooldownSeconds, $cooldownReason, $now);
        }

        $orderedCandidates = [];
        $replacement = $this->selectAvailable($failedAccountId !== null ? [$failedAccountId] : [], null, $orderedCandidates, false);
        $source = 'hard_switch';
        $this->state->bindSession($sessionKey, $replacement->accountId(), $source, $this->now());
        $selection = $this->selectionReport($source, $replacement, [
            'previous_account_id' => $failedAccountId,
            'excluded_account_id' => $failedAccountId,
            'cooldown_reason' => $cooldownReason,
        ], $orderedCandidates);

        return $replacement;
    }

    /** @param list<string> $excludeAccountIds */
    public function switchAfterSoftFailure(string $sessionKey, array $excludeAccountIds = [], string $source = 'soft_switch', array &$selection = []): CodexAccount
    {
        $failedAccountId = $this->state->sessionAccount($sessionKey);
        $excluded = [];
        foreach ($excludeAccountIds as $accountId) {
            if ($accountId === '') {
                continue;
            }
            $excluded[$accountId] = true;
        }

        $orderedCandidates = [];
        $replacement = $this->selectAvailable(array_keys($excluded), null, $orderedCandidates, false);
        $this->state->bindSession($sessionKey, $replacement->accountId(), $source, $this->now());

        $context = [];
        if ($failedAccountId !== null) {
            $context['previous_account_id'] = $failedAccountId;
            if (isset($excluded[$failedAccountId])) {
                $context['excluded_account_id'] = $failedAccountId;
            }
        }
        $selection = $this->selectionReport($source, $replacement, $context, $orderedCandidates);

        return $replacement;
    }

    /**
     * @param array{
     *   revision:string,
     *   by_index: array<int,array{
     *     index:int,
     *     account:CodexAccount,
     *     availability:AccountAvailability,
     *     low_quota:bool,
     *     quota_score:?float
     *   }>,
     *   by_account_id: array<string,array{
     *     index:int,
     *     account:CodexAccount,
     *     availability:AccountAvailability,
     *     low_quota:bool,
     *     quota_score:?float
     *   }>
     * }|null $snapshot
     * @param list<array{
     *   account_id:string,
     *   account:string,
     *   priority:string,
     *   confirmed_available:bool,
     *   low_quota:bool,
     *   quota_score:?float
     * }> $orderedCandidates
     * @param-out list<array{
     *   account_id:string,
     *   account:string,
     *   priority:string,
     *   confirmed_available:bool,
     *   low_quota:bool,
     *   quota_score:?float
     * }> $orderedCandidates
     */
    private function selectAvailable(
        array $excludeAccountIds = [],
        ?array $snapshot = null,
        array &$orderedCandidates = [],
        bool $roundRobinWithinPriority = false,
    ): CodexAccount
    {
        $count = count($this->accounts);
        if ($count === 0) {
            throw new RuntimeException('No Codex accounts configured');
        }

        $snapshot ??= $this->candidateSnapshot();
        $candidates = [];
        for ($index = 0; $index < $count; $index++) {
            $entry = $snapshot['by_index'][$index] ?? null;
            if ($entry === null) {
                continue;
            }
            if (in_array($entry['account']->accountId(), $excludeAccountIds, true)) {
                continue;
            }

            $candidates[] = $entry;
        }

        if ($candidates === []) {
            throw new RuntimeException('No available Codex account');
        }

        usort($candidates, fn (array $left, array $right): int => $this->compareCandidates($left, $right));
        $preferredCandidates = $this->preferredCandidates($candidates);
        $orderedCandidates = [];
        foreach ($candidates as $candidate) {
            $orderedCandidates[] = $this->candidateTrace($candidate);
        }
        if (!$roundRobinWithinPriority) {
            return $preferredCandidates[0]['account'];
        }

        $cursor = $this->state->consumeCursor();
        $selected = $preferredCandidates[$cursor % count($preferredCandidates)];

        return $selected['account'];
    }

    /**
     * @return array{
     *   revision:string,
     *   by_index: array<int,array{
     *     index:int,
     *     account:CodexAccount,
     *     availability:AccountAvailability,
     *     low_quota:bool,
     *     quota_score:?float
     *   }>,
     *   by_account_id: array<string,array{
     *     index:int,
     *     account:CodexAccount,
     *     availability:AccountAvailability,
     *     low_quota:bool,
     *     quota_score:?float
     *   }>
     * }
     */
    private function candidateSnapshot(): array
    {
        $revision = $this->state->candidateRevision();
        if ($this->candidateSnapshot !== null && $this->candidateSnapshot['revision'] === $revision) {
            return $this->candidateSnapshot;
        }

        $byIndex = [];
        $byAccountId = [];
        foreach ($this->accounts as $index => $account) {
            $availability = $this->availability($account);
            if (!$availability->routable) {
                continue;
            }

            $entry = [
                'index' => $index,
                'account' => $account,
                'availability' => $availability,
                'low_quota' => $this->isLowQuota($availability),
                'quota_score' => $this->quotaScore($availability),
            ];
            $byIndex[$index] = $entry;
            $byAccountId[$account->accountId()] = $entry;
        }

        $this->candidateSnapshot = [
            'revision' => $revision,
            'by_index' => $byIndex,
            'by_account_id' => $byAccountId,
        ];

        return $this->candidateSnapshot;
    }

    private function isAvailableInSnapshot(array $snapshot, string $accountId): bool
    {
        return isset($snapshot['by_account_id'][$accountId]);
    }

    private function canResponseAffinityOverrideBinding(string $sessionKey): bool
    {
        return str_starts_with($sessionKey, 'previous_response_id:');
    }

    private function availability(CodexAccount $account): AccountAvailability
    {
        return AccountAvailability::from(
            $account,
            $this->state->cooldownUntil($account->accountId()),
            $this->state->accountUsage($account->accountId()),
            $this->now(),
        );
    }

    private function isLowQuota(AccountAvailability $availability): bool
    {
        $usage = $availability->usage;
        if ($usage === null || $usage->error !== null) {
            return false;
        }
        if ($this->lowQuotaLeftThresholdPercent <= 0.0) {
            return false;
        }

        foreach ([$usage->primary, $usage->secondary] as $window) {
            if ($window === null) {
                continue;
            }
            if ($window->leftPercent > 0.0 && $window->leftPercent <= $this->lowQuotaLeftThresholdPercent) {
                return true;
            }
        }

        return false;
    }

    private function quotaScore(AccountAvailability $availability): ?float
    {
        $usage = $availability->usage;
        if (!$availability->isConfirmedAvailable || $usage === null || $usage->error !== null) {
            return null;
        }
        if ($usage->primary === null || $usage->secondary === null) {
            return null;
        }

        return min($usage->primary->leftPercent, $usage->secondary->leftPercent);
    }

    /** @param array{availability:AccountAvailability,low_quota:bool,quota_score:?float} $candidate */
    private function candidatePriority(array $candidate): int
    {
        if ($candidate['low_quota']) {
            return 2;
        }
        if ($candidate['quota_score'] !== null) {
            return 0;
        }

        return 1;
    }

    /**
     * @param array{index:int,availability:AccountAvailability,low_quota:bool,quota_score:?float} $left
     * @param array{index:int,availability:AccountAvailability,low_quota:bool,quota_score:?float} $right
     */
    private function compareCandidates(array $left, array $right): int
    {
        $priority = $this->candidatePriority($left) <=> $this->candidatePriority($right);
        if ($priority !== 0) {
            return $priority;
        }

        $leftScore = $left['quota_score'];
        $rightScore = $right['quota_score'];
        if ($leftScore !== $rightScore) {
            if ($leftScore === null) {
                return 1;
            }
            if ($rightScore === null) {
                return -1;
            }

            return $rightScore <=> $leftScore;
        }

        return $left['index'] <=> $right['index'];
    }

    /**
     * @param list<array{
     *   index:int,
     *   account:CodexAccount,
     *   availability:AccountAvailability,
     *   low_quota:bool,
     *   quota_score:?float
     * }> $candidates
     * @return list<array{
     *   index:int,
     *   account:CodexAccount,
     *   availability:AccountAvailability,
     *   low_quota:bool,
     *   quota_score:?float
     * }>
     */
    private function preferredCandidates(array $candidates): array
    {
        $bestPriority = $this->candidatePriority($candidates[0]);

        return array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => $this->candidatePriority($candidate) === $bestPriority,
        ));
    }

    private function selectionReport(string $source, CodexAccount $account, array $context = [], array $candidates = []): array
    {
        $report = [
            'source' => $source,
            'selected_account_id' => $account->accountId(),
            'selected_account_name' => $account->name(),
        ];

        foreach (['previous_account_id', 'excluded_account_id', 'cooldown_reason', 'fallback_session_key'] as $key) {
            if (isset($context[$key]) && is_string($context[$key]) && $context[$key] !== '') {
                $report[$key] = $context[$key];
            }
        }
        if ($candidates !== []) {
            $report['candidates'] = $candidates;
        }

        return $report;
    }

    /**
     * @param array{account:CodexAccount,availability:AccountAvailability,low_quota:bool,quota_score:?float} $candidate
     * @return array{
     *   account_id:string,
     *   account:string,
     *   priority:string,
     *   confirmed_available:bool,
     *   low_quota:bool,
     *   quota_score:?float
     * }
     */
    private function candidateTrace(array $candidate): array
    {
        return [
            'account_id' => $candidate['account']->accountId(),
            'account' => $candidate['account']->name(),
            'priority' => $this->candidatePriorityLabel($candidate),
            'confirmed_available' => $candidate['availability']->isConfirmedAvailable,
            'low_quota' => $candidate['low_quota'],
            'quota_score' => $candidate['quota_score'],
        ];
    }

    /** @param array{availability:AccountAvailability,low_quota:bool,quota_score:?float} $candidate */
    private function candidatePriorityLabel(array $candidate): string
    {
        if ($candidate['low_quota']) {
            return 'low_quota';
        }
        if ($candidate['quota_score'] !== null) {
            return 'confirmed_available';
        }

        return 'usage_unknown';
    }

    private function now(): int
    {
        return is_callable($this->clock) ? (int) ($this->clock)() : time();
    }
}
