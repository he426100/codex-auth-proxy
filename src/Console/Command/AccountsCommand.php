<?php

declare(strict_types=1);

namespace CodexAuthProxy\Console\Command;

use CodexAuthProxy\Account\AccountRepository;
use CodexAuthProxy\Account\CodexAccount;
use CodexAuthProxy\Auth\TokenRefresher;
use CodexAuthProxy\Config\AppConfig;
use CodexAuthProxy\Config\AppConfigLoader;
use CodexAuthProxy\Usage\AccountUsage;
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
            ->addArgument('action', InputArgument::OPTIONAL, 'Action: list, status, or delete', 'list')
            ->addArgument('name', InputArgument::OPTIONAL, 'Account name for status or delete')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip delete confirmation prompt');
        $this->addPathOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = strtolower((string) $input->getArgument('action'));
        $config = $this->appConfig($input);
        $repository = new AccountRepository($config->accountsDir);

        return match ($action) {
            'list' => $this->listAccounts($repository, $input, $output),
            'status' => $this->status($repository, $config, $input, $output),
            'delete' => $this->delete($repository, $input, $output),
            default => throw new InvalidArgumentException('Action must be list, status, or delete'),
        };
    }

    private function listAccounts(AccountRepository $repository, InputInterface $input, OutputInterface $output): int
    {
        $accounts = $repository->load();
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(array_map(fn (CodexAccount $account): array => $this->accountJson($account), $accounts), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($accounts === []) {
            $output->writeln('No accounts found.');

            return self::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Name', 'Email', 'Plan', 'Enabled', 'Account ID']);
        foreach ($accounts as $account) {
            $table->addRow([
                $account->name(),
                $account->email() !== '' ? $account->email() : '-',
                $this->displayPlan($account->planType()),
                $account->enabled() ? 'yes' : 'no',
                $account->accountId(),
            ]);
        }
        $table->render();

        return self::SUCCESS;
    }

    private function status(AccountRepository $repository, AppConfig $config, InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'name');
        $accounts = $this->selectedAccounts($repository, $name);
        $client = $this->usageClient ?? new CodexUsageClient();

        $results = [];
        $failed = false;
        foreach ($accounts as $account) {
            try {
                $account = $this->refreshAccountIfNeeded($repository, $account);
                $usage = $client->fetch($account);
                $results[] = ['account' => $account, 'usage' => $usage, 'error' => null];
            } catch (InvalidArgumentException|RuntimeException $exception) {
                if ($exception instanceof RuntimeException && $this->isInvalidatedTokenFailure($exception)) {
                    try {
                        $account = $this->refreshAccount($repository, $account);
                        $usage = $client->fetch($account);
                        $results[] = ['account' => $account, 'usage' => $usage, 'error' => null];
                        continue;
                    } catch (InvalidArgumentException|RuntimeException $retryException) {
                        $exception = $retryException;
                    }
                }
                $failed = true;
                $results[] = ['account' => $account, 'usage' => null, 'error' => $exception->getMessage()];
            }
        }

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
                continue;
            }
            $output->writeln('  Account: ' . $this->accountLabel($account, $usage));
            $output->writeln('  ' . $this->limitLine('5h limit', $usage->primary));
            $output->writeln('  ' . $this->limitLine('Weekly limit', $usage->secondary));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
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

    private function refreshAccountIfNeeded(AccountRepository $repository, CodexAccount $account): CodexAccount
    {
        $refresher = $this->tokenRefresher ?? new TokenRefresher();
        $refreshed = $refresher->refreshIfExpiring($account);
        if ($refreshed === null) {
            return $account;
        }

        $repository->saveAccount($refreshed);

        return $refreshed;
    }

    private function refreshAccount(AccountRepository $repository, CodexAccount $account): CodexAccount
    {
        $refresher = $this->tokenRefresher ?? new TokenRefresher();
        $refreshed = $refresher->refresh($account);
        $repository->saveAccount($refreshed);

        return $refreshed;
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
}
