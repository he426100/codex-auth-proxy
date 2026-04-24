<?php

declare(strict_types=1);

use CodexAuthProxy\Codex\CodexProtocol;
use CodexAuthProxy\Codex\CodexRuntimeProfile;
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
    'codex_user_agent' => env('CODEX_AUTH_PROXY_CODEX_USER_AGENT', CodexRuntimeProfile::defaultUserAgent()),
    'codex_beta_features' => env('CODEX_AUTH_PROXY_CODEX_BETA_FEATURES', CodexRuntimeProfile::defaultBetaFeatures()),
    'codex_originator' => env('CODEX_AUTH_PROXY_CODEX_ORIGINATOR', CodexRuntimeProfile::defaultOriginator()),
    'codex_residency' => env('CODEX_AUTH_PROXY_CODEX_RESIDENCY', CodexRuntimeProfile::defaultResidency()),
    'codex_upstream_base_url' => env('CODEX_AUTH_PROXY_CODEX_UPSTREAM_BASE_URL', CodexProtocol::defaultUpstreamBaseUrl()),
    'usage_base_url' => env('CODEX_AUTH_PROXY_USAGE_BASE_URL', CodexProtocol::defaultBackendBaseUrl()),
    'usage_refresh_interval_seconds' => (int) env('CODEX_AUTH_PROXY_USAGE_REFRESH_INTERVAL_SECONDS', 600),
    'trace_mutations' => env('CODEX_AUTH_PROXY_TRACE_MUTATIONS', true),
    'trace_timings' => env('CODEX_AUTH_PROXY_TRACE_TIMINGS', false),
    'http_proxy' => env('CODEX_AUTH_PROXY_HTTP_PROXY'),
    'https_proxy' => env('CODEX_AUTH_PROXY_HTTPS_PROXY'),
    'no_proxy' => env('CODEX_AUTH_PROXY_NO_PROXY', 'localhost,127.0.0.1,::1'),
];
