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
        $usage = $this->fromMetadata($response->metadata());

        foreach ($this->assistantMetadataList($response->metadata()) as $metadata) {
            $usage = $usage->withAdded($this->fromMetadata($metadata));
        }

        return $usage;
    }

    public function fromSession(CodexSession $session): CodexTokenUsage
    {
        $usage = new CodexTokenUsage();

        foreach ($session->messages() as $message) {
            if (($message['role'] ?? null) !== 'assistant') {
                continue;
            }

            $metadata = $message['metadata'] ?? null;
            if (!is_array($metadata)) {
                continue;
            }

            $usage = $usage->withAdded($this->fromMetadata($metadata));
        }

        return $usage;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function fromMetadata(array $metadata): CodexTokenUsage
    {
        $usage = is_array($metadata['final_response'] ?? null) ? $metadata['final_response'] : [];
        $normalUsage = is_array($usage['usage'] ?? null) ? $usage['usage'] : [];
        $imageUsage = $this->extractImageGenerationUsage($metadata['generated_images'] ?? []);

        return new CodexTokenUsage(
            input: $this->readInt($normalUsage, 'input_tokens'),
            cachedInput: $this->readNestedInt($normalUsage, ['input_tokens_details', 'cached_tokens']),
            output: $this->readInt($normalUsage, 'output_tokens'),
            reasoning: $this->readNestedInt($normalUsage, ['output_tokens_details', 'reasoning_tokens']),
            total: $this->readInt($normalUsage, 'total_tokens'),
            imageGenerationInput: $imageUsage['input'],
            imageGenerationOutput: $imageUsage['output'],
            imageGenerationTotal: $imageUsage['total'],
        );
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
    private function assistantMetadataList(array $metadata): array
    {
        $assistantMessages = $metadata['request_assistant_messages'] ?? null;

        if (!is_array($assistantMessages)) {
            return [];
        }

        $normalized = [];

        foreach ($assistantMessages as $assistantMessage) {
            if (!is_array($assistantMessage)) {
                continue;
            }

            $assistantMetadata = $assistantMessage['metadata'] ?? null;
            if (is_array($assistantMetadata)) {
                $normalized[] = $assistantMetadata;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function readInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        return is_int($value) ? $value : 0;
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
