<?php

declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Server;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
$captureFile = (string) ($argv[2] ?? '');
if ($port <= 0 || $captureFile === '') {
    fwrite(STDERR, "usage: fake-quota-upstream.php <port> <capture-file>\n");
    exit(2);
}

$server = new Server('127.0.0.1', $port, SWOOLE_BASE);
$server->set([
    'worker_num' => 1,
    'log_file' => '/dev/null',
]);
$server->on('request', static function (Request $request, Response $response) use ($captureFile): void {
    $path = (string) ($request->server['request_uri'] ?? '/');
    if ($path === '/health') {
        $response->end('ok');
        return;
    }

    $accountId = (string) ($request->header['chatgpt-account-id'] ?? '');
    file_put_contents($captureFile, json_encode([
        'path' => $path,
        'account_id' => $accountId,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

    $response->header('Content-Type', 'application/json');
    if (in_array($accountId, ['acct-alpha', 'acct-beta'], true)) {
        $response->status(429);
        $response->end('{"error":{"code":"usage_limit_reached","message":"too many requests"}}');
        return;
    }

    $response->end('{"id":"resp_3","object":"response.compaction"}');
});
$server->on('message', static function (): void {
});
$server->start();
