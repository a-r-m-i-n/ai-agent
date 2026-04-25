<?php

declare(strict_types=1);

namespace Armin\CodexPhp;

final class CodexTokenUsage
{
    /**
     * @param array<string, int> $toolCallDetails
     */
    public function __construct(
        private readonly int $input = 0,
        private readonly int $cachedInput = 0,
        private readonly int $output = 0,
        private readonly int $reasoning = 0,
        private readonly int $total = 0,
        private readonly int $imageGenerationInput = 0,
        private readonly int $imageGenerationOutput = 0,
        private readonly int $imageGenerationTotal = 0,
        private readonly int $toolCalls = 0,
        private readonly array $toolCallDetails = [],
    ) {
    }

    public function input(): int
    {
        return $this->input;
    }

    public function cachedInput(): int
    {
        return $this->cachedInput;
    }

    public function output(): int
    {
        return $this->output;
    }

    public function reasoning(): int
    {
        return $this->reasoning;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function imageGenerationInput(): int
    {
        return $this->imageGenerationInput;
    }

    public function imageGenerationOutput(): int
    {
        return $this->imageGenerationOutput;
    }

    public function imageGenerationTotal(): int
    {
        return $this->imageGenerationTotal;
    }

    public function toolCalls(): int
    {
        return $this->toolCalls;
    }

    /**
     * @return array<string, int>
     */
    public function toolCallDetails(): array
    {
        return $this->toolCallDetails;
    }

    public function withAdded(self $usage): self
    {
        return new self(
            $this->input + $usage->input,
            $this->cachedInput + $usage->cachedInput,
            $this->output + $usage->output,
            $this->reasoning + $usage->reasoning,
            $this->total + $usage->total,
            $this->imageGenerationInput + $usage->imageGenerationInput,
            $this->imageGenerationOutput + $usage->imageGenerationOutput,
            $this->imageGenerationTotal + $usage->imageGenerationTotal,
            $this->toolCalls + $usage->toolCalls,
            $this->mergeToolCallDetails($usage->toolCallDetails),
        );
    }

    /**
     * @return array{
     *     input: int,
     *     cached_input: int,
     *     output: int,
     *     reasoning: int,
     *     total: int,
     *     image_generation_input: int,
     *     image_generation_output: int,
     *     image_generation_total: int,
     *     tool_calls: int,
     *     tool_call_details: array<string, int>
     * }
     */
    public function toArray(): array
    {
        return [
            'input' => $this->input,
            'cached_input' => $this->cachedInput,
            'output' => $this->output,
            'reasoning' => $this->reasoning,
            'total' => $this->total,
            'image_generation_input' => $this->imageGenerationInput,
            'image_generation_output' => $this->imageGenerationOutput,
            'image_generation_total' => $this->imageGenerationTotal,
            'tool_calls' => $this->toolCalls,
            'tool_call_details' => $this->toolCallDetails,
        ];
    }

    public function toJson(bool $pretty = false): string
    {
        $usage = array_filter(
            $this->toArray(),
            static fn (mixed $value, string $key): bool => match (true) {
                $key === 'total' => true,
                is_int($value) => $value !== 0,
                is_array($value) => $value !== [],
                default => false,
            },
            ARRAY_FILTER_USE_BOTH,
        );

        $flags = JSON_THROW_ON_ERROR;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($usage, $flags);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * @param array<string, int> $otherDetails
     * @return array<string, int>
     */
    private function mergeToolCallDetails(array $otherDetails): array
    {
        $merged = $this->toolCallDetails;

        foreach ($otherDetails as $toolName => $count) {
            if (!isset($merged[$toolName])) {
                $merged[$toolName] = 0;
            }

            $merged[$toolName] += $count;
        }

        ksort($merged);

        return $merged;
    }
}
