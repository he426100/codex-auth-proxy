<?php

declare(strict_types=1);

namespace CodexAuthProxy\Console\Command;

use CodexAuthProxy\Config\AppConfig;
use CodexAuthProxy\Config\AppConfigLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

abstract class ProxyCommand extends Command
{
    public function __construct(protected readonly AppConfigLoader $configLoader)
    {
        parent::__construct();
    }

    protected function addPathOptions(): void
    {
        $this
            ->addOption('accounts-dir', null, InputOption::VALUE_REQUIRED, 'Proxy account directory')
            ->addOption('state-file', null, InputOption::VALUE_REQUIRED, 'Proxy state file');
    }

    protected function appConfig(InputInterface $input): AppConfig
    {
        return $this->configLoader->load([
            'accounts_dir' => $this->stringOption($input, 'accounts-dir'),
            'state_file' => $this->stringOption($input, 'state-file'),
            'host' => $this->stringOption($input, 'host'),
            'port' => $this->intOption($input, 'port'),
            'cooldown_seconds' => $this->intOption($input, 'cooldown'),
            'callback_host' => $this->stringOption($input, 'callback-host'),
            'callback_port' => $this->intOption($input, 'callback-port'),
            'callback_timeout_seconds' => $this->intOption($input, 'callback-timeout'),
        ]);
    }

    protected function stringOption(InputInterface $input, string $name): ?string
    {
        if (!$input->hasOption($name)) {
            return null;
        }

        $value = $input->getOption($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function stringArgument(InputInterface $input, string $name): ?string
    {
        $value = $input->getArgument($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function intOption(InputInterface $input, string $name): ?int
    {
        if (!$input->hasOption($name)) {
            return null;
        }

        $value = $input->getOption($name);
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            return (int) $value;
        }

        return null;
    }
}
