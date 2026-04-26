<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal;

final class ModelPricing
{
    /**
     * @param list<array<string, mixed>> $tiers
     */
    public function __construct(
        private readonly float $inputPerMillionUsd,
        private readonly ?float $cachedInputPerMillionUsd,
        private readonly float $outputPerMillionUsd,
        private readonly ?string $notes = null,
        private readonly array $tiers = [],
    ) {
    }

    public function inputPerMillionUsd(): float
    {
        return $this->inputPerMillionUsd;
    }

    public function cachedInputPerMillionUsd(): ?float
    {
        return $this->cachedInputPerMillionUsd;
    }

    public function outputPerMillionUsd(): float
    {
        return $this->outputPerMillionUsd;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tiers(): array
    {
        return $this->tiers;
    }
}
