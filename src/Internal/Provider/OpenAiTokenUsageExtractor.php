<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal\Provider;

use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

final class OpenAiTokenUsageExtractor implements TokenUsageExtractorInterface
{
    public function extract(RawResultInterface $rawResult, array $options = []): ?TokenUsage
    {
        if ($options['stream'] ?? false) {
            return null;
        }

        $rawBody = $rawResult->getObject()->getContent(false);
        if ($this->looksLikeStreamBody($rawBody)) {
            $data = $this->extractFinalResponseFromStreamBody($rawBody);

            if (!isset($data['usage']) || !is_array($data['usage'])) {
                return null;
            }

            return $this->fromDataArray($data);
        }

        $content = $rawResult->getData();

        if (!\array_key_exists('usage', $content)) {
            return null;
        }

        return $this->fromDataArray($content);
    }

    /**
     * @param array{usage: array{
     *     input_tokens?: int,
     *     input_tokens_details?: array{
     *         cached_tokens?: int,
     *     },
     *     output_tokens?: int,
     *     output_tokens_details?: array{
     *         reasoning_tokens?: int,
     *     },
     *     total_tokens?: int,
     * }} $data
     */
    public function fromDataArray(array $data): TokenUsage
    {
        return new TokenUsage(
            promptTokens: $data['usage']['input_tokens'] ?? null,
            completionTokens: $data['usage']['output_tokens'] ?? null,
            thinkingTokens: $data['usage']['output_tokens_details']['reasoning_tokens'] ?? null,
            cachedTokens: $data['usage']['input_tokens_details']['cached_tokens'] ?? null,
            totalTokens: $data['usage']['total_tokens'] ?? null,
        );
    }

    private function looksLikeStreamBody(string $rawBody): bool
    {
        $trimmed = ltrim($rawBody);

        return str_starts_with($trimmed, 'event:') || str_starts_with($trimmed, 'data:');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFinalResponseFromStreamBody(string $rawBody): array
    {
        preg_match_all('/^data:\s*(.+)$/m', $rawBody, $matches);

        foreach ($matches[1] ?? [] as $payload) {
            $event = json_decode($payload, true);

            if (!is_array($event)) {
                continue;
            }

            if (($event['type'] ?? null) === 'response.completed' && isset($event['response']) && is_array($event['response'])) {
                return $event['response'];
            }
        }

        return [];
    }
}
