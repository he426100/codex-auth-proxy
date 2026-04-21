<?php

declare(strict_types=1);

namespace CodexAuthProxy\Console\Command;

use CodexAuthProxy\Account\AccountFileValidator;
use CodexAuthProxy\Account\AccountRepository;
use CodexAuthProxy\Account\CodexAuthImporter;
use CodexAuthProxy\Config\AppConfigLoader;
use CodexAuthProxy\OAuth\CallbackServer;
use CodexAuthProxy\OAuth\CodexOAuthClient;
use CodexAuthProxy\OAuth\PkcePair;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'login', description: 'Authorize a ChatGPT Codex account through the official OpenAI browser flow')]
final class LoginCommand extends ProxyCommand
{
    public function __construct(
        AppConfigLoader $configLoader,
        private readonly CodexOAuthClient $oauthClient,
        private readonly CallbackServer $callbackServer,
    ) {
        parent::__construct($configLoader);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'Account name')
            ->addOption('callback-host', null, InputOption::VALUE_REQUIRED, 'OAuth callback host')
            ->addOption('callback-port', null, InputOption::VALUE_REQUIRED, 'OAuth callback port')
            ->addOption('callback-timeout', null, InputOption::VALUE_REQUIRED, 'OAuth callback timeout seconds');
        $this->addPathOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'name');
        $config = $this->appConfig($input);
        $pkce = PkcePair::generate();
        $state = bin2hex(random_bytes(16));
        $redirectUri = "http://{$config->callbackHost}:{$config->callbackPort}/auth/callback";
        $url = $this->oauthClient->authorizationUrl($state, $pkce, $redirectUri);

        $output->writeln('Open this URL in your browser:');
        $output->writeln($url);
        $output->writeln("Waiting for OAuth callback on {$redirectUri}");

        $callback = $this->callbackServer->waitForCode(
            $config->callbackHost,
            $config->callbackPort,
            '/auth/callback',
            $state,
            $config->callbackTimeoutSeconds,
        );

        $tokens = $this->oauthClient->exchangeCode($callback->code, $pkce, $redirectUri);
        $payload = (new CodexAuthImporter())->import([
            'auth_mode' => 'chatgpt',
            'tokens' => $tokens,
        ], $name);
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
