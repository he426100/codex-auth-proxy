<?php

declare(strict_types=1);

namespace CodexAuthProxy\Console\Command;

use CodexAuthProxy\Account\AccountRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'doctor', description: 'Validate proxy account files')]
final class DoctorCommand extends ProxyCommand
{
    protected function configure(): void
    {
        $this->addPathOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $accounts = (new AccountRepository($this->appConfig($input)->accountsDir))->load();
        foreach ($accounts as $account) {
            $output->writeln("OK {$account->name()} {$account->email()} {$account->accountId()}");
        }
        $output->writeln(count($accounts) . ' account(s) loaded');

        return self::SUCCESS;
    }
}
