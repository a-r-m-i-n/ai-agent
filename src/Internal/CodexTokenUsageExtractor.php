<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

use Armin\CodexPhp\CodexResponse;
use Armin\CodexPhp\CodexTokenUsage;
use Armin\CodexPhp\Internal\Session\CodexSession;

final class CodexTokenUsageExtractor
{
    public function fromResponse(CodexResponse $response): CodexTokenUsage
    {
        return $this->fromMetadata($response->metadata(), toolCalls: $response->toolCalls());
    }

    public function fromSession(CodexSession $session): CodexTokenUsage
    {
        $usage = new CodexTokenUsage();
        $assistantMessagesSinceLastUser = 0;

        foreach ($session->messages() as $message) {
            if (($message['role'] ?? null) === 'user') {
                $assistantMessagesSinceLastUser = 0;

                continue;
            }

            if (($message['role'] ?? null) !== 'assistant') {
                continue;
            }

            $metadata = $message['metadata'] ?? null;
            if (!is_array($metadata)) {
                continue;
            }

            $toolCalls = $message['tool_calls'] ?? null;
            $usage = $usage->withAdded($this->fromMetadata(
                $metadata,
                includeNestedAssistantMessages: $assistantMessagesSinceLastUser === 0,
                toolCalls: is_array($toolCalls) ? array_values(array_filter($toolCalls, 'is_array')) : [],
            ));
            ++$assistantMessagesSinceLastUser;

            if (!is_array($toolCalls) || $toolCalls === []) {
                $assistantMessagesSinceLastUser = 0;
            }
        }

        return $usage;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param list<array<string, mixed>> $toolCalls
     */
    private function fromMetadata(array $metadata, bool $includeNestedAssistantMessages = true, array $toolCalls = []): CodexTokenUsage
    {
        $usage = is_array($metadata['final_response'] ?? null) ? $metadata['final_response'] : [];
        $normalUsage = is_array($usage['usage'] ?? null) ? $usage['usage'] : [];
        $imageUsage = $this->extractImageGenerationUsage($metadata['generated_images'] ?? []);
        $toolCallUsage = $this->extractToolCallUsage($toolCalls);
        $input = $this->readInt($normalUsage, 'input_tokens', 'promptTokenCount');
        $cachedInput = $this->readNestedInt($normalUsage, ['input_tokens_details', 'cached_tokens']);
        if ($cachedInput === 0) {
            $cachedInput = $this->readInt($normalUsage, 'cachedContentTokenCount', 'cache_read_input_tokens');
        }

        $output = $this->readInt($normalUsage, 'output_tokens', 'candidatesTokenCount');
        $reasoning = $this->readNestedInt($normalUsage, ['output_tokens_details', 'reasoning_tokens']);
        $total = $this->readInt($normalUsage, 'total_tokens', 'totalTokenCount');
        if ($total === 0) {
            $total = $input + $output;
        }

        $aggregatedUsage = new CodexTokenUsage(
            input: $input,
            cachedInput: $cachedInput,
            output: $output,
            reasoning: $reasoning,
            total: $total,
            imageGenerationInput: $imageUsage['input'],
            imageGenerationOutput: $imageUsage['output'],
            imageGenerationTotal: $imageUsage['total'],
            toolCalls: $toolCallUsage['count'],
            toolCallDetails: $toolCallUsage['details'],
        );

        if (!$includeNestedAssistantMessages) {
            return $aggregatedUsage;
        }

        foreach ($this->assistantMessageList($metadata) as $assistantMessage) {
            $assistantMetadata = $assistantMessage['metadata'] ?? null;
            if (!is_array($assistantMetadata)) {
                continue;
            }

            $nestedToolCalls = $assistantMessage['tool_calls'] ?? null;
            $aggregatedUsage = $aggregatedUsage->withAdded($this->fromMetadata(
                $assistantMetadata,
                toolCalls: is_array($nestedToolCalls) ? array_values(array_filter($nestedToolCalls, 'is_array')) : [],
            ));
        }

        return $aggregatedUsage;
    }

    /**
     * @param mixed $generatedImages
     * @return array{input: int, output: int, total: int}
     */
    private function extractImageGenerationUsage(mixed $generatedImages): array
    {
        if (!is_array($generatedImages)) {
            return ['input' => 0, 'output' => 0, 'total' => 0];
        }

        $input = 0;
        $output = 0;
        $total = 0;

        foreach ($generatedImages as $generatedImage) {
            if (!is_array($generatedImage)) {
                continue;
            }

            $imageGenUsage = $generatedImage['provider_response']['tool_usage']['image_gen'] ?? null;
            if (!is_array($imageGenUsage)) {
                continue;
            }

            $input += $this->readInt($imageGenUsage, 'input_tokens');
            $output += $this->readInt($imageGenUsage, 'output_tokens');
            $total += $this->readInt($imageGenUsage, 'total_tokens');
        }

        return ['input' => $input, 'output' => $output, 'total' => $total];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return list<array<string, mixed>>
     */
    private function assistantMessageList(array $metadata): array
    {
        $assistantMessages = $metadata['request_assistant_messages'] ?? null;

        if (!is_array($assistantMessages)) {
            return [];
        }

        return array_values(array_filter($assistantMessages, 'is_array'));
    }

    /**
     * @param list<array<string, mixed>> $toolCalls
     * @return array{count: int, details: array<string, int>}
     */
    private function extractToolCallUsage(array $toolCalls): array
    {
        $details = [];

        foreach ($toolCalls as $toolCall) {
            $name = $toolCall['name'] ?? null;

            if (!is_string($name) || $name === '') {
                continue;
            }

            if (!isset($details[$name])) {
                $details[$name] = 0;
            }

            ++$details[$name];
        }

        ksort($details);

        return [
            'count' => array_sum($details),
            'details' => $details,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function readInt(array $payload, string ...$keys): int
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if (is_int($value)) {
                return $value;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $path
     */
    private function readNestedInt(array $payload, array $path): int
    {
        $value = $payload;

        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return 0;
            }

            $value = $value[$segment];
        }

        return is_int($value) ? $value : 0;
    }
}
