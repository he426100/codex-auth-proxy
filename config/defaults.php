<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require_once dirname(__DIR__) . '/src/Config/env.php';

$envFile = env('CODEX_AUTH_PROXY_DOTENV_FILE', dirname(__DIR__) . '/.env');
if (is_string($envFile) && $envFile !== '' && is_file($envFile)) {
    (new Dotenv())->usePutenv()->loadEnv($envFile);
}

$home = (string) env('CODEX_AUTH_PROXY_HOME', env('HOME', '.'));

return [
    'home' => $home,
    'accounts_dir' => env('CODEX_AUTH_PROXY_ACCOUNTS_DIR'),
    'state_file' => env('CODEX_AUTH_PROXY_STATE_FILE'),
    'host' => env('CODEX_AUTH_PROXY_HOST', '127.0.0.1'),
    'port' => (int) env('CODEX_AUTH_PROXY_PORT', 1456),
    'cooldown_seconds' => (int) env('CODEX_AUTH_PROXY_COOLDOWN_SECONDS', 18000),
    'callback_host' => env('CODEX_AUTH_PROXY_CALLBACK_HOST', 'localhost'),
    'callback_port' => (int) env('CODEX_AUTH_PROXY_CALLBACK_PORT', 1455),
    'callback_timeout_seconds' => (int) env('CODEX_AUTH_PROXY_CALLBACK_TIMEOUT_SECONDS', 300),
    'log_level' => env('CODEX_AUTH_PROXY_LOG_LEVEL', 'warning'),
    'codex_user_agent' => env('CODEX_AUTH_PROXY_CODEX_USER_AGENT', 'codex_cli_rs/0.114.0 codex-auth-proxy/0.1.0'),
    'codex_beta_features' => env('CODEX_AUTH_PROXY_CODEX_BETA_FEATURES', 'multi_agent'),
    'trace_dir' => env('CODEX_AUTH_PROXY_TRACE_DIR'),
    'http_proxy' => env('CODEX_AUTH_PROXY_HTTP_PROXY'),
    'https_proxy' => env('CODEX_AUTH_PROXY_HTTPS_PROXY'),
    'no_proxy' => env('CODEX_AUTH_PROXY_NO_PROXY', 'localhost,127.0.0.1,::1'),
];
