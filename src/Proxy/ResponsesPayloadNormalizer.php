<?php

declare(strict_types=1);

namespace CodexAuthProxy\Proxy;

use stdClass;

final class ResponsesPayloadNormalizer
{
    public function normalizeHttp(string $payload): string
    {
        return $this->normalizeHttpWithReport($payload)->payload();
    }

    public function normalizeWebSocket(string $payload): string
    {
        return $this->normalizeWebSocketWithReport($payload)->payload();
    }

    public function normalizeHttpWithReport(string $payload): NormalizedPayload
    {
        return $this->normalize($payload, false);
    }

    public function normalizeWebSocketWithReport(string $payload): NormalizedPayload
    {
        return $this->normalize($payload, true);
    }

    private function normalize(string $payload, bool $websocket): NormalizedPayload
    {
        try {
            $decoded = json_decode($payload, false, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new NormalizedPayload($payload, []);
        }

        if (!$decoded instanceof stdClass && !is_array($decoded)) {
            return new NormalizedPayload($payload, []);
        }

        $mutations = [];
        if ($websocket && $decoded instanceof stdClass && ($decoded->type ?? null) !== 'response.create') {
            $decoded->type = 'response.create';
            $mutations[] = 'websocket.type.response_create';
        }
        if (!$websocket && $decoded instanceof stdClass) {
            $this->normalizeHttpCompatibility($decoded, $mutations);
        }

        $this->normalizeParameters($decoded, $mutations);
        if ($mutations === []) {
            return new NormalizedPayload($payload, []);
        }

        return new NormalizedPayload(
            json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            array_values(array_unique($mutations)),
        );
    }

    /** @param list<string> $mutations */
    private function normalizeHttpCompatibility(stdClass $payload, array &$mutations): void
    {
        if (property_exists($payload, 'instructions') && $payload->instructions === null) {
            $payload->instructions = '';
            $mutations[] = 'http.instructions.null_to_empty';
        }

        if (property_exists($payload, 'input') && is_string($payload->input)) {
            $payload->input = [$this->messageInput($payload->input)];
            $mutations[] = 'http.input.string_to_message';
        }
        if (property_exists($payload, 'input') && is_array($payload->input)) {
            foreach ($payload->input as $item) {
                if ($item instanceof stdClass && ($item->role ?? null) === 'system') {
                    $item->role = 'developer';
                    $mutations[] = 'http.input.system_role_to_developer';
                }
            }
        }

        $this->normalizeBuiltinTools($payload, $mutations);
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

    /** @param list<string> $mutations */
    private function normalizeBuiltinTools(stdClass $payload, array &$mutations): void
    {
        if (isset($payload->tools) && is_array($payload->tools)) {
            foreach ($payload->tools as $tool) {
                $this->normalizeBuiltinTool($tool, $mutations);
            }
        }

        if (isset($payload->tool_choice) && $payload->tool_choice instanceof stdClass) {
            $this->normalizeBuiltinTool($payload->tool_choice, $mutations);
            if (isset($payload->tool_choice->tools) && is_array($payload->tool_choice->tools)) {
                foreach ($payload->tool_choice->tools as $tool) {
                    $this->normalizeBuiltinTool($tool, $mutations);
                }
            }
        }
    }

    /** @param list<string> $mutations */
    private function normalizeBuiltinTool(mixed $tool, array &$mutations): void
    {
        if (!$tool instanceof stdClass || !isset($tool->type) || !is_string($tool->type)) {
            return;
        }

        if (in_array($tool->type, ['web_search_preview', 'web_search_preview_2025_03_11'], true)) {
            $tool->type = 'web_search';
            $mutations[] = 'tool.web_search_preview_to_web_search';
        }
    }

    /** @param list<string> $mutations */
    private function normalizeParameters(mixed &$value, array &$mutations): void
    {
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                if ($key === 'parameters' && is_array($item) && $item === []) {
                    $value->{$key} = new stdClass();
                    $mutations[] = 'parameters.empty_array_to_object';
                    continue;
                }

                $this->normalizeParameters($item, $mutations);
                $value->{$key} = $item;
            }

            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            $this->normalizeParameters($item, $mutations);
            $value[$key] = $item;
        }
    }
}
