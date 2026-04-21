<?php

declare(strict_types=1);

namespace CodexAuthProxy\Console;

use CodexAuthProxy\Config\AppConfigLoader;
use CodexAuthProxy\Console\Command\ConfigCommand;
use CodexAuthProxy\Console\Command\DoctorCommand;
use CodexAuthProxy\Console\Command\ImportCommand;
use CodexAuthProxy\Console\Command\LoginCommand;
use CodexAuthProxy\Console\Command\ServeCommand;
use CodexAuthProxy\Logging\LoggerFactory;
use CodexAuthProxy\OAuth\CallbackServer;
use CodexAuthProxy\OAuth\CodexOAuthClient;
use CodexAuthProxy\OAuth\CodexOAuthHttpClient;
use CodexAuthProxy\OAuth\LoopbackCallbackServer;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application as SymfonyApplication;

final class Application extends SymfonyApplication
{
    public function __construct(
        ?string $home = null,
        ?LoggerInterface $logger = null,
        ?CodexOAuthClient $oauthClient = null,
        ?CallbackServer $callbackServer = null,
        ?AppConfigLoader $configLoader = null,
    ) {
        parent::__construct('codex-auth-proxy', '0.1.0');

        $configLoader ??= new AppConfigLoader($home);
        $config = $configLoader->load();
        $logger ??= LoggerFactory::create($config->logLevel);
        $oauthClient ??= new CodexOAuthHttpClient(new Client(['timeout' => 30]));
        $callbackServer ??= new LoopbackCallbackServer();

        $this->add(new ImportCommand($configLoader));
        $this->add(new DoctorCommand($configLoader));
        $this->add(new ConfigCommand($configLoader));
        $this->add(new ServeCommand($configLoader, $logger));
        $this->add(new LoginCommand($configLoader, $oauthClient, $callbackServer));
    }
}
