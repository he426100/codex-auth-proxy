<?php

declare(strict_types=1);

use CodexAuthProxy\Observability\RequestTraceLogger;
use CodexAuthProxy\Proxy\CodexProxyServer;
use Psr\Log\NullLogger;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$proxyPort = (int) ($argv[1] ?? 0);
$upstreamPort = (int) ($argv[2] ?? 0);
$accountsDir = (string) ($argv[3] ?? '');
$home = (string) ($argv[4] ?? '');
if ($proxyPort <= 0 || $upstreamPort <= 0 || $accountsDir === '' || $home === '') {
    fwrite(STDERR, "usage: start-proxy.php <proxy-port> <upstream-port> <accounts-dir> <home>\n");
    exit(2);
}

(new CodexProxyServer(
    host: '127.0.0.1',
    port: $proxyPort,
    accountsDir: $accountsDir,
    stateFile: $home . '/state.json',
    defaultCooldownSeconds: 18000,
    upstreamBase: "http://127.0.0.1:{$upstreamPort}",
    logger: new NullLogger(),
    requestTraceLogger: new RequestTraceLogger($home . '/traces'),
))->start();
