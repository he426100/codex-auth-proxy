<?php

declare(strict_types=1);

namespace CodexAuthProxy\Console\Command;

use CodexAuthProxy\Account\AccountRepository;
use CodexAuthProxy\Account\CodexCliAuth;
use CodexAuthProxy\Account\CodexAccount;
use CodexAuthProxy\Config\AppConfig;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Helper\QuestionHelper;

#[AsCommand(name: 'export', description: 'Export Codex CLI config.toml and auth.json from proxy state')]
final class ExportCommand extends ProxyCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::REQUIRED, 'Export target: config, auth, or all')
            ->addArgument('name', InputArgument::OPTIONAL, 'Account name for auth export')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Proxy listen host')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Proxy listen port')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Prompt before applying exported config.toml to ~/.codex')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip apply confirmation prompt');
        $this->addPathOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = strtolower((string) $input->getArgument('target'));
        if (!in_array($target, ['config', 'auth', 'all'], true)) {
            throw new InvalidArgumentException('Export target must be config, auth, or all');
        }

        $config = $this->appConfig($input);
        $paths = [];
        if ($target === 'config' || $target === 'all') {
            $paths['config'] = $this->exportConfig($config);
            $output->writeln('Exported config to ' . $paths['config']);
        }
        if ($target === 'auth' || $target === 'all') {
            $account = $this->selectedAccount($config, $this->stringArgument($input, 'name'));
            $paths['auth'] = $this->exportAuth($config, $account);
            $output->writeln('Exported auth for ' . $account->name() . ' to ' . $paths['auth']);
        }

        if ((bool) $input->getOption('apply')) {
            if (!(bool) $input->getOption('yes') && !$this->confirmed($input, $output)) {
                $output->writeln('Apply aborted.');
                return self::SUCCESS;
            }
            $this->apply($config, $paths, $output);
        }

        return self::SUCCESS;
    }

    private function exportConfig(AppConfig $config): string
    {
        $sourcePath = $config->home . '/.codex/config.toml';
        $source = is_file($sourcePath) ? (string) file_get_contents($sourcePath) : '';
        $content = $this->withLeadingRootAssignments($source, [
            'model_provider' => '"openai"',
            'openai_base_url' => '"http://' . $config->host . ':' . $config->port . '/v1"',
        ]);
        $path = $this->proxyPath($config, 'config.toml');
        $this->writeFile($path, $content);

        return $path;
    }

    private function exportAuth(AppConfig $config, CodexAccount $account): string
    {
        $path = $this->proxyPath($config, 'auth.json');
        $payload = CodexCliAuth::payload($account);
        $this->writeFile($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", 0600);

        return $path;
    }

    /** @param array<string,string> $assignments */
    private function withLeadingRootAssignments(string $source, array $assignments): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $source);
        if ($normalized === '') {
            $content = [];
            foreach ($assignments as $key => $value) {
                $content[] = $key . ' = ' . $value;
            }

            return implode("\n", $content) . "\n";
        }

        $lines = explode("\n", str_ends_with($normalized, "\n") ? substr($normalized, 0, -1) : $normalized);
        $tableIndex = count($lines);
        foreach ($lines as $index => $text) {
            if (preg_match('/^\s*(?:\[[^\]]+\]|\[\[[^\]]+\]\])\s*(?:#.*)?$/', $text) === 1) {
                $tableIndex = $index;
                break;
            }
        }

        $root = [];
        $remaining = array_fill_keys(array_keys($assignments), true);
        $insertAt = null;
        foreach (array_slice($lines, 0, $tableIndex) as $text) {
            $matched = false;
            foreach ($assignments as $key => $value) {
                if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $text) === 1) {
                    if ($insertAt === null) {
                        $insertAt = count($root);
                    }
                    if ($remaining[$key] === true) {
                        $remaining[$key] = false;
                    }
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                continue;
            }
            $root[] = $text;
        }

        $tail = array_slice($lines, $tableIndex);
        if ($insertAt !== null || in_array(true, $remaining, true)) {
            $insertLines = [];
            foreach ($assignments as $key => $value) {
                if (($remaining[$key] ?? false) === true || $insertAt !== null) {
                    $insertLines[] = $key . ' = ' . $value;
                }
            }
            if ($root === []) {
                array_push($root, ...$insertLines);
                if ($tail !== []) {
                    $root[] = '';
                }
            } else {
                $position = $insertAt ?? count($root);
                while ($position > 0 && trim($root[$position - 1]) === '') {
                    $position--;
                }
                array_splice($root, $position, 0, $insertLines);
            }
        }

        return implode("\n", array_merge($root, $tail)) . "\n";
    }

    private function selectedAccount(AppConfig $config, ?string $name): CodexAccount
    {
        $accounts = (new AccountRepository($config->accountsDir))->load();
        if ($name !== null) {
            foreach ($accounts as $account) {
                if ($account->name() === $name) {
                    return $account;
                }
            }
            throw new InvalidArgumentException('Account not found: ' . $name);
        }

        if (count($accounts) === 1) {
            return $accounts[0];
        }

        throw new InvalidArgumentException('Account name is required when exporting auth with ' . count($accounts) . ' accounts');
    }

    /** @param array<string,string> $paths */
    private function apply(AppConfig $config, array $paths, OutputInterface $output): void
    {
        if (isset($paths['config'])) {
            $target = $config->home . '/.codex/config.toml';
            $this->backupAndCopy($paths['config'], $target, 0644);
            $output->writeln('Applied config to ' . $target);
        }

        if (isset($paths['auth'])) {
            $output->writeln('Skipped applying auth to ~/.codex/auth.json; the proxy uses its own account store.');
        }
    }

    private function confirmed(InputInterface $input, OutputInterface $output): bool
    {
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            throw new RuntimeException('Question helper is unavailable');
        }
        $question = new ConfirmationQuestion('Apply exported config.toml to ~/.codex after creating a backup? [y/N] ', false);

        return (bool) $helper->ask($input, $output, $question);
    }

    private function backupAndCopy(string $source, string $target, int $mode): void
    {
        if (is_file($target)) {
            $backup = $this->backupPath($target);
            if (!copy($target, $backup)) {
                throw new RuntimeException('Failed to back up ' . $target);
            }
        }

        $this->writeFile($target, (string) file_get_contents($source), $mode);
    }

    private function backupPath(string $target): string
    {
        $base = $target . '.bak.' . date('YmdHis');
        $candidate = $base;
        $index = 2;
        while (file_exists($candidate)) {
            $candidate = $base . '-' . $index;
            $index++;
        }

        return $candidate;
    }

    private function proxyPath(AppConfig $config, string $file): string
    {
        return $config->home . '/.config/codex-auth-proxy/' . $file;
    }

    private function writeFile(string $path, string $content, int $mode = 0644): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory: ' . $dir);
        }
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write file: ' . $path);
        }
        chmod($path, $mode);
    }
}
