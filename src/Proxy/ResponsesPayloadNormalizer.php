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

        $changed = false;
        if ($websocket && $decoded instanceof stdClass && ($decoded->type ?? null) !== 'response.create') {
            $decoded->type = 'response.create';
            $changed = true;
        }
        if (!$websocket && $decoded instanceof stdClass) {
            $changed = $this->normalizeHttpCompatibility($decoded) || $changed;
        }

        $changed = $this->normalizeParameters($decoded) || $changed;
        if (!$changed) {
            return $payload;
        }

        return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function normalizeHttpCompatibility(stdClass $payload): bool
    {
        $changed = false;
        if (property_exists($payload, 'instructions') && $payload->instructions === null) {
            $payload->instructions = '';
            $changed = true;
        }

        if (property_exists($payload, 'input') && is_string($payload->input)) {
            $payload->input = [$this->messageInput($payload->input)];
            $changed = true;
        }
        if (property_exists($payload, 'input') && is_array($payload->input)) {
            foreach ($payload->input as $item) {
                if ($item instanceof stdClass && ($item->role ?? null) === 'system') {
                    $item->role = 'developer';
                    $changed = true;
                }
            }
        }

        return $this->normalizeBuiltinTools($payload) || $changed;
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

    private function normalizeBuiltinTools(stdClass $payload): bool
    {
        $changed = false;
        if (isset($payload->tools) && is_array($payload->tools)) {
            foreach ($payload->tools as $tool) {
                $changed = $this->normalizeBuiltinTool($tool) || $changed;
            }
        }

        if (isset($payload->tool_choice) && $payload->tool_choice instanceof stdClass) {
            $changed = $this->normalizeBuiltinTool($payload->tool_choice) || $changed;
            if (isset($payload->tool_choice->tools) && is_array($payload->tool_choice->tools)) {
                foreach ($payload->tool_choice->tools as $tool) {
                    $changed = $this->normalizeBuiltinTool($tool) || $changed;
                }
            }
        }

        return $changed;
    }

    private function normalizeBuiltinTool(mixed $tool): bool
    {
        if (!$tool instanceof stdClass || !isset($tool->type) || !is_string($tool->type)) {
            return false;
        }

        if (in_array($tool->type, ['web_search_preview', 'web_search_preview_2025_03_11'], true)) {
            $tool->type = 'web_search';
            return true;
        }

        return false;
    }

    private function normalizeParameters(mixed &$value): bool
    {
        $changed = false;
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                if ($key === 'parameters' && is_array($item) && $item === []) {
                    $value->{$key} = new stdClass();
                    $changed = true;
                    continue;
                }

                $changed = $this->normalizeParameters($item) || $changed;
                $value->{$key} = $item;
            }

            return $changed;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            $changed = $this->normalizeParameters($item) || $changed;
            $value[$key] = $item;
        }

        return $changed;
    }
}
