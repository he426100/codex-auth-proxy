<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

final class UpstreamResponseBodyBuffer
{
    private string $body = '';
    private bool $streamed = false;
    private bool $streamErrorBuffered = false;
    private string $undecided = '';

    /** @var list<string> */
    private array $queuedFrames = [];

    public function __construct(
        private readonly bool $forceBuffer,
        private readonly SseFramer $framer = new SseFramer(),
    ) {
    }

    /**
     * @param array<string,string> $headers
     * @return list<string>
     */
    public function write(int $statusCode, array $headers, string $chunk): array
    {
        if ($chunk === '') {
            return [];
        }

        if ($this->forceBuffer || $statusCode >= 400 || $this->streamErrorBuffered) {
            $this->body .= $this->undecided . $chunk;
            $this->undecided = '';
            return [];
        }

        if (!$this->isSse($headers)) {
            if ($statusCode <= 0 && $headers === []) {
                $this->undecided .= $chunk;
                return [];
            }

            $this->body .= $this->undecided . $chunk;
            $this->undecided = '';
            return [];
        }

        if ($this->undecided !== '') {
            $chunk = $this->undecided . $chunk;
            $this->undecided = '';
        }

        $frames = [];
        foreach ($this->framer->write($chunk) as $frame) {
            if (!$this->streamed && $this->queuedFrames === []) {
                $errorBody = StreamErrorDetector::errorBody($frame);
                if ($errorBody !== null) {
                    $this->streamErrorBuffered = true;
                    $this->body .= $errorBody;
                    return [];
                }
            }

            if ($statusCode <= 0) {
                $this->queuedFrames[] = $frame;
                continue;
            }

            $frames[] = $frame;
        }

        if ($statusCode > 0 && $this->queuedFrames !== []) {
            $frames = array_merge($this->queuedFrames, $frames);
            $this->queuedFrames = [];
        }
        if ($frames !== []) {
            $this->streamed = true;
        }

        return $frames;
    }

    /** @return list<string> */
    public function flush(array $headers = []): array
    {
        if ($this->forceBuffer || $this->streamErrorBuffered) {
            if ($this->undecided !== '') {
                $this->body .= $this->undecided;
                $this->undecided = '';
            }
            return [];
        }

        $frames = [];
        if ($this->undecided !== '') {
            if (!$this->isSse($headers)) {
                $this->body .= $this->undecided;
                $this->undecided = '';
                return [];
            }

            $frames = $this->framer->write($this->undecided);
            $this->undecided = '';
        }

        $frames = array_merge($this->queuedFrames, $frames);
        $this->queuedFrames = [];
        foreach ($this->framer->flush() as $frame) {
            $frames[] = $frame;
        }
        if ($frames !== []) {
            $this->streamed = true;
        }

        return $frames;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function streamed(): bool
    {
        return $this->streamed;
    }

    /** @param array<string,string> $headers */
    private function isSse(array $headers): bool
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === 'content-type' && str_contains(strtolower((string) $value), 'text/event-stream')) {
                return true;
            }
        }

        return false;
    }
}
