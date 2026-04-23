<?php

declare(strict_types=1);

namespace CodexAuthProxy\Usage;

use CodexAuthProxy\Account\CodexAccount;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class CodexUsageClient implements UsageClient
{
    private readonly ClientInterface $http;
    private readonly UsageResponseParser $parser;
    /** @var array<string,mixed> */
    private readonly array $proxy;

    /** @param array<string,string> $proxyEnv */
    public function __construct(
        private readonly string $baseUrl = 'https://chatgpt.com/backend-api',
        ?UsageResponseParser $parser = null,
        private readonly int $timeoutSeconds = 30,
        private readonly array $proxyEnv = [],
        ?ClientInterface $http = null,
        private readonly string $originator = 'codex-tui',
        private readonly string $userAgent = 'codex_cli_rs/0.114.0 codex-auth-proxy/0.1.0',
        private readonly string $residency = '',
    ) {
        $this->proxy = $this->proxyOptions();
        $options = [
            'timeout' => $this->timeoutSeconds,
            'connect_timeout' => min($this->timeoutSeconds, 15),
            'http_errors' => false,
        ];
        if ($this->proxy !== []) {
            $options['proxy'] = $this->proxy;
        }

        $this->http = $http ?? new Client($options);
        $this->parser = $parser ?? new UsageResponseParser();
    }

    public function fetch(CodexAccount $account): AccountUsage
    {
        try {
            $response = $this->http->request('GET', $this->usageEndpoint(), [
                'headers' => $this->headers($account),
                'http_errors' => false,
                'proxy' => $this->proxy,
            ]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('usage endpoint request failed: ' . $exception->getMessage(), 0, $exception);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->errorSummary($response, $body));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('usage endpoint returned invalid JSON');
        }

        return $this->parser->parse($decoded);
    }

    /** @return array<string,string> */
    private function headers(CodexAccount $account): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $account->accessToken(),
            'Accept' => 'application/json',
            'ChatGPT-Account-ID' => $account->accountId(),
        ];

        if ($this->originator !== '') {
            $headers['originator'] = $this->originator;
        }
        if ($this->userAgent !== '') {
            $headers['User-Agent'] = $this->userAgent;
        }
        if ($this->residency !== '') {
            $headers['x-openai-internal-codex-residency'] = $this->residency;
        }

        return $headers;
    }

    private function usageEndpoint(): string
    {
        $base = rtrim($this->usageBaseUrl(), '/');
        $host = parse_url($base, PHP_URL_HOST);
        if (is_string($host) && in_array($host, ['chatgpt.com', 'chat.openai.com'], true) && !str_contains($base, '/backend-api')) {
            $base .= '/backend-api';
        }

        if (str_contains($base, '/backend-api')) {
            return $base . '/wham/usage';
        }

        return $base . '/api/codex/usage';
    }

    private function usageBaseUrl(): string
    {
        $candidate = trim($this->baseUrl);
        if ($candidate !== '' && preg_match('#^https?://#i', $candidate) === 1) {
            return $candidate;
        }

        return 'https://chatgpt.com/backend-api';
    }

    private function errorSummary(ResponseInterface $response, string $body): string
    {
        $status = $response->getStatusCode();
        $details = [];
        foreach (['x-request-id', 'x-oai-request-id', 'cf-ray', 'x-openai-authorization-error'] as $header) {
            $value = trim($response->getHeaderLine($header));
            if ($value !== '') {
                $details[] = $header . '=' . $value;
            }
        }

        $bodyHint = $this->bodyHint($body);
        if ($details !== []) {
            $bodyHint .= '; ' . implode('; ', $details);
        }

        return 'usage endpoint returned HTTP ' . $status . ': ' . $bodyHint;
    }

    private function bodyHint(string $body): string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return 'empty body';
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $message = $decoded['error']['message'] ?? $decoded['message'] ?? null;
            $code = $decoded['error']['code'] ?? $decoded['code'] ?? null;
            $parts = [];
            if (is_string($code) && trim($code) !== '') {
                $parts[] = trim($code);
            }
            if (is_string($message) && trim($message) !== '') {
                $parts[] = trim($message);
            }
            if ($parts !== []) {
                return implode(': ', $parts);
            }
        }

        return strlen($trimmed) > 500 ? substr($trimmed, 0, 500) . '...' : $trimmed;
    }

    /** @return array{http?:string,https?:string,no?:list<string>} */
    private function proxyOptions(): array
    {
        $proxy = [];

        $httpProxy = $this->proxyValue(['HTTP_PROXY', 'http_proxy']) ?? $this->proxyValue(['HTTPS_PROXY', 'https_proxy']);
        if ($httpProxy !== null) {
            $proxy['http'] = $httpProxy;
        }

        $httpsProxy = $this->proxyValue(['HTTPS_PROXY', 'https_proxy']) ?? $this->proxyValue(['HTTP_PROXY', 'http_proxy']);
        if ($httpsProxy !== null) {
            $proxy['https'] = $httpsProxy;
        }

        $noProxy = $this->proxyValue(['NO_PROXY', 'no_proxy']);
        if ($noProxy !== null) {
            $items = array_values(array_filter(array_map('trim', explode(',', $noProxy)), static fn (string $item): bool => $item !== ''));
            if ($items !== []) {
                $proxy['no'] = $items;
            }
        }

        return $proxy;
    }

    /** @param list<string> $keys */
    private function proxyValue(array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->proxyEnv[$key] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
