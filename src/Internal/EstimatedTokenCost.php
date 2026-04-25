<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

final class EstimatedTokenCost
{
    public function __construct(
        private readonly float $usd,
    ) {
    }

    public function usd(): float
    {
        return $this->usd;
    }

    public function formatUsd(): string
    {
        return '$' . number_format($this->usd, 4, '.', '');
    }
}
