<?php

declare(strict_types=1);

namespace CodexAuthProxy\Usage;

use CodexAuthProxy\Account\CodexCliAuth;
use CodexAuthProxy\Account\CodexAccount;
use RuntimeException;

final class CodexUsageClient implements UsageClient
{
    private readonly UsageResponseParser $parser;

    public function __construct(
        private readonly string $codexBinary = 'codex',
        ?UsageResponseParser $parser = null,
        private readonly int $timeoutSeconds = 30,
        private readonly ?string $tempRoot = null,
    ) {
        $this->parser = $parser ?? new UsageResponseParser();
    }

    public function fetch(CodexAccount $account): AccountUsage
    {
        $codexHome = $this->createCodexHome($account);
        try {
            $response = $this->readRateLimits($codexHome);

            return $this->parser->parse($response);
        } finally {
            $this->removeDirectory($codexHome);
        }
    }

    /** @return array<string,mixed> */
    private function readRateLimits(string $codexHome): array
    {
        $pipes = [];
        $process = $this->startProcess($codexHome, $pipes);
        $stdoutBuffer = '';
        $stderrBuffer = '';

        try {
            $this->sendRequest($pipes[0], 1, 'initialize', [
                'clientInfo' => [
                    'name' => 'codex-auth-proxy',
                    'title' => null,
                    'version' => '0.1.0',
                ],
                'capabilities' => [
                    'experimentalApi' => true,
                ],
            ]);
            $this->readResponse($pipes[1], $pipes[2], 1, $stdoutBuffer, $stderrBuffer);

            $this->sendRequest($pipes[0], 2, 'account/rateLimits/read');
            $result = $this->readResponse($pipes[1], $pipes[2], 2, $stdoutBuffer, $stderrBuffer);
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $status = proc_get_status($process);
            if ($status['running'] === true) {
                proc_terminate($process);
            }
            proc_close($process);
        }

        return $result;
    }

    /**
     * @param resource $stdin
     * @param array<string,mixed>|null $params
     */
    private function sendRequest($stdin, int $id, string $method, ?array $params = null): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
        ];
        if ($params !== null) {
            $message['params'] = $params;
        }

        $line = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($stdin, $line) === false) {
            throw new RuntimeException('Failed to write Codex app-server request');
        }
        fflush($stdin);
    }

    /**
     * @param resource $stdout
     * @param resource $stderr
     * @return array<string,mixed>
     */
    private function readResponse($stdout, $stderr, int $id, string &$stdoutBuffer, string &$stderrBuffer): array
    {
        $deadline = microtime(true) + $this->timeoutSeconds;
        while (microtime(true) < $deadline) {
            $this->drain($stdout, $stdoutBuffer);
            $this->drain($stderr, $stderrBuffer);

            while (($lineEnd = strpos($stdoutBuffer, "\n")) !== false) {
                $line = trim(substr($stdoutBuffer, 0, $lineEnd));
                $stdoutBuffer = substr($stdoutBuffer, $lineEnd + 1);
                if ($line === '') {
                    continue;
                }

                $message = json_decode($line, true);
                if (!is_array($message) || ($message['id'] ?? null) !== $id) {
                    continue;
                }
                if (isset($message['error'])) {
                    throw new RuntimeException('Codex app-server request failed: ' . $this->jsonSummary($message['error']));
                }
                if (!is_array($message['result'] ?? null)) {
                    throw new RuntimeException('Codex app-server response missing JSON result');
                }

                /** @var array<string,mixed> $result */
                $result = $message['result'];

                return $result;
            }

            usleep(50_000);
        }

        $detail = trim($stderrBuffer);
        throw new RuntimeException('Timed out waiting for Codex app-server response' . ($detail !== '' ? ': ' . $detail : ''));
    }

    /**
     * @param resource $stream
     */
    private function drain($stream, string &$buffer): void
    {
        $chunk = stream_get_contents($stream);
        if (is_string($chunk) && $chunk !== '') {
            $buffer .= $chunk;
        }
    }

    /**
     * @param-out array<int,resource> $pipes
     * @return resource
     */
    private function startProcess(string $codexHome, array &$pipes)
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = $this->environment($codexHome);
        $process = proc_open([$this->codexBinary, 'app-server', '--listen', 'stdio://'], $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start Codex app-server process');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return $process;
    }

    /** @return array<string,string> */
    private function environment(string $codexHome): array
    {
        $env = getenv();
        $env['CODEX_HOME'] = $codexHome;

        return $env;
    }

    private function createCodexHome(CodexAccount $account): string
    {
        $root = rtrim($this->tempRoot ?? sys_get_temp_dir(), '/');
        $dir = $root . '/codex-home-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create temporary Codex home: ' . $dir);
        }

        $payload = CodexCliAuth::payload($account);
        $path = $dir . '/auth.json';
        if (file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Failed to write temporary Codex auth file');
        }
        chmod($path, 0600);

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
                continue;
            }
            @unlink($path);
        }
        @rmdir($dir);
    }

    private function jsonSummary(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

            return is_string($encoded) ? $encoded : 'unknown error';
        }

        return 'unknown error';
    }
}
