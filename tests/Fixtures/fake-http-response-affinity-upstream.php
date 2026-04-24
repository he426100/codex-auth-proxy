<?php

declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
$captureFile = (string) ($argv[2] ?? '');
$mode = (string) ($argv[3] ?? 'default');
if ($port <= 0 || $captureFile === '') {
    fwrite(STDERR, "usage: fake-http-response-affinity-upstream.php <port> <capture-file> [default|all-miss]\n");
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

    $body = (string) $request->rawContent();
    $decoded = json_decode($body, true);
    $previousResponseId = is_array($decoded) && is_string($decoded['previous_response_id'] ?? null)
        ? $decoded['previous_response_id']
        : null;
    $accountId = (string) ($request->header['chatgpt-account-id'] ?? '');

    file_put_contents($captureFile, json_encode([
        'method' => (string) ($request->server['request_method'] ?? ''),
        'path' => $path,
        'account_id' => $accountId,
        'previous_response_id' => $previousResponseId,
        'body' => $body,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

    if ($path === '/responses/compact') {
        $response->header('Content-Type', 'application/json');
        $response->end(match ($accountId) {
            'acct-beta' => '{"id":"resp_compact_beta","object":"response.compaction"}',
            default => '{"id":"resp_compact_alpha","object":"response.compaction"}',
        });
        return;
    }

    if ($path === '/responses') {
        $response->status(200);
        $response->header('Content-Type', 'text/event-stream');
        $response->header('Cache-Control', 'no-cache');

        if ($previousResponseId === 'resp_compact_beta' && ($mode === 'all-miss' || $accountId !== 'acct-beta')) {
            $response->write("event: error\n");
            $response->write("data: {\"type\":\"error\",\"error\":{\"type\":\"invalid_request_error\",\"code\":\"previous_response_not_found\",\"message\":\"Previous response with id 'resp_compact_beta' not found.\",\"param\":\"previous_response_id\"},\"status\":400}\n\n");
            $response->end();
            return;
        }

        if ($previousResponseId === 'resp_compact_beta') {
            $response->write("data: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_after_compact_beta\",\"output\":[]}}\n\n");
            $response->end();
            return;
        }

        $response->write("data: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_generic_http\",\"output\":[]}}\n\n");
        $response->end();
        return;
    }

    $response->status(404);
    $response->end('not found');
});
$server->start();
