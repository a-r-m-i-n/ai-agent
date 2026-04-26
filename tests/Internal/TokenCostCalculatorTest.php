<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tests\Internal;

use Armin\AiAgent\AiAgentTokenUsage;
use Armin\AiAgent\Internal\ModelMetadataRegistry;
use Armin\AiAgent\Internal\TokenCostCalculator;
use PHPUnit\Framework\TestCase;

final class TokenCostCalculatorTest extends TestCase
{
    public function testEstimateCalculatesOpenAiCachedInputCost(): void
    {
        $calculator = new TokenCostCalculator();
        $metadata = (new ModelMetadataRegistry())->find('openai:gpt-5.4');
        $usage = new AiAgentTokenUsage(input: 10000, cachedInput: 4000, output: 2000);

        $cost = $calculator->estimate($usage, $metadata);

        self::assertNotNull($cost);
        self::assertSame('$0.0460', $cost->formatUsd());
    }

    public function testEstimateFallsBackToNormalInputPriceWhenCachedPriceIsMissing(): void
    {
        $calculator = new TokenCostCalculator();
        $metadata = (new ModelMetadataRegistry())->find('openai:gpt-5.4');
        self::assertNotNull($metadata);

        $customMetadata = new \Armin\AiAgent\Internal\ModelMetadata(
            provider: $metadata->provider(),
            model: $metadata->model(),
            contextWindow: $metadata->contextWindow(),
            maxOutputTokens: $metadata->maxOutputTokens(),
            pricing: new \Armin\AiAgent\Internal\ModelPricing(
                inputPerMillionUsd: 2.5,
                cachedInputPerMillionUsd: null,
                outputPerMillionUsd: 15.0,
            ),
            source: $metadata->source(),
        );

        $cost = $calculator->estimate(new AiAgentTokenUsage(input: 1000, cachedInput: 300, output: 100), $customMetadata);

        self::assertNotNull($cost);
        self::assertSame('$0.0040', $cost->formatUsd());
    }

    public function testEstimateReturnsZeroForNullUsage(): void
    {
        $calculator = new TokenCostCalculator();
        $metadata = (new ModelMetadataRegistry())->find('gemini:gemini-2.5-flash-lite');

        $cost = $calculator->estimate(new AiAgentTokenUsage(), $metadata);

        self::assertNotNull($cost);
        self::assertSame('$0.0000', $cost->formatUsd());
    }

    public function testEstimateUsesTierPricingWhenThresholdIsMet(): void
    {
        $calculator = new TokenCostCalculator();
        $metadata = (new ModelMetadataRegistry())->find('gemini:gemini-2.5-pro');
        $usage = new AiAgentTokenUsage(input: 250000, cachedInput: 50000, output: 10000);

        $cost = $calculator->estimate($usage, $metadata);

        self::assertNotNull($cost);
        self::assertSame('$1.1925', $cost->formatUsd());
    }

    public function testEstimateIncludesOpenAiImageGenerationUsage(): void
    {
        $calculator = new TokenCostCalculator();
        $metadata = (new ModelMetadataRegistry())->find('openai:gpt-5.4');
        $usage = new AiAgentTokenUsage(
            input: 10000,
            cachedInput: 4000,
            output: 2000,
            imageGenerationInput: 100,
            imageGenerationOutput: 50,
        );

        $cost = $calculator->estimate($usage, $metadata);

        self::assertNotNull($cost);
        self::assertSame('$0.0490', $cost->formatUsd());
    }
}
