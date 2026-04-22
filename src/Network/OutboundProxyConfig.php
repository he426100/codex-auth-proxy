<?php

declare(strict_types=1);

namespace CodexAuthProxy\Network;

use CodexAuthProxy\Config\AppConfig;
use InvalidArgumentException;

final class OutboundProxyConfig
{
    /** @param list<string> $noProxy */
    private function __construct(
        private readonly ?string $httpProxy,
        private readonly ?string $httpsProxy,
        private readonly array $noProxy,
    ) {
    }

    public static function fromAppConfig(AppConfig $config): self
    {
        return new self(
            self::validatedProxy($config->httpProxy),
            self::validatedProxy($config->httpsProxy),
            self::parseNoProxy($config->noProxy),
        );
    }

    /** @return array{http?:string,https?:string,no?:list<string>} */
    public function guzzleProxy(): array
    {
        $proxy = [];
        if ($this->httpProxy !== null) {
            $proxy['http'] = $this->httpProxy;
        }
        if ($this->httpsProxy !== null) {
            $proxy['https'] = $this->httpsProxy;
        }
        if ($this->noProxy !== []) {
            $proxy['no'] = $this->noProxy;
        }

        return $proxy;
    }

    /** @return array<string,string|int> */
    public function swooleOptionsFor(string $host): array
    {
        if ($this->shouldBypassHost($host)) {
            return [];
        }

        $proxy = $this->httpsProxy ?? $this->httpProxy;
        if ($proxy === null) {
            return [];
        }

        $parts = parse_url($proxy);
        if (!is_array($parts) || !isset($parts['host'], $parts['port'])) {
            throw new InvalidArgumentException('Invalid proxy URL: ' . $proxy);
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === 'socks5') {
            return $this->socks5Options($parts);
        }
        if ($scheme !== 'http') {
            throw new InvalidArgumentException('Swoole upstream proxy requires an http:// or socks5:// proxy URL: ' . $proxy);
        }

        $options = [
            'http_proxy_host' => (string) $parts['host'],
            'http_proxy_port' => (int) $parts['port'],
        ];
        if (isset($parts['user']) && (string) $parts['user'] !== '') {
            $options['http_proxy_user'] = rawurldecode((string) $parts['user']);
        }
        if (isset($parts['pass']) && (string) $parts['pass'] !== '') {
            $options['http_proxy_password'] = rawurldecode((string) $parts['pass']);
        }

        return $options;
    }

    /**
     * @param array{host:string,port:int|string,user?:string,pass?:string} $parts
     * @return array<string,string|int>
     */
    private function socks5Options(array $parts): array
    {
        $options = [
            'socks5_host' => (string) $parts['host'],
            'socks5_port' => (int) $parts['port'],
        ];
        if (isset($parts['user']) && (string) $parts['user'] !== '') {
            $options['socks5_username'] = rawurldecode((string) $parts['user']);
        }
        if (isset($parts['pass']) && (string) $parts['pass'] !== '') {
            $options['socks5_password'] = rawurldecode((string) $parts['pass']);
        }

        return $options;
    }

    /** @return array<string,string> */
    public function environment(): array
    {
        $env = [];
        if ($this->httpProxy !== null) {
            $env['HTTP_PROXY'] = $this->httpProxy;
        }
        if ($this->httpsProxy !== null) {
            $env['HTTPS_PROXY'] = $this->httpsProxy;
        }
        if ($this->noProxy !== []) {
            $env['NO_PROXY'] = implode(',', $this->noProxy);
        }

        return $env;
    }

    public function shouldBypassHost(string $host): bool
    {
        $needle = self::normalizeHost($host);
        foreach ($this->noProxy as $entry) {
            $entry = self::normalizeHost($entry);
            if ($entry === '*') {
                return true;
            }
            if ($entry === $needle) {
                return true;
            }
            if (str_starts_with($entry, '.') && str_ends_with($needle, $entry)) {
                return true;
            }
            if (!str_starts_with($entry, '.') && str_ends_with($needle, '.' . $entry)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function parseNoProxy(string $value): array
    {
        $items = [];
        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry);
            if ($entry !== '') {
                $items[] = $entry;
            }
        }

        return $items;
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, '[') && str_contains($host, ']')) {
            $end = (int) strpos($host, ']');

            return substr($host, 1, $end - 1);
        }
        if (substr_count($host, ':') === 1) {
            return (string) preg_replace('/:\d+$/', '', $host);
        }

        return $host;
    }

    private static function validatedProxy(?string $proxy): ?string
    {
        if ($proxy === null) {
            return null;
        }

        $parts = parse_url($proxy);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        if (!in_array($scheme, ['http', 'https', 'socks5'], true)) {
            throw new InvalidArgumentException('Unsupported proxy scheme: ' . $proxy);
        }

        return $proxy;
    }
}
