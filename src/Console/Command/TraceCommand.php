<?php

declare(strict_types=1);

namespace CodexAuthProxy\Console\Command;

use CodexAuthProxy\Config\AppConfigLoader;
use CodexAuthProxy\Logging\LoggerConfigLoader;
use RuntimeException;
use SplFileObject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'trace', description: 'Summarize Codex proxy trace logs')]
final class TraceCommand extends ProxyCommand
{
    public function __construct(
        AppConfigLoader $configLoader,
        private readonly LoggerConfigLoader $loggerConfigLoader,
    ) {
        parent::__construct($configLoader);
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Trace JSONL file')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print summary as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $explicitFile = $this->stringOption($input, 'file');
        $traceFile = $explicitFile ?? $this->defaultTraceFile();

        if (!is_file($traceFile)) {
            $output->writeln('No trace file found: ' . $traceFile);

            return $explicitFile === null ? self::SUCCESS : self::FAILURE;
        }

        $summary = $this->summarize($traceFile);
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $output->writeln('Trace file: ' . $traceFile);
        $output->writeln('Total records: ' . $summary['total_records']);
        $output->writeln('WebSocket retries: ' . $summary['websocket_retries']);
        $output->writeln('HTTP fallbacks: ' . $summary['http_fallbacks']);
        $output->writeln('Stream disconnect terminals: ' . $summary['stream_disconnect_terminals']);
        $output->writeln('Lineage errors: ' . $summary['lineage_errors']);
        if ($summary['invalid_records'] > 0) {
            $output->writeln('Invalid records: ' . $summary['invalid_records']);
        }

        return self::SUCCESS;
    }

    private function defaultTraceFile(): string
    {
        $config = $this->loggerConfigLoader->load();
        $stream = $config['channels']['trace']['handler']['constructor']['stream'] ?? null;
        if (!is_string($stream) || trim($stream) === '') {
            throw new RuntimeException('Trace logger stream is not configured');
        }

        return $stream;
    }

    /** @return array{total_records:int,invalid_records:int,websocket_retries:int,http_fallbacks:int,stream_disconnect_terminals:int,lineage_errors:int} */
    private function summarize(string $traceFile): array
    {
        $summary = [
            'total_records' => 0,
            'invalid_records' => 0,
            'websocket_retries' => 0,
            'http_fallbacks' => 0,
            'stream_disconnect_terminals' => 0,
            'lineage_errors' => 0,
        ];

        $file = new SplFileObject($traceFile, 'r');
        while (!$file->eof()) {
            $line = trim((string) $file->fgets());
            if ($line === '') {
                continue;
            }

            try {
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $summary['invalid_records']++;
                continue;
            }
            if (!is_array($record)) {
                $summary['invalid_records']++;
                continue;
            }

            $context = $record['context'] ?? null;
            if (!is_array($context)) {
                $summary['invalid_records']++;
                continue;
            }

            $summary['total_records']++;
            $phase = $this->stringValue($context['phase'] ?? null);
            $message = $this->stringValue($context['message'] ?? null);
            $classification = $this->stringValue($context['classification'] ?? null);
            $recovery = $this->stringValue($context['recovery'] ?? null);

            if ($phase === 'websocket_retry' || $recovery === 'retry') {
                $summary['websocket_retries']++;
            }
            if ($phase === 'websocket_http_fallback' || $recovery === 'http_fallback') {
                $summary['http_fallbacks']++;
            }
            if ($phase === 'websocket_incomplete_terminal' || str_contains($message, 'stream disconnected before response.completed')) {
                $summary['stream_disconnect_terminals']++;
            }
            if ($classification === 'lineage' || str_contains($message, 'previous_response_not_found')) {
                $summary['lineage_errors']++;
            }
        }

        return $summary;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
