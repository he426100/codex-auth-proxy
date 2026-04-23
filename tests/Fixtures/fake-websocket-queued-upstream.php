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
    fwrite(STDERR, "usage: fake-websocket-queued-upstream.php <port> <capture-file>\n");
    exit(2);
}

$server = new Server('127.0.0.1', $port, SWOOLE_BASE);
$server->set([
    'worker_num' => 1,
    'log_file' => '/dev/null',
]);

$messageCount = 0;
$firstCompleted = false;

$server->on('request', static function (Request $request, Response $response): void {
    $path = (string) ($request->server['request_uri'] ?? '/');
    if ($path === '/health') {
        $response->end('ok');
        return;
    }

    $response->status(404);
    $response->end('not found');
});

$server->on('message', static function (Server $server, Frame $frame) use ($captureFile, &$messageCount, &$firstCompleted): void {
    $fd = (int) $frame->fd;
    $messageCount++;
    $messageIndex = $messageCount;
    $outOfOrder = $messageIndex > 1 && $firstCompleted === false;

    file_put_contents($captureFile, json_encode([
        'payload' => (string) $frame->data,
        'message_index' => $messageIndex,
        'out_of_order' => $outOfOrder,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

    if ($messageIndex === 1) {
        Swoole\Timer::after(200, static function () use ($server, $fd, &$firstCompleted): void {
            $firstCompleted = true;
            if ($server->isEstablished($fd)) {
                $server->push($fd, '{"type":"response.done","response":{"id":"resp_ws_first"}}');
                $server->disconnect($fd, SWOOLE_WEBSOCKET_CLOSE_NORMAL, 'first-done');
            }
        });
        return;
    }

    $responseId = $outOfOrder ? 'resp_ws_out_of_order' : 'resp_ws_second';
    if ($server->isEstablished($fd)) {
        $server->push($fd, '{"type":"response.done","response":{"id":"' . $responseId . '"}}');
        $server->disconnect($fd, SWOOLE_WEBSOCKET_CLOSE_NORMAL, 'done');
    }
});

$server->start();
