<?php

declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
$captureFile = (string) ($argv[2] ?? '');
$mode = (string) ($argv[3] ?? 'success');
if ($port <= 0 || $captureFile === '') {
    fwrite(STDERR, "usage: fake-websocket-upgrade-fallback-upstream.php <port> <capture-file> [success|error]\n");
    exit(2);
}

$server = new Server('127.0.0.1', $port, SWOOLE_BASE);
$server->set([
    'worker_num' => 1,
    'log_file' => '/dev/null',
]);
$server->on('request', static function (Request $request, Response $response) use ($captureFile, $mode): void {
    $path = (string) ($request->server['request_uri'] ?? '/');
    if ($path === '/health') {
        $response->end('ok');
        return;
    }

    file_put_contents($captureFile, json_encode([
        'method' => (string) ($request->server['request_method'] ?? ''),
        'path' => $path,
        'upgrade' => (string) ($request->header['upgrade'] ?? ''),
        'accept' => (string) ($request->header['accept'] ?? ''),
        'authorization' => (string) ($request->header['authorization'] ?? ''),
        'body' => $request->rawContent(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

    if (strtolower((string) ($request->header['upgrade'] ?? '')) === 'websocket') {
        $response->status(426);
        $response->end('websocket upgrade disabled');
        return;
    }

    $response->status(200);
    $response->header('Content-Type', 'text/event-stream');
    $response->header('Cache-Control', 'no-cache');
    if ($mode === 'error') {
        $response->write("event: error\n");
        $response->write("data: {\"type\":\"error\",\"error\":{\"type\":\"server_error\",\"code\":\"server_error\",\"message\":\"transient failed\"}}\n\n");
        $response->end();
        return;
    }

    $response->write("data: {\"type\":\"response.output_text.delta\",\"delta\":\"hello\"}\n\n");
    usleep(20_000);
    $response->write("data: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_http_fallback\",\"output\":[]}}\n\n");
    $response->end();
});
$server->start();
