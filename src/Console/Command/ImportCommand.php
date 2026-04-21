<?php

declare(strict_types=1);

namespace CodexAuthProxy\Console\Command;

use CodexAuthProxy\Account\AccountFileValidator;
use CodexAuthProxy\Account\AccountRepository;
use CodexAuthProxy\Account\CodexAuthImporter;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'import', description: 'Import a ChatGPT Codex auth.json into the proxy account store')]
final class ImportCommand extends ProxyCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'Account name')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Path to the official Codex auth.json');
        $this->addPathOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'name');
        $config = $this->appConfig($input);
        $from = $this->stringOption($input, 'from');
        $from ??= $config->home . '/.codex/auth.json';

        $raw = file_get_contents($from);
        if ($raw === false) {
            throw new InvalidArgumentException('Failed to read source auth file: ' . $from);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Source auth file must be a JSON object');
        }

        $payload = (new CodexAuthImporter())->import($decoded, $name);
        $account = (new AccountFileValidator())->validate($payload);
        $repository = new AccountRepository($config->accountsDir);
        if ($name === null) {
            $name = $repository->resolveImplicitName($account->name(), $account->accountId());
            $account = $account->withName($name);
        }
        $path = $repository->save($name, $account);
        $output->writeln("Imported {$account->name()} to {$path}");

        return self::SUCCESS;
    }
}
