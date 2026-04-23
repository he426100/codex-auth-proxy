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
    fwrite(STDERR, "usage: fake-websocket-session-reuse-upstream.php <port> <capture-file>\n");
    exit(2);
}

$server = new Server('127.0.0.1', $port, SWOOLE_BASE);
$server->set([
    'worker_num' => 1,
    'log_file' => '/dev/null',
]);

$connectionCount = 0;
/** @var array<int,int> $messageCounts */
$messageCounts = [];
/** @var array<int,int> $connectionIndexes */
$connectionIndexes = [];

$server->on('request', static function (Request $request, Response $response): void {
    $path = (string) ($request->server['request_uri'] ?? '/');
    if ($path === '/health') {
        $response->end('ok');
        return;
    }

    $response->status(404);
    $response->end('not found');
});

$server->on('open', static function (Server $server, Request $request) use ($captureFile, &$connectionCount, &$connectionIndexes): void {
    $fd = (int) $request->fd;
    $connectionCount++;
    $connectionIndexes[$fd] = $connectionCount;

    file_put_contents($captureFile, json_encode([
        'event' => 'open',
        'connection_index' => $connectionCount,
        'fd' => $fd,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
});

$server->on('message', static function (Server $server, Frame $frame) use ($captureFile, &$messageCounts, &$connectionIndexes): void {
    $fd = (int) $frame->fd;
    $messageCounts[$fd] = ($messageCounts[$fd] ?? 0) + 1;
    $connectionIndex = $connectionIndexes[$fd] ?? 0;
    $messageIndex = $messageCounts[$fd];

    file_put_contents($captureFile, json_encode([
        'event' => 'message',
        'connection_index' => $connectionIndex,
        'message_index' => $messageIndex,
        'payload' => (string) $frame->data,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

    $server->push(
        $fd,
        '{"type":"response.done","response":{"id":"resp_ws_conn_' . $connectionIndex . '_msg_' . $messageIndex . '"}}'
    );
});

$server->start();
