<?php

declare(strict_types=1);

namespace CodexAuthProxy\Tests\Proxy;

use CodexAuthProxy\Tests\TestCase;
use GuzzleHttp\Client as GuzzleClient;

final class CodexProxyServerIntegrationTest extends TestCase
{
    public function testProxiesCompactRequestToFakeUpstream(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-integration');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-request.json';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $http = new GuzzleClient(['http_errors' => false, 'timeout' => 5]);
            $response = $http->post("http://127.0.0.1:{$proxyPort}/v1/responses/compact", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Request-Id' => 'integration-compact',
                ],
                'body' => '{"input":"hello"}',
            ]);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('{"id":"resp_1","object":"response.compaction"}', (string) $response->getBody());

            $capture = $this->waitForJsonFile($captureFile);
            self::assertSame('/responses/compact', $capture['path']);
            self::assertSame('application/json', $capture['accept']);
            self::assertStringStartsWith('Bearer ', $capture['authorization']);
            self::assertStringContainsString('"input":[{"type":"message"', $capture['body']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testReturnsStructuredErrorWhenNoAccountIsAvailable(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-no-account');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);

        $captureFile = $home . '/upstream-request.json';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $http = new GuzzleClient(['http_errors' => false, 'timeout' => 5]);
            $response = $http->post("http://127.0.0.1:{$proxyPort}/v1/responses", [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => '{"input":"hello"}',
            ]);

            $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(503, $response->getStatusCode());
            self::assertSame('codex_proxy_unavailable', $body['error']['code']);
            self::assertSame('No Codex accounts configured', $body['error']['message']);
            self::assertFileDoesNotExist($captureFile);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    public function testTracesWebSocketUpstreamErrorFrame(): void
    {
        if (!extension_loaded('swoole') || !function_exists('proc_open')) {
            self::markTestSkipped('Swoole and proc_open are required for proxy integration tests');
        }

        $home = $this->tempDir('proxy-websocket-trace');
        $accountsDir = $home . '/accounts';
        mkdir($accountsDir, 0700, true);
        $this->writeJson($accountsDir . '/alpha.account.json', $this->accountFixture('alpha'));

        $captureFile = $home . '/upstream-request.json';
        $upstreamPort = $this->freePortOrSkip();
        $proxyPort = $this->freePortOrSkip();

        $upstream = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-upstream.php', (string) $upstreamPort, $captureFile], $home . '/upstream.stderr.log');
        $proxy = $this->startProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/start-proxy.php', (string) $proxyPort, (string) $upstreamPort, $accountsDir, $home], $home . '/proxy.stderr.log');

        try {
            $this->waitForHttp("http://127.0.0.1:{$upstreamPort}/health");
            $this->waitForHttp("http://127.0.0.1:{$proxyPort}/health", $home . '/proxy.stderr.log');

            $payload = null;
            \Swoole\Coroutine\run(static function () use ($proxyPort, &$payload): void {
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $proxyPort);
                $client->set(['timeout' => 5]);
                if (!$client->upgrade('/v1/responses')) {
                    $payload = 'upgrade failed: ' . (string) $client->errCode;
                    $client->close();
                    return;
                }

                $client->push('{"input":"hello"}');
                $frame = $client->recv(5);
                $payload = is_object($frame) && property_exists($frame, 'data') ? (string) $frame->data : (string) $frame;
                $client->close();
            });

            self::assertStringContainsString('usage_limit_reached', (string) $payload);
            $trace = $this->waitForTrace($home . '/traces');
            self::assertSame('websocket', $trace['transport']);
            self::assertSame('upstream_error', $trace['phase']);
            self::assertSame('alpha', $trace['account']);
            self::assertSame('quota', $trace['classification']);
            self::assertStringContainsString('usage_limit_reached', $trace['message']);
        } finally {
            $this->stopProcess($proxy ?? null);
            $this->stopProcess($upstream ?? null);
        }
    }

    private function freePortOrSkip(): int
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $port = random_int(20_000, 60_000);
            $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);
            if ($socket === false) {
                continue;
            }
            fclose($socket);

            return $port;
        }

        self::markTestSkipped('Local TCP bind is not available in this environment');
    }

    private function waitForHttp(string $url, ?string $errorLog = null): void
    {
        $http = new GuzzleClient(['http_errors' => false, 'timeout' => 0.2]);
        $deadline = microtime(true) + 5.0;
        do {
            try {
                $response = $http->get($url);
                if ($response->getStatusCode() < 500) {
                    return;
                }
            } catch (\Throwable) {
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        $suffix = $errorLog !== null && is_file($errorLog) ? ': ' . (string) file_get_contents($errorLog) : '';
        self::fail('Timed out waiting for ' . $url . $suffix);
    }

    /** @return array<string,mixed> */
    private function waitForJsonFile(string $path): array
    {
        $deadline = microtime(true) + 5.0;
        do {
            if (is_file($path)) {
                $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                if (is_array($data)) {
                    return $data;
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail('Timed out waiting for JSON file: ' . $path);
    }

    /** @return array<string,mixed> */
    private function waitForTrace(string $dir): array
    {
        $deadline = microtime(true) + 5.0;
        do {
            $files = glob($dir . '/*.json') ?: [];
            if ($files !== []) {
                $data = json_decode((string) file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
                if (is_array($data)) {
                    return $data;
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail('Timed out waiting for trace in: ' . $dir);
    }

    /** @param list<string> $command */
    private function startProcess(array $command, string $stderrFile): mixed
    {
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $stderrFile, 'a'],
            2 => ['file', $stderrFile, 'a'],
        ], $pipes);
        if (!is_resource($process)) {
            self::fail('Failed to start process: ' . implode(' ', $command));
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return $process;
    }

    private function stopProcess(mixed $process): void
    {
        if (!is_resource($process)) {
            return;
        }

        proc_terminate($process, SIGTERM);
        proc_close($process);
    }
}
