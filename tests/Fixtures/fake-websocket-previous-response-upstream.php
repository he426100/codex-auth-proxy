<?php

declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
$captureFile = (string) ($argv[2] ?? '');
$mode = (string) ($argv[3] ?? 'default');
if ($port <= 0 || $captureFile === '') {
    fwrite(STDERR, "usage: fake-websocket-previous-response-upstream.php <port> <capture-file> [default|all-miss]\n");
    exit(2);
}

$connectionsByFd = [];
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
$server->on('open', static function (Server $server, Request $request) use (&$connectionsByFd): void {
    $fd = (int) ($request->fd ?? 0);
    if ($fd <= 0) {
        return;
    }

    $path = (string) ($request->server['request_uri'] ?? '/');
    $query = (string) ($request->server['query_string'] ?? '');
    $target = $query !== '' ? $path . '?' . $query : $path;

    $connectionsByFd[$fd] = [
        'account_id' => (string) ($request->header['chatgpt-account-id'] ?? ''),
        'path' => $path,
        'query' => $query,
        'target' => $target,
    ];
});
$server->on('message', static function (Server $server, Frame $frame) use (&$connectionsByFd, $captureFile, $mode): void {
    $fd = (int) $frame->fd;
    $connection = $connectionsByFd[$fd] ?? [
        'account_id' => '',
        'path' => '/',
        'query' => '',
        'target' => '/',
    ];
    $accountId = (string) $connection['account_id'];
    $decoded = json_decode((string) $frame->data, true);
    $previousResponseId = is_array($decoded) && is_string($decoded['previous_response_id'] ?? null)
        ? $decoded['previous_response_id']
        : null;

    file_put_contents($captureFile, json_encode([
        'account_id' => $accountId,
        'path' => (string) $connection['path'],
        'query' => (string) $connection['query'],
        'target' => (string) $connection['target'],
        'previous_response_id' => $previousResponseId,
        'payload' => (string) $frame->data,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

    if ($previousResponseId === 'resp_prev_beta' && ($mode === 'all-miss' || $accountId !== 'acct-beta')) {
        $server->push($fd, '{"type":"error","error":{"type":"invalid_request_error","code":"previous_response_not_found","message":"Previous response with id \'resp_prev_beta\' not found.","param":"previous_response_id"},"status":400}');
        return;
    }

    if ($previousResponseId === 'resp_prev_beta') {
        $server->push($fd, '{"type":"response.completed","response":{"id":"resp_beta_next"}}');
        return;
    }

    $suffix = str_replace('acct-', '', $accountId);
    $server->push($fd, '{"type":"response.completed","response":{"id":"resp_' . $suffix . '_fresh"}}');
});
$server->on('close', static function (Server $server, int $fd) use (&$connectionsByFd): void {
    unset($connectionsByFd[$fd]);
});
$server->start();
