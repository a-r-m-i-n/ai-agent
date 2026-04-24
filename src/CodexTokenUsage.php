<?php

declare(strict_types=1);

namespace Armin\CodexPhp;

final class CodexTokenUsage
{
    public function __construct(
        private readonly int $input = 0,
        private readonly int $cachedInput = 0,
        private readonly int $output = 0,
        private readonly int $reasoning = 0,
        private readonly int $total = 0,
        private readonly int $imageGenerationInput = 0,
        private readonly int $imageGenerationOutput = 0,
        private readonly int $imageGenerationTotal = 0,
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
     *     image_generation_total: int
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
        ];
    }

    public function toJson(bool $pretty = false): string
    {
        $usage = array_filter(
            $this->toArray(),
            static fn (int $value, string $key): bool => $key === 'total' || $value !== 0,
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
}
