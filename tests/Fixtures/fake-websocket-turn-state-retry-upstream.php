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
    fwrite(STDERR, "usage: fake-websocket-turn-state-retry-upstream.php <port> <capture-file>\n");
    exit(2);
}

$connectionCount = 0;
/** @var array<int,int> $connectionIndexes */
$connectionIndexes = [];

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

$server->on('handshake', static function (Request $request, Response $response) use ($captureFile, &$connectionCount, &$connectionIndexes): bool {
    $fd = (int) $request->fd;
    $connectionCount++;
    $connectionIndexes[$fd] = $connectionCount;
    $turnState = $request->header['x-codex-turn-state'] ?? null;
    file_put_contents($captureFile, json_encode([
        'event' => 'handshake',
        'connection_index' => $connectionCount,
        'turn_state_header' => is_string($turnState) && $turnState !== '' ? $turnState : null,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

    $secWebSocketKey = $request->header['sec-websocket-key'] ?? '';
    if (!is_string($secWebSocketKey) || strlen((string) base64_decode($secWebSocketKey, true)) !== 16) {
        $response->end();
        return false;
    }

    $headers = [
        'Upgrade' => 'websocket',
        'Connection' => 'Upgrade',
        'Sec-WebSocket-Accept' => base64_encode(sha1($secWebSocketKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)),
        'Sec-WebSocket-Version' => '13',
    ];
    if ($connectionCount === 1) {
        $headers['x-codex-turn-state'] = 'ts-bridge-1';
    }
    foreach ($headers as $name => $value) {
        $response->header($name, $value);
    }
    $response->status(101);
    $response->end();

    return true;
});

$server->on('message', static function (Server $server, Frame $frame) use ($captureFile, &$connectionIndexes): void {
    $fd = (int) $frame->fd;
    $connectionIndex = $connectionIndexes[$fd] ?? 0;
    file_put_contents($captureFile, json_encode([
        'event' => 'message',
        'connection_index' => $connectionIndex,
        'payload' => (string) $frame->data,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

    if ($connectionIndex === 1) {
        $server->disconnect($fd, 1011, 'retry with sticky turn state');
        return;
    }

    $server->push($fd, '{"type":"response.done","response":{"id":"resp_ws_turn_state_retry"}}');
});

$server->on('close', static function (Server $server, int $fd) use (&$connectionIndexes): void {
    unset($connectionIndexes[$fd]);
});

$server->start();
