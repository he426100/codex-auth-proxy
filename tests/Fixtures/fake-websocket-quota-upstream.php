<?php

declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
$captureFile = (string) ($argv[2] ?? '');
if ($port <= 0 || $captureFile === '') {
    fwrite(STDERR, "usage: fake-websocket-quota-upstream.php <port> <capture-file>\n");
    exit(2);
}

$accountsByFd = [];
$server = new Server('127.0.0.1', $port, SWOOLE_BASE);
$server->set([
    'worker_num' => 1,
    'log_file' => '/dev/null',
]);
$server->on('request', static function (Request $request, Response $response): void {
    $path = (string) ($request->server['request_uri'] ?? '/');
    if ($path === '/health') {
        $response->end('ok');
        return;
    }

    $response->status(404);
    $response->end('not found');
});
$server->on('open', static function (Server $server, Request $request) use (&$accountsByFd): void {
    $fd = (int) ($request->fd ?? 0);
    if ($fd <= 0) {
        return;
    }

    $accountsByFd[$fd] = (string) ($request->header['chatgpt-account-id'] ?? '');
});
$server->on('message', static function (Server $server, Frame $frame) use (&$accountsByFd, $captureFile): void {
    $fd = (int) $frame->fd;
    $accountId = $accountsByFd[$fd] ?? '';
    file_put_contents($captureFile, json_encode([
        'account_id' => $accountId,
        'payload' => (string) $frame->data,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

    if (in_array($accountId, ['acct-alpha', 'acct-beta'], true)) {
        $server->push($fd, '{"type":"error","error":{"code":"usage_limit_reached","message":"too many requests"}}');
        return;
    }

    $server->push($fd, '{"type":"response.completed","response":{"id":"resp_ws_gamma"}}');
});
$server->on('close', static function (Server $server, int $fd) use (&$accountsByFd): void {
    unset($accountsByFd[$fd]);
});
$server->start();
