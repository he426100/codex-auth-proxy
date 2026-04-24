<?php

declare(strict_types=1);

use CodexAuthProxy\Codex\CodexRuntimeProfile;
use CodexAuthProxy\Observability\RequestTraceLogger;
use CodexAuthProxy\Logging\LoggerFactory;
use CodexAuthProxy\Proxy\CodexProxyServer;
use Psr\Log\NullLogger;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

$proxyPort = (int) ($argv[1] ?? 0);
$upstreamPort = (int) ($argv[2] ?? 0);
$accountsDir = (string) ($argv[3] ?? '');
$home = (string) ($argv[4] ?? '');
/** @var mixed $traceTimingsArg */
$traceTimingsArg = $argv[5] ?? false;
$traceTimings = match (strtolower((string) $traceTimingsArg)) {
    '1', 'true', 'yes', 'on' => true,
    default => false,
};
if ($proxyPort <= 0 || $upstreamPort <= 0 || $accountsDir === '' || $home === '') {
    fwrite(STDERR, "usage: start-proxy.php <proxy-port> <upstream-port> <accounts-dir> <home> [trace-timings]\n");
    exit(2);
}

(new CodexProxyServer(
    host: '127.0.0.1',
    port: $proxyPort,
    accountsDir: $accountsDir,
    stateFile: $home . '/state.json',
    defaultCooldownSeconds: 18000,
    upstreamBase: "http://127.0.0.1:{$upstreamPort}",
    runtimeProfile: new CodexRuntimeProfile('codex-test-agent', 'beta-test', 'codex-test-originator', ''),
    usageBaseUrl: "http://127.0.0.1:{$upstreamPort}",
    logger: new NullLogger(),
    requestTraceLogger: new RequestTraceLogger(LoggerFactory::createTrace($home . '/logs/trace.jsonl')),
    traceTimings: $traceTimings,
    usageRefreshIntervalSeconds: 0,
))->start();
