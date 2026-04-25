<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Internal;

use Armin\CodexPhp\CodexTokenUsage;
use Armin\CodexPhp\Internal\ModelMetadataRegistry;
use Armin\CodexPhp\Internal\TokenCostCalculator;
use PHPUnit\Framework\TestCase;

final class TokenCostCalculatorTest extends TestCase
{
    public function testEstimateCalculatesOpenAiCachedInputCost(): void
    {
        $calculator = new TokenCostCalculator();
        $metadata = (new ModelMetadataRegistry())->find('openai:gpt-5.4');
        $usage = new CodexTokenUsage(input: 10000, cachedInput: 4000, output: 2000);

        $cost = $calculator->estimate($usage, $metadata);

        self::assertNotNull($cost);
        self::assertSame('$0.0460', $cost->formatUsd());
    }

    public function testEstimateFallsBackToNormalInputPriceWhenCachedPriceIsMissing(): void
    {
        $calculator = new TokenCostCalculator();
        $metadata = (new ModelMetadataRegistry())->find('openai:gpt-5.4');
        self::assertNotNull($metadata);

        $customMetadata = new \Armin\CodexPhp\Internal\ModelMetadata(
            provider: $metadata->provider(),
            model: $metadata->model(),
            contextWindow: $metadata->contextWindow(),
            maxOutputTokens: $metadata->maxOutputTokens(),
            pricing: new \Armin\CodexPhp\Internal\ModelPricing(
                inputPerMillionUsd: 2.5,
                cachedInputPerMillionUsd: null,
                outputPerMillionUsd: 15.0,
            ),
            source: $metadata->source(),
        );

        $cost = $calculator->estimate(new CodexTokenUsage(input: 1000, cachedInput: 300, output: 100), $customMetadata);

        self::assertNotNull($cost);
        self::assertSame('$0.0040', $cost->formatUsd());
    }

    public function testEstimateReturnsZeroForNullUsage(): void
    {
        $calculator = new TokenCostCalculator();
        $metadata = (new ModelMetadataRegistry())->find('gemini:gemini-2.5-flash-lite');

        $cost = $calculator->estimate(new CodexTokenUsage(), $metadata);

        self::assertNotNull($cost);
        self::assertSame('$0.0000', $cost->formatUsd());
    }

    public function testEstimateUsesTierPricingWhenThresholdIsMet(): void
    {
        $calculator = new TokenCostCalculator();
        $metadata = (new ModelMetadataRegistry())->find('gemini:gemini-2.5-pro');
        $usage = new CodexTokenUsage(input: 250000, cachedInput: 50000, output: 10000);

        $cost = $calculator->estimate($usage, $metadata);

        self::assertNotNull($cost);
        self::assertSame('$1.1925', $cost->formatUsd());
    }
}
