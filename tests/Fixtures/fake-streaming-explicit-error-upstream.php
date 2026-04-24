<?php

declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Server;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
$captureFile = (string) ($argv[2] ?? '');
if ($port <= 0 || $captureFile === '') {
    fwrite(STDERR, "usage: fake-streaming-explicit-error-upstream.php <port> <capture-file>\n");
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

    file_put_contents($captureFile, json_encode([
        'path' => $path,
        'accept' => (string) ($request->header['accept'] ?? ''),
        'authorization' => (string) ($request->header['authorization'] ?? ''),
        'body' => $request->rawContent(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    $response->status(200);
    $response->header('Content-Type', 'text/event-stream');
    $response->header('Cache-Control', 'no-cache');
    $response->write("data: {\"type\":\"response.output_text.delta\",\"delta\":\"Hello\"}\n\n");
    usleep(20_000);
    $response->write("event: error\n");
    $response->write("data: {\"type\":\"error\",\"error\":{\"type\":\"invalid_request_error\",\"code\":\"previous_response_not_found\",\"message\":\"Previous response with id 'resp_stream_explicit' not found.\",\"param\":\"previous_response_id\"},\"status\":400}\n\n");
    $response->end();
});
$server->on('message', static function (): void {
});
$server->start();
