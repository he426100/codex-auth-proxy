<?php

declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
$captureFile = (string) ($argv[2] ?? '');
if ($port <= 0 || $captureFile === '') {
    fwrite(STDERR, "usage: fake-upstream.php <port> <capture-file>\n");
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
        'authorization' => (string) ($request->header['authorization'] ?? ''),
        'body' => $request->rawContent(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    $response->header('Content-Type', 'application/json');
    $response->end('{"id":"resp_1","object":"response.compaction"}');
});
$server->start();
