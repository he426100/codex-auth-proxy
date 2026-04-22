<?php

declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Server;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
$captureFile = (string) ($argv[2] ?? '');
$freshAccessToken = (string) ($argv[3] ?? '');
if ($port <= 0 || $captureFile === '' || $freshAccessToken === '') {
    fwrite(STDERR, "usage: fake-auth-refresh-upstream.php <port> <capture-file> <fresh-access-token>\n");
    exit(2);
}

$server = new Server('127.0.0.1', $port, SWOOLE_BASE);
$server->set([
    'worker_num' => 1,
    'log_file' => '/dev/null',
]);
$server->on('request', static function (Request $request, Response $response) use ($captureFile, $freshAccessToken): void {
    $path = (string) ($request->server['request_uri'] ?? '/');
    if ($path === '/health') {
        $response->end('ok');
        return;
    }

    $authorization = (string) ($request->header['authorization'] ?? '');
    file_put_contents($captureFile, json_encode([
        'path' => $path,
        'authorization' => $authorization,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

    $response->header('Content-Type', 'application/json');
    if ($authorization !== 'Bearer ' . $freshAccessToken) {
        $response->status(401);
        $response->end('{"error":{"message":"Your authentication token has been invalidated.","type":"invalid_request_error","code":"token_invalidated","param":null},"status":401}');
        return;
    }

    $response->end('{"id":"resp_fresh","object":"response.compaction"}');
});
$server->on('message', static function (): void {
});
$server->start();
