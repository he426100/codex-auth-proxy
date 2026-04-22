<?php

declare(strict_types=1);

use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use function Swoole\Coroutine\run;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
$captureFile = (string) ($argv[2] ?? '');
if ($port <= 0 || $captureFile === '') {
    fwrite(STDERR, "usage: fake-websocket-stream-then-upgrade-quota-upstream.php <port> <capture-file>\n");
    exit(2);
}

run(static function () use ($port, $captureFile): void {
    $server = new Server('127.0.0.1', $port, false);
    $server->handle('/health', static function (Request $request, Response $response): void {
        $response->end('ok');
    });
    $server->handle('/responses', static function (Request $request, Response $response) use ($captureFile): void {
        $path = (string) ($request->server['request_uri'] ?? '/');
        $query = (string) ($request->server['query_string'] ?? '');
        $target = $query !== '' ? $path . '?' . $query : $path;
        $accountId = (string) ($request->header['chatgpt-account-id'] ?? '');

        file_put_contents($captureFile, json_encode([
            'account_id' => $accountId,
            'path' => $path,
            'query' => $query,
            'target' => $target,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

        if ($accountId === 'acct-beta') {
            $response->status(429);
            $response->header('Content-Type', 'application/json');
            $response->end('{"type":"error","error":{"code":"usage_limit_reached","message":"too many requests"}}');
            return;
        }

        $response->upgrade();
        $frame = $response->recv(5);
        if ($frame === false || $frame === '') {
            $response->close();
            return;
        }

        if ($accountId === 'acct-alpha') {
            $response->push('{"type":"error","error":{"code":"usage_limit_reached","message":"too many requests"}}');
            $response->close();
            return;
        }

        $response->push(json_encode([
            'type' => 'response.completed',
            'response' => ['id' => 'resp_ws_stream_upgrade_' . str_replace('acct-', '', $accountId)],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $response->close();
    });
    $server->start();
});
