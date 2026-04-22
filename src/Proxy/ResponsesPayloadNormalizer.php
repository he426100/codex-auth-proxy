<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

use stdClass;

final class ResponsesPayloadNormalizer
{
    public function normalizeHttp(string $payload): string
    {
        return $this->normalize($payload, false);
    }

    public function normalizeWebSocket(string $payload): string
    {
        return $this->normalize($payload, true);
    }

    private function normalize(string $payload, bool $websocket): string
    {
        try {
            $decoded = json_decode($payload, false, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $payload;
        }

        if (!$decoded instanceof stdClass && !is_array($decoded)) {
            return $payload;
        }

        if ($websocket && $decoded instanceof stdClass) {
            $decoded->type = 'response.create';
        }
        if (!$websocket && $decoded instanceof stdClass) {
            $this->normalizeHttpCompatibility($decoded);
        }

        $this->normalizeParameters($decoded);

        return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function normalizeHttpCompatibility(stdClass $payload): void
    {
        if (property_exists($payload, 'instructions') && $payload->instructions === null) {
            $payload->instructions = '';
        }

        if (property_exists($payload, 'input') && is_string($payload->input)) {
            $payload->input = [$this->messageInput($payload->input)];
        }
        if (property_exists($payload, 'input') && is_array($payload->input)) {
            foreach ($payload->input as $item) {
                if ($item instanceof stdClass && ($item->role ?? null) === 'system') {
                    $item->role = 'developer';
                }
            }
        }

        $this->normalizeBuiltinTools($payload);
    }

    private function messageInput(string $text): stdClass
    {
        return (object) [
            'type' => 'message',
            'role' => 'user',
            'content' => [
                (object) [
                    'type' => 'input_text',
                    'text' => $text,
                ],
            ],
        ];
    }

    private function normalizeBuiltinTools(stdClass $payload): void
    {
        if (isset($payload->tools) && is_array($payload->tools)) {
            foreach ($payload->tools as $tool) {
                $this->normalizeBuiltinTool($tool);
            }
        }

        if (isset($payload->tool_choice) && $payload->tool_choice instanceof stdClass) {
            $this->normalizeBuiltinTool($payload->tool_choice);
            if (isset($payload->tool_choice->tools) && is_array($payload->tool_choice->tools)) {
                foreach ($payload->tool_choice->tools as $tool) {
                    $this->normalizeBuiltinTool($tool);
                }
            }
        }
    }

    private function normalizeBuiltinTool(mixed $tool): void
    {
        if (!$tool instanceof stdClass || !isset($tool->type) || !is_string($tool->type)) {
            return;
        }

        if (in_array($tool->type, ['web_search_preview', 'web_search_preview_2025_03_11'], true)) {
            $tool->type = 'web_search';
        }
    }

    private function normalizeParameters(mixed &$value): void
    {
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                if ($key === 'parameters' && is_array($item) && $item === []) {
                    $value->{$key} = new stdClass();
                    continue;
                }

                $this->normalizeParameters($item);
                $value->{$key} = $item;
            }

            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            $this->normalizeParameters($item);
            $value[$key] = $item;
        }
    }
}
