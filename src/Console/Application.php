<?php

declare(strict_types=1);

namespace CodexAuthProxy\Console;

use CodexAuthProxy\AppMeta;
use CodexAuthProxy\Config\AppConfigLoader;
use CodexAuthProxy\Console\Command\ConfigCommand;
use CodexAuthProxy\Console\Command\AccountsCommand;
use CodexAuthProxy\Console\Command\DoctorCommand;
use CodexAuthProxy\Console\Command\ExportCommand;
use CodexAuthProxy\Console\Command\ImportCommand;
use CodexAuthProxy\Console\Command\LoginCommand;
use CodexAuthProxy\Console\Command\ServeCommand;
use CodexAuthProxy\Logging\LoggerConfigLoader;
use CodexAuthProxy\Logging\LoggerFactory;
use CodexAuthProxy\OAuth\CallbackServer;
use CodexAuthProxy\OAuth\CodexOAuthClient;
use CodexAuthProxy\OAuth\CodexOAuthHttpClient;
use CodexAuthProxy\Network\OutboundProxyConfig;
use CodexAuthProxy\Observability\RequestTraceLogger;
use CodexAuthProxy\OAuth\LoopbackCallbackServer;
use CodexAuthProxy\Usage\UsageClient;
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
        ?LoggerConfigLoader $loggerConfigLoader = null,
        ?UsageClient $usageClient = null,
    ) {
        parent::__construct(AppMeta::NAME, AppMeta::VERSION);

        $configLoader ??= new AppConfigLoader($home);
        $loggerConfigLoader ??= new LoggerConfigLoader();
        $config = $configLoader->load();
        $loggerConfig = $loggerConfigLoader->load();
        $outboundProxyConfig = OutboundProxyConfig::fromAppConfig($config);
        $logger ??= LoggerFactory::create($loggerConfig, 'default');
        $oauthClient ??= new CodexOAuthHttpClient(new Client(['timeout' => 30]), $outboundProxyConfig->guzzleProxy());
        $callbackServer ??= new LoopbackCallbackServer();
        $requestTraceLogger = new RequestTraceLogger(LoggerFactory::create($loggerConfig, 'trace'));

        $this->add(new AccountsCommand($configLoader, $usageClient));
        $this->add(new ImportCommand($configLoader));
        $this->add(new ExportCommand($configLoader));
        $this->add(new DoctorCommand($configLoader));
        $this->add(new ConfigCommand($configLoader));
        $this->add(new ServeCommand($configLoader, $logger, $requestTraceLogger));
        $this->add(new LoginCommand($configLoader, $oauthClient, $callbackServer));
    }
}
