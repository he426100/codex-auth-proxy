<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

final class UpstreamTarget
{
    public function __construct(private readonly string $baseUrl)
    {
    }

    /** @return array{0:string,1:int,2:bool} */
    public function endpoint(): array
    {
        $base = parse_url($this->baseUrl);
        $scheme = (string) ($base['scheme'] ?? 'https');
        $host = (string) ($base['host'] ?? 'chatgpt.com');
        $port = (int) ($base['port'] ?? ($scheme === 'https' ? 443 : 80));

        return [$host, $port, $scheme === 'https'];
    }

    public function pathFor(string $requestUri): string
    {
        $base = parse_url($this->baseUrl);
        $basePath = rtrim((string) ($base['path'] ?? ''), '/');
        $path = (string) (parse_url($requestUri, PHP_URL_PATH) ?: '/');
        $query = parse_url($requestUri, PHP_URL_QUERY);

        if ($path === '/v1') {
            $path = '/';
        } elseif (str_starts_with($path, '/v1/')) {
            $path = substr($path, 3);
        }

        $targetPath = $basePath . '/' . ltrim($path, '/');
        if ($targetPath !== '/' && str_ends_with($targetPath, '/')) {
            $targetPath = rtrim($targetPath, '/');
        }

        return is_string($query) && $query !== '' ? $targetPath . '?' . $query : $targetPath;
    }
}
