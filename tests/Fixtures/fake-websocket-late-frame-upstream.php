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
    fwrite(STDERR, "usage: fake-websocket-late-frame-upstream.php <port> <capture-file>\n");
    exit(2);
}

$server = new Server('127.0.0.1', $port, SWOOLE_BASE);
$server->set([
    'worker_num' => 1,
    'log_file' => '/dev/null',
]);

$messageCount = 0;

$server->on('request', static function (Request $request, Response $response): void {
    $path = (string) ($request->server['request_uri'] ?? '/');
    if ($path === '/health') {
        $response->end('ok');
        return;
    }

    $response->status(404);
    $response->end('not found');
});

$server->on('message', static function (Server $server, Frame $frame) use ($captureFile, &$messageCount): void {
    $messageCount++;
    file_put_contents($captureFile, json_encode([
        'payload' => (string) $frame->data,
        'message_index' => $messageCount,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

    if ($messageCount === 1) {
        $server->push($frame->fd, '{"type":"response.done","response":{"id":"resp_ws_first"}}');
        Swoole\Timer::after(150, static function () use ($server, $frame): void {
            if ($server->isEstablished($frame->fd)) {
                $server->push($frame->fd, '{"type":"error","error":{"code":"server_error","message":"late upstream frame","status":500}}');
            }
        });
        return;
    }

    $server->push($frame->fd, '{"type":"response.done","response":{"id":"resp_ws_second"}}');
});

$server->start();
