<?php

declare(strict_types=1);

namespace CodexAuthProxy\Console\Command;

use CodexAuthProxy\Config\AppConfigLoader;
use CodexAuthProxy\Network\OutboundProxyConfig;
use CodexAuthProxy\Proxy\CodexProxyServer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'serve', description: 'Start the local Codex proxy')]
final class ServeCommand extends ProxyCommand
{
    public function __construct(AppConfigLoader $configLoader, private readonly LoggerInterface $logger)
    {
        parent::__construct($configLoader);
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Proxy listen host (default: 127.0.0.1)')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Proxy listen port (default: 1456)')
            ->addOption('cooldown', null, InputOption::VALUE_REQUIRED, 'Default cooldown seconds after quota errors (default: 18000)');
        $this->addPathOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->appConfig($input);
        $outboundProxyConfig = OutboundProxyConfig::fromAppConfig($config);
        $output->writeln("Starting Codex auth proxy on http://{$config->host}:{$config->port}");
        $this->logger->info('Starting Codex auth proxy', ['host' => $config->host, 'port' => $config->port]);

        (new CodexProxyServer(
            host: $config->host,
            port: $config->port,
            accountsDir: $config->accountsDir,
            stateFile: $config->stateFile,
            defaultCooldownSeconds: $config->cooldownSeconds,
            logger: $this->logger,
            outboundProxyConfig: $outboundProxyConfig,
            codexUserAgent: $config->codexUserAgent,
            codexBetaFeatures: $config->codexBetaFeatures,
        ))->start();

        return self::SUCCESS;
    }
}
