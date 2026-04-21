<?php

declare(strict_types=1);

namespace CodexAuthProxy\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'config', description: 'Print the Codex config.toml snippet')]
final class ConfigCommand extends ProxyCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Proxy listen host (default: 127.0.0.1)')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Proxy listen port (default: 1456)');
        $this->addPathOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->appConfig($input);
        $output->writeln('# Add this to ~/.codex/config.toml');
        $output->writeln('openai_base_url = "http://' . $config->host . ':' . $config->port . '/v1"');

        return self::SUCCESS;
    }
}
