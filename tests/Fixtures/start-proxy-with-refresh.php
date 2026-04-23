<?php

declare(strict_types=1);

use CodexAuthProxy\Auth\TokenRefresher;
use CodexAuthProxy\Logging\LoggerFactory;
use CodexAuthProxy\Observability\RequestTraceLogger;
use CodexAuthProxy\Proxy\CodexProxyServer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

$proxyPort = (int) ($argv[1] ?? 0);
$upstreamPort = (int) ($argv[2] ?? 0);
$accountsDir = (string) ($argv[3] ?? '');
$home = (string) ($argv[4] ?? '');
$tokensFile = (string) ($argv[5] ?? '');
if ($proxyPort <= 0 || $upstreamPort <= 0 || $accountsDir === '' || $home === '' || $tokensFile === '') {
    fwrite(STDERR, "usage: start-proxy-with-refresh.php <proxy-port> <upstream-port> <accounts-dir> <home> <tokens-file>\n");
    exit(2);
}

$tokens = json_decode((string) file_get_contents($tokensFile), true);
if (!is_array($tokens)) {
    fwrite(STDERR, "refresh tokens file must be a JSON object\n");
    exit(2);
}

$http = new Client([
    'handler' => HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id_token' => $tokens['id_token'] ?? null,
            'access_token' => $tokens['access_token'] ?? null,
            'refresh_token' => $tokens['refresh_token'] ?? null,
        ], JSON_THROW_ON_ERROR)),
    ])),
]);

(new CodexProxyServer(
    host: '127.0.0.1',
    port: $proxyPort,
    accountsDir: $accountsDir,
    stateFile: $home . '/state.json',
    defaultCooldownSeconds: 18000,
    upstreamBase: "http://127.0.0.1:{$upstreamPort}",
    logger: new NullLogger(),
    tokenRefresher: new TokenRefresher($http),
    requestTraceLogger: new RequestTraceLogger(LoggerFactory::createTrace($home . '/logs/trace.jsonl')),
    usageRefreshIntervalSeconds: 0,
))->start();
