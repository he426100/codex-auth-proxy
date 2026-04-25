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
    fwrite(STDERR, "usage: fake-replay-upstream.php <port> <capture-file>\n");
    exit(2);
}

/** @var array<string,string> $ownersByResponse */
$ownersByResponse = [];
/** @var array<string,int> $sequenceByAccount */
$sequenceByAccount = [];
/** @var array<int,string> $accountsByFd */
$accountsByFd = [];

$nextResponseId = static function (string $accountId, string $kind = 'resp') use (&$sequenceByAccount): string {
    $suffix = str_replace('acct-', '', $accountId);
    $sequenceByAccount[$accountId] = ($sequenceByAccount[$accountId] ?? 0) + 1;

    return $kind . '_' . $suffix . '_' . $sequenceByAccount[$accountId];
};

$previousResponseId = static function (string $payload): ?string {
    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        return null;
    }
    $previousResponseId = $decoded['previous_response_id'] ?? null;

    return is_string($previousResponseId) && $previousResponseId !== '' ? $previousResponseId : null;
};

$lineageMiss = static function (?string $previousResponseId, string $accountId) use (&$ownersByResponse): bool {
    if ($previousResponseId === null || $previousResponseId === '') {
        return false;
    }
    $owner = $ownersByResponse[$previousResponseId] ?? null;

    return is_string($owner) && $owner !== '' && $owner !== $accountId;
};

$record = static function (array $row) use ($captureFile): void {
    file_put_contents($captureFile, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
};

$server = new Server('127.0.0.1', $port, SWOOLE_BASE);
$server->set([
    'worker_num' => 1,
    'log_file' => '/dev/null',
]);

$server->on('request', static function (Request $request, Response $response) use (&$ownersByResponse, $nextResponseId, $previousResponseId, $lineageMiss, $record): void {
    $path = (string) ($request->server['request_uri'] ?? '/');
    if ($path === '/health') {
        $response->end('ok');
        return;
    }

    $payload = $request->rawContent();
    $accountId = (string) ($request->header['chatgpt-account-id'] ?? '');
    $previous = $previousResponseId($payload);
    $miss = $lineageMiss($previous, $accountId);
    $record([
        'transport' => 'http',
        'path' => $path,
        'account_id' => $accountId,
        'previous_response_id' => $previous,
        'lineage_miss' => $miss,
    ]);

    if ($miss) {
        $response->status(400);
        $response->header('Content-Type', 'application/json');
        $response->end('{"type":"error","error":{"type":"invalid_request_error","code":"previous_response_not_found","message":"previous response not found","param":"previous_response_id"},"status":400}');
        return;
    }

    if ($path === '/responses/compact') {
        $responseId = $nextResponseId($accountId, 'resp_compact');
        $ownersByResponse[$responseId] = $accountId;
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'id' => $responseId,
            'object' => 'response.compaction',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return;
    }

    if ($path === '/responses') {
        $responseId = $nextResponseId($accountId);
        $ownersByResponse[$responseId] = $accountId;
        $response->status(200);
        $response->header('Content-Type', 'text/event-stream');
        $response->write('data: ' . json_encode([
            'type' => 'response.completed',
            'response' => [
                'id' => $responseId,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n\n");
        $response->end();
        return;
    }

    $response->status(404);
    $response->end('not found');
});

$server->on('open', static function (Server $server, Request $request) use (&$accountsByFd): void {
    $accountsByFd[(int) $request->fd] = (string) ($request->header['chatgpt-account-id'] ?? '');
});

$server->on('message', static function (Server $server, Frame $frame) use (&$ownersByResponse, &$accountsByFd, $nextResponseId, $previousResponseId, $lineageMiss, $record): void {
    $fd = (int) $frame->fd;
    $payload = (string) $frame->data;
    $accountId = $accountsByFd[$fd] ?? '';
    $previous = $previousResponseId($payload);
    $miss = $lineageMiss($previous, $accountId);
    $record([
        'transport' => 'websocket',
        'path' => '/responses',
        'account_id' => $accountId,
        'previous_response_id' => $previous,
        'lineage_miss' => $miss,
    ]);

    if ($miss) {
        $server->push($fd, '{"type":"error","error":{"type":"invalid_request_error","code":"previous_response_not_found","message":"previous response not found","param":"previous_response_id"},"status":400}');
        return;
    }

    $responseId = $nextResponseId($accountId);
    $ownersByResponse[$responseId] = $accountId;
    $server->push($fd, json_encode([
        'type' => 'response.done',
        'response' => [
            'id' => $responseId,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
});

$server->on('close', static function (Server $server, int $fd) use (&$accountsByFd): void {
    unset($accountsByFd[$fd]);
});

$server->start();
