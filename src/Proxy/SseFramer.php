<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

final class SseFramer
{
    private string $pending = '';

    /** @return list<string> */
    public function write(string $chunk): array
    {
        if ($chunk === '') {
            return [];
        }

        if ($this->needsLineBreak($this->pending, $chunk)) {
            $this->pending .= "\n";
        }
        $this->pending .= $chunk;

        $frames = [];
        while (($length = $this->frameLength($this->pending)) > 0) {
            $frames[] = $this->withDelimiter(substr($this->pending, 0, $length));
            $this->pending = substr($this->pending, $length);
        }

        if (trim($this->pending) === '') {
            $this->pending = '';
            return $frames;
        }

        if ($this->canEmitWithoutDelimiter($this->pending)) {
            $frames[] = $this->withDelimiter($this->pending);
            $this->pending = '';
        }

        return $frames;
    }

    /** @return list<string> */
    public function flush(): array
    {
        if ($this->pending === '' || trim($this->pending) === '') {
            $this->pending = '';
            return [];
        }

        if (!$this->canEmitWithoutDelimiter($this->pending)) {
            $this->pending = '';
            return [];
        }

        $frame = $this->withDelimiter($this->pending);
        $this->pending = '';

        return [$frame];
    }

    private function frameLength(string $chunk): int
    {
        $lf = strpos($chunk, "\n\n");
        $crlf = strpos($chunk, "\r\n\r\n");
        if ($lf === false && $crlf === false) {
            return 0;
        }
        if ($lf === false) {
            return (int) $crlf + 4;
        }
        if ($crlf === false) {
            return (int) $lf + 2;
        }

        return min($lf + 2, $crlf + 4);
    }

    private function withDelimiter(string $chunk): string
    {
        if (str_ends_with($chunk, "\n\n") || str_ends_with($chunk, "\r\n\r\n")) {
            return $chunk;
        }
        if (str_ends_with($chunk, "\r\n")) {
            return $chunk . "\r\n";
        }
        if (str_ends_with($chunk, "\n")) {
            return $chunk . "\n";
        }

        return $chunk . "\n\n";
    }

    private function canEmitWithoutDelimiter(string $chunk): bool
    {
        $trimmed = trim($chunk);
        if ($trimmed === '' || !$this->hasField($trimmed, 'data:') || $this->needsMoreData($trimmed)) {
            return false;
        }

        foreach (preg_split('/\R/', $trimmed) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_starts_with($line, 'data:')) {
                continue;
            }
            $data = trim(substr($line, strlen('data:')));
            if ($data === '' || $data === '[DONE]') {
                continue;
            }
            if (json_decode($data, true) === null && json_last_error() !== JSON_ERROR_NONE) {
                return false;
            }
        }

        return true;
    }

    private function needsMoreData(string $chunk): bool
    {
        return $this->hasField($chunk, 'event:') && !$this->hasField($chunk, 'data:');
    }

    private function hasField(string $chunk, string $prefix): bool
    {
        foreach (preg_split('/\R/', $chunk) ?: [] as $line) {
            if (str_starts_with(trim($line), $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function needsLineBreak(string $pending, string $chunk): bool
    {
        if ($pending === '' || $chunk === '' || str_ends_with($pending, "\n") || str_ends_with($pending, "\r")) {
            return false;
        }
        if (str_starts_with($chunk, "\n") || str_starts_with($chunk, "\r")) {
            return false;
        }

        $trimmed = ltrim($chunk, " \t");
        foreach (['data:', 'event:', 'id:', 'retry:', ':'] as $prefix) {
            if (str_starts_with($trimmed, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
