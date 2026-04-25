<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

use Armin\CodexPhp\CodexTokenUsage;

final class TokenCostCalculator
{
    public function estimate(CodexTokenUsage $usage, ?ModelMetadata $metadata): ?EstimatedTokenCost
    {
        if (!$metadata instanceof ModelMetadata) {
            return null;
        }

        $pricing = $this->resolvePricing($metadata->pricing(), $usage);
        $cachedInput = max($usage->cachedInput(), 0);
        $uncachedInput = max($usage->input() - $cachedInput, 0);

        $inputCost = $uncachedInput * $pricing->inputPerMillionUsd();
        $outputCost = $usage->output() * $pricing->outputPerMillionUsd();
        $cachedInputCost = 0.0;

        if ($cachedInput > 0) {
            $cachedPrice = $pricing->cachedInputPerMillionUsd() ?? $pricing->inputPerMillionUsd();
            $cachedInputCost = $cachedInput * $cachedPrice;
        }

        return new EstimatedTokenCost(($inputCost + $cachedInputCost + $outputCost) / 1_000_000);
    }

    private function resolvePricing(ModelPricing $pricing, CodexTokenUsage $usage): ModelPricing
    {
        foreach ($pricing->tiers() as $tier) {
            $condition = $tier['condition'] ?? null;

            if (!is_array($condition) || ($condition['type'] ?? null) !== 'input_tokens_gt' || !is_int($condition['value'] ?? null)) {
                continue;
            }

            if ($usage->input() <= $condition['value']) {
                continue;
            }

            return new ModelPricing(
                inputPerMillionUsd: is_numeric($tier['input_per_million_usd'] ?? null)
                    ? (float) $tier['input_per_million_usd']
                    : $pricing->inputPerMillionUsd(),
                cachedInputPerMillionUsd: is_numeric($tier['cached_input_per_million_usd'] ?? null)
                    ? (float) $tier['cached_input_per_million_usd']
                    : $pricing->cachedInputPerMillionUsd(),
                outputPerMillionUsd: is_numeric($tier['output_per_million_usd'] ?? null)
                    ? (float) $tier['output_per_million_usd']
                    : $pricing->outputPerMillionUsd(),
                notes: $pricing->notes(),
                tiers: $pricing->tiers(),
            );
        }

        return $pricing;
    }
}
