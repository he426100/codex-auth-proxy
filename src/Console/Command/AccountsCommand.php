<?php

declare(strict_types=1);

namespace CodexAuthProxy\Console\Command;

use CodexAuthProxy\Account\AccountRepository;
use CodexAuthProxy\Account\CodexAccount;
use CodexAuthProxy\Auth\TokenRefresher;
use CodexAuthProxy\Codex\CodexRuntimeProfile;
use CodexAuthProxy\Config\AppConfig;
use CodexAuthProxy\Config\AppConfigLoader;
use CodexAuthProxy\Network\OutboundProxyConfig;
use CodexAuthProxy\Routing\Scheduler;
use CodexAuthProxy\Routing\StateStore;
use CodexAuthProxy\Usage\AccountAvailability;
use CodexAuthProxy\Usage\AccountUsage;
use CodexAuthProxy\Usage\CachedAccountUsage;
use CodexAuthProxy\Usage\CachedRateLimitWindow;
use CodexAuthProxy\Usage\CodexUsageClient;
use CodexAuthProxy\Usage\RateLimitWindow;
use CodexAuthProxy\Usage\UsageClient;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'accounts', description: 'List, inspect, and delete proxy account files')]
final class AccountsCommand extends ProxyCommand
{
    public function __construct(
        AppConfigLoader $configLoader,
        private readonly ?UsageClient $usageClient = null,
        private readonly ?TokenRefresher $tokenRefresher = null,
    ) {
        parent::__construct($configLoader);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action: list, bindings, pick, refresh, status, or delete', 'list')
            ->addArgument('name', InputArgument::OPTIONAL, 'Account name for refresh/status/delete, or session key for bindings/pick')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Filter bindings by account name or account id')
            ->addOption('activity', null, InputOption::VALUE_REQUIRED, 'Filter bindings by activity: active, stale, or unknown')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip delete confirmation prompt');
        $this->addPathOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = strtolower((string) $input->getArgument('action'));
        $config = $this->appConfig($input);
        $repository = new AccountRepository($config->accountsDir);

        return match ($action) {
            'list' => $this->listAccounts($repository, $config, $input, $output),
            'bindings' => $this->bindings($repository, $config, $input, $output),
            'pick' => $this->pick($repository, $config, $input, $output),
            'refresh' => $this->refresh($repository, $config, $input, $output),
            'status' => $this->status($repository, $config, $input, $output),
            'delete' => $this->delete($repository, $input, $output),
            default => throw new InvalidArgumentException('Action must be list, bindings, pick, refresh, status, or delete'),
        };
    }

    private function listAccounts(AccountRepository $repository, AppConfig $config, InputInterface $input, OutputInterface $output): int
    {
        $accounts = $repository->load();
        $state = $this->stateStore($config);
        $selectionPreview = $this->selectionPreview($accounts, $state);
        $bindingSummary = $this->bindingSummary($state, $config->activeSessionWindowSeconds);

        if ((bool) $input->getOption('json')) {
            $rows = array_map(fn (CodexAccount $account): array => $this->accountDashboardJson($account, $state, $selectionPreview, $bindingSummary), $accounts);
            $output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($accounts === []) {
            $output->writeln('No accounts found.');

            return self::SUCCESS;
        }

        $rows = array_map(fn (CodexAccount $account): array => $this->accountDashboardRow($account, $state, $selectionPreview, $bindingSummary), $accounts);
        $this->renderSelectionPreviewSummary($output, $selectionPreview);
        $table = new Table($output);
        $table->setHeaders(['Next', 'Name', 'Email', 'Plan', 'Sess', '5h left', 'Weekly left', 'Available', 'Reason']);
        foreach ($rows as $row) {
            $table->addRow([
                $row['selected_for_new_session'],
                $row['name'],
                $row['email'],
                $row['plan'],
                $row['session_count'],
                $row['five_hour_left'],
                $row['weekly_left'],
                $row['available'],
                $row['reason'],
            ]);
        }
        $table->render();

        return self::SUCCESS;
    }

    private function refresh(AccountRepository $repository, AppConfig $config, InputInterface $input, OutputInterface $output): int
    {
        $results = $this->fetchLiveUsageResults($repository, $config, $this->stringArgument($input, 'name'));
        $failed = $this->hasFailure($results);
        $state = $this->stateStore($config);

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(array_map(fn (array $result): array => $this->statusJson($result), $results), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        foreach ($results as $result) {
            /** @var CodexAccount $account */
            $account = $result['account'];
            if ($result['usage'] instanceof AccountUsage) {
                $output->writeln($account->name() . ': success');

                continue;
            }

            $output->writeln($account->name() . ': failure (' . $result['error'] . ')');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function pick(AccountRepository $repository, AppConfig $config, InputInterface $input, OutputInterface $output): int
    {
        $sessionKey = $this->stringArgument($input, 'name');
        $accounts = $repository->load();
        if ($accounts === []) {
            throw new InvalidArgumentException('No accounts found');
        }

        $liveState = $this->stateStore($config);
        $selectionPreview = $this->selectionPreview($accounts, $liveState, $sessionKey);
        if (!$selectionPreview['account'] instanceof CodexAccount) {
            throw new RuntimeException($selectionPreview['error'] ?? 'No available Codex account');
        }
        $selection = $selectionPreview['selection'];
        $account = $selectionPreview['account'];
        $snapshot = $this->accountDashboardSnapshot($account, $liveState);

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode($this->pickJson($sessionKey, $selection, $account, $snapshot), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $output->writeln('Session: ' . ($sessionKey ?? '(new session)'));
        $output->writeln('Source: ' . ($selection['source'] ?? 'unknown'));
        $output->writeln('Selected: ' . $this->accountLabel($account, null));
        $output->writeln('Available: ' . $this->formatAvailability($snapshot['availability']));
        $output->writeln('Reason: ' . $this->formatAvailabilityReason($snapshot['availability']->reason, $snapshot['usage']?->error, $snapshot['cooldown_reason']));
        $output->writeln('Cooldown: ' . $this->formatCooldown($snapshot['cooldown_until'], $snapshot['now']));
        if (!isset($selection['candidates']) || !is_array($selection['candidates']) || $selection['candidates'] === []) {
            return self::SUCCESS;
        }

        $output->writeln('');
        $table = new Table($output);
        $table->setHeaders(['Rank', 'Account', 'Priority', 'Confirmed', 'Low quota', 'Quota score']);
        foreach (array_values($selection['candidates']) as $index => $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $quotaScore = $candidate['quota_score'] ?? null;

            $table->addRow([
                $index + 1,
                (string) ($candidate['account'] ?? $candidate['account_id'] ?? '-'),
                (string) ($candidate['priority'] ?? '-'),
                (($candidate['confirmed_available'] ?? false) === true) ? 'yes' : 'no',
                (($candidate['low_quota'] ?? false) === true) ? 'yes' : 'no',
                (is_float($quotaScore) || is_int($quotaScore))
                    ? $this->formatPercent((float) $quotaScore)
                    : '-',
            ]);
        }
        $table->render();

        return self::SUCCESS;
    }

    private function bindings(AccountRepository $repository, AppConfig $config, InputInterface $input, OutputInterface $output): int
    {
        $state = $this->stateStore($config);
        $rows = $this->bindingRows(
            $repository,
            $state,
            $this->stringArgument($input, 'name'),
            $config->activeSessionWindowSeconds,
            $this->bindingAccountFilter($input),
            $this->bindingActivityFilter($input),
        );

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(array_map(fn (array $row): array => $this->bindingJson($row), $rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $output->writeln('No session bindings found.');

            return self::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Session', 'Account', 'Email', 'Plan', 'Source', 'Seen', 'Available', 'Reason', 'Cooldown', 'Checked at']);
        foreach ($rows as $row) {
            $table->addRow([
                $row['session_key'],
                $row['account_name'],
                $row['email'],
                $row['plan'],
                $row['selection_source'],
                $row['seen'],
                $row['available'],
                $row['reason'],
                $row['cooldown'],
                $row['checked_at'],
            ]);
        }
        $table->render();

        return self::SUCCESS;
    }

    private function status(AccountRepository $repository, AppConfig $config, InputInterface $input, OutputInterface $output): int
    {
        $results = $this->fetchLiveUsageResults($repository, $config, $this->stringArgument($input, 'name'));
        $failed = $this->hasFailure($results);
        $state = $this->stateStore($config);

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(array_map(fn (array $result): array => $this->statusJson($result), $results), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        foreach ($results as $index => $result) {
            if ($index > 0) {
                $output->writeln('');
            }
            /** @var CodexAccount $account */
            $account = $result['account'];
            $usage = $result['usage'];
            $output->writeln($account->name());
            if (!$usage instanceof AccountUsage) {
                $output->writeln('  Account: ' . $this->accountLabel($account, null));
                $output->writeln('  Status: unavailable (' . $result['error'] . ')');
                $snapshot = $this->accountDashboardSnapshot($account, $state);
                if ($snapshot['last_cooldown_reason'] !== null || $snapshot['last_cooldown_at'] !== null) {
                    $output->writeln('  Last cooldown: ' . $this->formatLastCooldown($snapshot['last_cooldown_reason'], $snapshot['last_cooldown_at']));
                }
                continue;
            }
            $snapshot = $this->accountDashboardSnapshot($account, $state);
            $output->writeln('  Account: ' . $this->accountLabel($account, $usage));
            $output->writeln('  ' . $this->limitLine('5h limit', $usage->primary));
            $output->writeln('  ' . $this->limitLine('Weekly limit', $usage->secondary));
            if ($snapshot['last_cooldown_reason'] !== null || $snapshot['last_cooldown_at'] !== null) {
                $output->writeln('  Last cooldown: ' . $this->formatLastCooldown($snapshot['last_cooldown_reason'], $snapshot['last_cooldown_at']));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<array{account:CodexAccount,usage:?AccountUsage,error:?string}> */
    private function fetchLiveUsageResults(AccountRepository $repository, AppConfig $config, ?string $name): array
    {
        $accounts = $this->selectedAccounts($repository, $name);
        $outboundProxyConfig = OutboundProxyConfig::fromAppConfig($config);
        $runtimeProfile = CodexRuntimeProfile::fromAppConfig($config);
        $client = $this->usageClient ?? new CodexUsageClient(
            baseUrl: $config->usageBaseUrl,
            runtimeProfile: $runtimeProfile,
            proxyEnv: $outboundProxyConfig->environment(),
        );
        $state = $this->stateStore($config);
        $results = [];

        foreach ($accounts as $account) {
            $checkedAt = time();

            try {
                $account = $this->refreshAccountIfNeeded($repository, $account, $config);
                $usage = $client->fetch($account);
                $this->recordLiveUsageSuccess($state, $account, $usage, $checkedAt);
                $results[] = ['account' => $account, 'usage' => $usage, 'error' => null];
            } catch (InvalidArgumentException|RuntimeException $exception) {
                if ($exception instanceof RuntimeException && $this->isInvalidatedTokenFailure($exception)) {
                    try {
                        $account = $this->refreshAccount($repository, $account, $config);
                        $usage = $client->fetch($account);
                        $this->recordLiveUsageSuccess($state, $account, $usage, $checkedAt);
                        $results[] = ['account' => $account, 'usage' => $usage, 'error' => null];

                        continue;
                    } catch (InvalidArgumentException|RuntimeException $retryException) {
                        $exception = $retryException;
                    }
                }

                $state->setAccountUsageError($account->accountId(), $exception->getMessage(), $checkedAt);
                $results[] = ['account' => $account, 'usage' => null, 'error' => $exception->getMessage()];
            }
        }

        return $results;
    }

    private function recordLiveUsageSuccess(StateStore $state, CodexAccount $account, AccountUsage $usage, int $checkedAt): void
    {
        $state->setAccountUsage($account->accountId(), CachedAccountUsage::fromLive($usage, $checkedAt));
        $state->setCooldownUntil($account->accountId(), 0);
    }

    private function delete(AccountRepository $repository, InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'name');
        if ($name === null) {
            throw new InvalidArgumentException('Account name is required for delete');
        }

        $account = $repository->findByName($name);
        if ($account === null) {
            throw new InvalidArgumentException('Account not found: ' . $name);
        }

        if (!(bool) $input->getOption('yes') && !$this->confirmed($input, $output, $account)) {
            $output->writeln('Delete aborted.');

            return self::SUCCESS;
        }

        $archivedPath = $repository->deleteByName($name);
        $output->writeln('Archived account ' . $name . ' to ' . $archivedPath);

        return self::SUCCESS;
    }

    /** @return list<CodexAccount> */
    private function selectedAccounts(AccountRepository $repository, ?string $name): array
    {
        if ($name === null) {
            $accounts = $repository->load();
            if ($accounts === []) {
                throw new InvalidArgumentException('No accounts found');
            }

            return $accounts;
        }

        $account = $repository->findByName($name);
        if ($account === null) {
            throw new InvalidArgumentException('Account not found: ' . $name);
        }

        return [$account];
    }

    private function refreshAccountIfNeeded(AccountRepository $repository, CodexAccount $account, AppConfig $config): CodexAccount
    {
        $refresher = $this->tokenRefresher($config);
        $refreshed = $refresher->refreshIfExpiring($account);
        if ($refreshed === null) {
            return $account;
        }

        $repository->saveAccount($refreshed);

        return $refreshed;
    }

    private function refreshAccount(AccountRepository $repository, CodexAccount $account, AppConfig $config): CodexAccount
    {
        $refresher = $this->tokenRefresher($config);
        $refreshed = $refresher->refresh($account);
        $repository->saveAccount($refreshed);

        return $refreshed;
    }

    private function tokenRefresher(AppConfig $config): TokenRefresher
    {
        return $this->tokenRefresher ?? new TokenRefresher(proxy: OutboundProxyConfig::fromAppConfig($config)->guzzleProxy());
    }

    private function isInvalidatedTokenFailure(RuntimeException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'token_invalidated') || str_contains($message, '401 Unauthorized');
    }

    private function confirmed(InputInterface $input, OutputInterface $output, CodexAccount $account): bool
    {
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            throw new RuntimeException('Question helper is unavailable');
        }
        $question = new ConfirmationQuestion('Archive account ' . $account->name() . ' (' . $account->email() . ')? [y/N] ', false);

        return (bool) $helper->ask($input, $output, $question);
    }

    private function accountLabel(CodexAccount $account, ?AccountUsage $usage): string
    {
        $email = $account->email() !== '' ? $account->email() : $account->accountId();
        $planType = $usage instanceof AccountUsage && $usage->planType !== '' ? $usage->planType : $account->planType();

        return $email . ' (' . $this->displayPlan($planType) . ')';
    }

    private function limitLine(string $label, ?RateLimitWindow $window): string
    {
        if ($window === null) {
            return $label . ': unavailable';
        }

        $line = $label . ': ' . $this->formatPercent($window->leftPercent()) . '% left';
        if ($window->resetsAt !== null) {
            $line .= ' (resets ' . date('Y-m-d H:i', $window->resetsAt) . ')';
        }

        return $line;
    }

    private function displayPlan(string $planType): string
    {
        $planType = trim($planType);
        if ($planType === '') {
            return 'Unknown';
        }

        return ucfirst(strtolower($planType));
    }

    private function formatPercent(float $value): string
    {
        $rounded = round($value, 1);

        return rtrim(rtrim(number_format($rounded, 1, '.', ''), '0'), '.');
    }

    /** @return array<string,mixed> */
    private function accountJson(CodexAccount $account): array
    {
        return [
            'name' => $account->name(),
            'email' => $account->email(),
            'plan_type' => $account->planType(),
            'enabled' => $account->enabled(),
            'account_id' => $account->accountId(),
            'file' => $account->sourcePath(),
        ];
    }

    /** @param array{selected_account_id:?string,ranks:array<string,int>,priorities:array<string,string>,selection:array<string,mixed>,account:?CodexAccount,error:?string} $selectionPreview
     *  @param array<string,array{session_count:int,binding_count:int,last_selection_source:?string,last_bound_at:?int,last_seen_at:?int}> $bindingSummary
     *  @return array<string,mixed>
     */
    private function accountDashboardRow(CodexAccount $account, StateStore $state, array $selectionPreview, array $bindingSummary): array
    {
        $snapshot = $this->accountDashboardSnapshot($account, $state);
        $availability = $snapshot['availability'];
        $usage = $snapshot['usage'];
        $cooldownUntil = $snapshot['cooldown_until'];
        $now = $snapshot['now'];
        $cooldownReason = $snapshot['cooldown_reason'];
        $accountId = $account->accountId();
        $rank = $selectionPreview['ranks'][$accountId] ?? null;
        $binding = $bindingSummary[$accountId] ?? ['session_count' => 0, 'binding_count' => 0, 'last_selection_source' => null, 'last_bound_at' => null, 'last_seen_at' => null];

        return [
            'selection_rank' => $rank !== null ? (string) $rank : '-',
            'selected_for_new_session' => ($selectionPreview['selected_account_id'] ?? null) === $accountId ? 'yes' : '-',
            'name' => $account->name(),
            'email' => $account->email() !== '' ? $account->email() : '-',
            'plan' => $this->displayPlan($snapshot['plan_type']),
            'session_count' => (string) $binding['session_count'],
            'last_selection_source' => $binding['last_selection_source'] ?? '-',
            'last_bound_at' => $this->formatTimestamp($binding['last_bound_at']),
            'enabled' => $account->enabled() ? 'yes' : 'no',
            'cooldown' => $this->formatCooldown($cooldownUntil, $now),
            'five_hour_left' => $this->formatCachedWindowPercent($usage?->primary),
            'weekly_left' => $this->formatCachedWindowPercent($usage?->secondary),
            'available' => $this->formatAvailability($availability),
            'reason' => $this->formatAvailabilityReason($availability->reason, $usage?->error, $cooldownReason),
            'checked_at' => $this->formatTimestamp($usage?->checkedAt),
        ];
    }

    /** @param array{selected_account_id:?string,ranks:array<string,int>,priorities:array<string,string>,selection:array<string,mixed>,account:?CodexAccount,error:?string} $selectionPreview
     *  @param array<string,array{session_count:int,binding_count:int,last_selection_source:?string,last_bound_at:?int,last_seen_at:?int}> $bindingSummary
     *  @return array<string,mixed>
     */
    private function accountDashboardJson(CodexAccount $account, StateStore $state, array $selectionPreview, array $bindingSummary): array
    {
        $snapshot = $this->accountDashboardSnapshot($account, $state);
        $availability = $snapshot['availability'];
        $usage = $snapshot['usage'];
        $cooldownUntil = $snapshot['cooldown_until'];
        $cooldownReason = $snapshot['cooldown_reason'];
        $accountId = $account->accountId();
        $binding = $bindingSummary[$accountId] ?? ['session_count' => 0, 'binding_count' => 0, 'last_selection_source' => null, 'last_bound_at' => null, 'last_seen_at' => null];

        return [
            'name' => $account->name(),
            'email' => $account->email(),
            'plan_type' => $snapshot['plan_type'],
            'enabled' => $account->enabled(),
            'account_id' => $account->accountId(),
            'session_count' => $binding['session_count'],
            'binding_count' => $binding['binding_count'],
            'last_selection_source' => $binding['last_selection_source'],
            'last_bound_at' => $binding['last_bound_at'],
            'last_seen_at' => $binding['last_seen_at'],
            'selection_rank' => $selectionPreview['ranks'][$accountId] ?? null,
            'selected_for_new_session' => ($selectionPreview['selected_account_id'] ?? null) === $accountId,
            'selection_priority' => $selectionPreview['priorities'][$accountId] ?? null,
            'cooldown_until' => $cooldownUntil > 0 ? $cooldownUntil : null,
            'cooldown_reason' => $cooldownReason,
            'last_cooldown_reason' => $snapshot['last_cooldown_reason'],
            'last_cooldown_at' => $snapshot['last_cooldown_at'],
            'primary_left_percent' => $usage?->primary?->leftPercent,
            'secondary_left_percent' => $usage?->secondary?->leftPercent,
            'is_confirmed_available' => $availability->isConfirmedAvailable,
            'routable' => $availability->routable,
            'availability_reason' => $availability->reason,
            'error' => $usage?->error,
            'checked_at' => $usage?->checkedAt,
            'file' => $account->sourcePath(),
        ];
    }

    /** @return array{usage:?CachedAccountUsage,availability:AccountAvailability,cooldown_until:int,cooldown_reason:?string,last_cooldown_reason:?string,last_cooldown_at:?int,plan_type:string,now:int} */
    private function accountDashboardSnapshot(CodexAccount $account, StateStore $state): array
    {
        $now = time();
        $usage = $state->accountUsage($account->accountId());
        $cooldownUntil = $state->cooldownUntil($account->accountId());
        $cooldownReason = $state->cooldownReason($account->accountId());
        $lastCooldownReason = $state->lastCooldownReason($account->accountId());
        $lastCooldownAt = $state->lastCooldownAt($account->accountId());
        $availability = AccountAvailability::from($account, $cooldownUntil, $usage, $now);
        $planType = $account->planType();
        if ($usage instanceof CachedAccountUsage && $usage->planType !== '') {
            $planType = $usage->planType;
        }

        return [
            'usage' => $usage,
            'availability' => $availability,
            'cooldown_until' => $cooldownUntil,
            'cooldown_reason' => $cooldownReason,
            'last_cooldown_reason' => $lastCooldownReason,
            'last_cooldown_at' => $lastCooldownAt,
            'plan_type' => $planType,
            'now' => $now,
        ];
    }

    /**
     * @return list<array{
     *   session_key:string,
     *   account_id:string,
     *   account_name:string,
     *   email:string,
     *   plan:string,
     *   available:string,
     *   reason:string,
     *   cooldown:string,
     *   checked_at:string,
     *   selection_source:string,
     *   bound_at:string,
     *   last_seen_at:string,
     *   seen:string,
     *   activity:string,
     *   is_active:?bool,
     *   cooldown_until:?int,
     *   cooldown_reason:?string,
     *   selection_source_raw:?string,
     *   bound_at_raw:?int,
     *   last_seen_at_raw:?int,
     *   availability_reason:string,
     *   routable:bool
     * }>
     */
    private function bindingRows(AccountRepository $repository, StateStore $state, ?string $sessionFilter, int $activeSessionWindowSeconds, ?string $accountFilter = null, ?string $activityFilter = null): array
    {
        $accountsById = [];
        foreach ($repository->load() as $account) {
            $accountsById[$account->accountId()] = $account;
        }

        $rows = [];
        $now = time();
        foreach ($state->allSessionBindings() as $sessionKey => $binding) {
            if ($sessionFilter !== null && $sessionKey !== $sessionFilter) {
                continue;
            }

            $seenAt = $this->bindingSeenAt($binding);
            $activity = $this->bindingActivity($binding, $now, $activeSessionWindowSeconds);
            $accountId = $binding['account_id'];
            $account = $accountsById[$accountId] ?? null;
            if (!$account instanceof CodexAccount) {
                $rows[] = [
                    'session_key' => $sessionKey,
                    'account_id' => $accountId,
                    'account_name' => '-',
                    'email' => '-',
                    'plan' => 'Unknown',
                    'available' => 'unknown',
                    'reason' => 'account_missing',
                    'cooldown' => '-',
                    'checked_at' => '-',
                    'selection_source' => $binding['selection_source'] ?? '-',
                    'bound_at' => $this->formatTimestamp($binding['bound_at'] ?? null),
                    'last_seen_at' => $this->formatTimestamp($seenAt),
                    'seen' => $this->formatBindingSeen($activity['label'], $seenAt),
                    'activity' => $activity['label'],
                    'is_active' => $activity['is_active'],
                    'cooldown_until' => null,
                    'cooldown_reason' => null,
                    'selection_source_raw' => $binding['selection_source'] ?? null,
                    'bound_at_raw' => $binding['bound_at'] ?? null,
                    'last_seen_at_raw' => $seenAt,
                    'availability_reason' => 'account_missing',
                    'routable' => false,
                ];
                continue;
            }

            $snapshot = $this->accountDashboardSnapshot($account, $state);
            $availability = $snapshot['availability'];
            $usage = $snapshot['usage'];
            $rows[] = [
                'session_key' => $sessionKey,
                'account_id' => $accountId,
                'account_name' => $account->name(),
                'email' => $account->email() !== '' ? $account->email() : '-',
                'plan' => $this->displayPlan($snapshot['plan_type']),
                'available' => $this->formatAvailability($availability),
                'reason' => $this->formatAvailabilityReason($availability->reason, $usage?->error, $snapshot['cooldown_reason']),
                'cooldown' => $this->formatCooldown($snapshot['cooldown_until'], $snapshot['now']),
                'checked_at' => $this->formatTimestamp($usage?->checkedAt),
                'selection_source' => $binding['selection_source'] ?? '-',
                'bound_at' => $this->formatTimestamp($binding['bound_at'] ?? null),
                'last_seen_at' => $this->formatTimestamp($seenAt),
                'seen' => $this->formatBindingSeen($activity['label'], $seenAt),
                'activity' => $activity['label'],
                'is_active' => $activity['is_active'],
                'cooldown_until' => $snapshot['cooldown_until'] > 0 ? $snapshot['cooldown_until'] : null,
                'cooldown_reason' => $snapshot['cooldown_reason'],
                'selection_source_raw' => $binding['selection_source'] ?? null,
                'bound_at_raw' => $binding['bound_at'] ?? null,
                'last_seen_at_raw' => $seenAt,
                'availability_reason' => $availability->reason,
                'routable' => $availability->routable,
            ];
        }

        if ($accountFilter !== null) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => $this->matchesBindingAccountFilter($row, $accountFilter)));
        }
        if ($activityFilter !== null) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['activity'] === $activityFilter));
        }

        usort($rows, static fn (array $left, array $right): int => strcmp((string) $left['session_key'], (string) $right['session_key']));

        return $rows;
    }

    /** @param array<string,mixed> $row */
    private function bindingJson(array $row): array
    {
        return [
            'session_key' => $row['session_key'],
            'account_id' => $row['account_id'],
            'account_name' => $row['account_name'],
            'email' => $row['email'] !== '-' ? $row['email'] : '',
            'plan_type' => strtolower((string) $row['plan']),
            'routable' => $row['routable'],
            'available' => $row['available'],
            'availability_reason' => $row['availability_reason'],
            'cooldown_until' => $row['cooldown_until'],
            'cooldown_reason' => $row['cooldown_reason'],
            'selection_source' => $row['selection_source_raw'],
            'bound_at' => $row['bound_at_raw'],
            'last_seen_at' => $row['last_seen_at_raw'],
            'activity' => $row['activity'],
            'is_active' => $row['is_active'],
            'checked_at' => $this->parseTimestamp($row['checked_at']),
        ];
    }

    /** @param array{selected_account_id:?string,ranks:array<string,int>,priorities:array<string,string>,selection:array<string,mixed>,account:?CodexAccount,error:?string} $selectionPreview */
    private function renderSelectionPreviewSummary(OutputInterface $output, array $selectionPreview): void
    {
        if ($selectionPreview['error'] !== null) {
            $output->writeln('Next new session: unavailable (' . $selectionPreview['error'] . ')');
            $output->writeln('');

            return;
        }

        $account = $selectionPreview['account'];
        if (!$account instanceof CodexAccount) {
            return;
        }

        $output->writeln('Next new session: ' . $account->name());
        $output->writeln('');
    }

    /**
     * @return array<string,array{session_count:int,binding_count:int,last_selection_source:?string,last_bound_at:?int,last_seen_at:?int}>
     */
    private function bindingSummary(StateStore $state, int $activeSessionWindowSeconds): array
    {
        $summary = [];
        $now = time();
        foreach ($state->allSessionBindings() as $binding) {
            $accountId = $binding['account_id'];
            if (!isset($summary[$accountId])) {
                $summary[$accountId] = [
                    'session_count' => 0,
                    'binding_count' => 0,
                    'last_selection_source' => null,
                    'last_bound_at' => null,
                    'last_seen_at' => null,
                ];
            }

            $summary[$accountId]['binding_count']++;
            if ($this->isBindingActive($binding, $now, $activeSessionWindowSeconds)) {
                $summary[$accountId]['session_count']++;
            }
            $boundAt = $binding['bound_at'];
            if ($boundAt !== null && ($summary[$accountId]['last_bound_at'] === null || $boundAt >= $summary[$accountId]['last_bound_at'])) {
                $summary[$accountId]['last_bound_at'] = $boundAt;
                $summary[$accountId]['last_selection_source'] = $binding['selection_source'];
            }
            $lastSeenAt = $this->bindingSeenAt($binding);
            if ($lastSeenAt !== null && ($summary[$accountId]['last_seen_at'] === null || $lastSeenAt >= $summary[$accountId]['last_seen_at'])) {
                $summary[$accountId]['last_seen_at'] = $lastSeenAt;
            }
        }

        return $summary;
    }

    /** @param array{account_id:string,selection_source:?string,bound_at:?int,last_seen_at:?int} $binding */
    private function bindingSeenAt(array $binding): ?int
    {
        return $binding['last_seen_at'] ?? $binding['bound_at'] ?? null;
    }

    /**
     * @param array{account_id:string,selection_source:?string,bound_at:?int,last_seen_at:?int} $binding
     * @return array{label:string,is_active:?bool}
     */
    private function bindingActivity(array $binding, int $now, int $activeSessionWindowSeconds): array
    {
        $seenAt = $this->bindingSeenAt($binding);
        if ($seenAt === null) {
            return [
                'label' => 'unknown',
                'is_active' => null,
            ];
        }
        if ($activeSessionWindowSeconds <= 0) {
            return [
                'label' => 'active',
                'is_active' => true,
            ];
        }

        $active = $seenAt >= ($now - $activeSessionWindowSeconds);

        return [
            'label' => $active ? 'active' : 'stale',
            'is_active' => $active,
        ];
    }

    private function formatBindingSeen(string $activity, ?int $seenAt): string
    {
        if ($seenAt === null) {
            return $activity;
        }

        return $activity . ' @ ' . $this->formatTimestamp($seenAt);
    }

    private function bindingActivityFilter(InputInterface $input): ?string
    {
        $value = $input->getOption('activity');
        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        if (!in_array($value, ['active', 'stale', 'unknown'], true)) {
            throw new InvalidArgumentException('Activity filter must be active, stale, or unknown');
        }

        return $value;
    }

    private function bindingAccountFilter(InputInterface $input): ?string
    {
        $value = $input->getOption('account');
        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return $value !== '' ? $value : null;
    }

    /** @param array<string,mixed> $row */
    private function matchesBindingAccountFilter(array $row, string $accountFilter): bool
    {
        $accountName = strtolower(trim((string) ($row['account_name'] ?? '')));
        $accountId = strtolower(trim((string) ($row['account_id'] ?? '')));

        return $accountName === $accountFilter || $accountId === $accountFilter;
    }

    /** @param array{account_id:string,selection_source:?string,bound_at:?int,last_seen_at:?int} $binding */
    private function isBindingActive(array $binding, int $now, int $activeSessionWindowSeconds): bool
    {
        if ($activeSessionWindowSeconds <= 0) {
            return true;
        }

        $seenAt = $this->bindingSeenAt($binding);
        if ($seenAt === null) {
            return false;
        }

        return $seenAt >= ($now - $activeSessionWindowSeconds);
    }

    /**
     * @param list<CodexAccount> $accounts
     * @return array{
     *   selected_account_id:?string,
     *   ranks:array<string,int>,
     *   priorities:array<string,string>,
     *   selection:array<string,mixed>,
     *   account:?CodexAccount,
     *   error:?string
     * }
     */
    private function selectionPreview(array $accounts, StateStore $state, ?string $sessionKey = null): array
    {
        if ($accounts === []) {
            return [
                'selected_account_id' => null,
                'ranks' => [],
                'priorities' => [],
                'selection' => [],
                'account' => null,
                'error' => 'No accounts found',
            ];
        }

        $previewState = StateStore::fromArray($state->snapshot());
        $scheduler = new Scheduler($accounts, $previewState);
        $selection = [];
        $previewSessionKey = $sessionKey ?? '__preview__:' . bin2hex(random_bytes(4));

        try {
            $account = $scheduler->accountForSession($previewSessionKey, null, $selection);
        } catch (RuntimeException $exception) {
            return [
                'selected_account_id' => null,
                'ranks' => [],
                'priorities' => [],
                'selection' => [],
                'account' => null,
                'error' => $exception->getMessage(),
            ];
        }

        $ranks = [];
        $priorities = [];
        foreach (($selection['candidates'] ?? []) as $index => $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $candidateAccountId = $candidate['account_id'] ?? null;
            if (!is_string($candidateAccountId) || $candidateAccountId === '') {
                continue;
            }
            $ranks[$candidateAccountId] = $index + 1;
            if (isset($candidate['priority']) && is_string($candidate['priority']) && $candidate['priority'] !== '') {
                $priorities[$candidateAccountId] = $candidate['priority'];
            }
        }

        return [
            'selected_account_id' => $selection['selected_account_id'] ?? $account->accountId(),
            'ranks' => $ranks,
            'priorities' => $priorities,
            'selection' => $selection,
            'account' => $account,
            'error' => null,
        ];
    }

    /** @param array<string,mixed> $selection @param array{usage:?CachedAccountUsage,availability:AccountAvailability,cooldown_until:int,cooldown_reason:?string,plan_type:string,now:int} $snapshot */
    private function pickJson(?string $sessionKey, array $selection, CodexAccount $account, array $snapshot): array
    {
        return [
            'session_key' => $sessionKey,
            'source' => $selection['source'] ?? null,
            'selected_account' => [
                'name' => $account->name(),
                'account_id' => $account->accountId(),
                'email' => $account->email(),
                'plan_type' => $snapshot['plan_type'],
                'available' => $this->formatAvailability($snapshot['availability']),
                'availability_reason' => $snapshot['availability']->reason,
                'cooldown_until' => $snapshot['cooldown_until'] > 0 ? $snapshot['cooldown_until'] : null,
                'cooldown_reason' => $snapshot['cooldown_reason'],
                'checked_at' => $snapshot['usage']?->checkedAt,
            ],
            'selection' => $selection,
        ];
    }

    /** @param array{account:CodexAccount,usage:?AccountUsage,error:?string} $result */
    private function statusJson(array $result): array
    {
        $account = $result['account'];
        $usage = $result['usage'];

        return [
            'account' => $this->accountJson($account),
            'usage' => $usage instanceof AccountUsage ? [
                'plan_type' => $usage->planType !== '' ? $usage->planType : $account->planType(),
                'primary' => $this->windowJson($usage->primary),
                'secondary' => $this->windowJson($usage->secondary),
            ] : null,
            'error' => $result['error'],
        ];
    }

    /** @param list<array{account:CodexAccount,usage:?AccountUsage,error:?string}> $results */
    private function hasFailure(array $results): bool
    {
        foreach ($results as $result) {
            if ($result['error'] !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed>|null */
    private function windowJson(?RateLimitWindow $window): ?array
    {
        if ($window === null) {
            return null;
        }

        return [
            'used_percent' => $window->usedPercent,
            'left_percent' => $window->leftPercent(),
            'window_minutes' => $window->windowMinutes,
            'resets_at' => $window->resetsAt,
        ];
    }

    private function formatAvailability(AccountAvailability $availability): string
    {
        if (!$availability->routable) {
            return 'no';
        }

        return $availability->isConfirmedAvailable ? 'yes' : 'unknown';
    }

    private function stateStore(AppConfig $config): StateStore
    {
        return StateStore::file(
            $config->stateFile,
            StateStore::sessionRetentionSeconds($config->activeSessionWindowSeconds),
        );
    }

    private function formatCooldown(int $cooldownUntil, int $now): string
    {
        if ($cooldownUntil <= $now) {
            return '-';
        }

        return $this->formatTimestamp($cooldownUntil);
    }

    private function formatTimestamp(?int $timestamp): string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return '-';
        }

        return date('Y-m-d H:i', $timestamp);
    }

    private function formatCachedWindowPercent(?CachedRateLimitWindow $window): string
    {
        if ($window === null) {
            return '-';
        }

        return $this->formatPercent($window->leftPercent) . '%';
    }

    private function formatAvailabilityReason(string $reason, ?string $error, ?string $cooldownReason = null): string
    {
        if ($reason === 'cooldown' && $cooldownReason !== null && $cooldownReason !== '') {
            $reason .= ' (' . $cooldownReason . ')';
        }
        if ($error === null || $error === '') {
            return $reason;
        }

        return $reason . ' (' . $error . ')';
    }

    private function formatLastCooldown(?string $reason, ?int $at): string
    {
        $parts = [];
        if ($reason !== null && $reason !== '') {
            $parts[] = $reason;
        }
        if ($at !== null && $at > 0) {
            $parts[] = $this->formatTimestamp($at);
        }

        return $parts === [] ? '-' : implode(' @ ', $parts);
    }

    private function parseTimestamp(string $value): ?int
    {
        if ($value === '-' || $value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }
}
