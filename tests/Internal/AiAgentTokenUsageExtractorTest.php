<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tests\Internal;

use Armin\AiAgent\AiAgentResponse;
use Armin\AiAgent\Internal\AiAgentTokenUsageExtractor;
use PHPUnit\Framework\TestCase;

final class AiAgentTokenUsageExtractorTest extends TestCase
{
    public function testFromResponseExtractsImageGenerationUsage(): void
    {
        $extractor = new AiAgentTokenUsageExtractor();
        $response = new AiAgentResponse(
            content: 'done',
            model: 'openai:gpt-5',
            metadata: [
                'generated_images' => [
                    [
                        'provider_response' => [
                            'tool_usage' => [
                                'image_gen' => [
                                    'input_tokens' => 12,
                                    'output_tokens' => 34,
                                    'total_tokens' => 46,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        );

        self::assertSame([
            'input' => 0,
            'cached_input' => 0,
            'output' => 0,
            'reasoning' => 0,
            'total' => 0,
            'image_generation_input' => 12,
            'image_generation_output' => 34,
            'image_generation_total' => 46,
            'tool_calls' => 0,
            'tool_call_details' => [],
        ], $extractor->fromResponse($response)->toArray());
    }

    public function testFromResponseDefaultsMissingUsageDetailFieldsToZero(): void
    {
        $extractor = new AiAgentTokenUsageExtractor();
        $response = new AiAgentResponse(
            content: 'done',
            model: 'openai:gpt-5',
            metadata: [
                'final_response' => [
                    'usage' => [
                        'input_tokens' => 9,
                        'output_tokens' => 4,
                        'total_tokens' => 13,
                    ],
                ],
            ],
        );

        self::assertSame([
            'input' => 9,
            'cached_input' => 0,
            'output' => 4,
            'reasoning' => 0,
            'total' => 13,
            'image_generation_input' => 0,
            'image_generation_output' => 0,
            'image_generation_total' => 0,
            'tool_calls' => 0,
            'tool_call_details' => [],
        ], $extractor->fromResponse($response)->toArray());
    }

    public function testFromResponseAggregatesNestedAssistantMetadataRecursively(): void
    {
        $extractor = new AiAgentTokenUsageExtractor();
        $response = new AiAgentResponse(
            content: 'done',
            model: 'openai:gpt-5',
            metadata: [
                'final_response' => [
                    'usage' => [
                        'input_tokens' => 9,
                        'output_tokens' => 4,
                        'total_tokens' => 13,
                    ],
                ],
                'request_assistant_messages' => [
                    [
                        'metadata' => [
                            'final_response' => [
                                'usage' => [
                                    'input_tokens' => 5,
                                    'output_tokens' => 2,
                                    'total_tokens' => 7,
                                ],
                            ],
                            'generated_images' => [
                                [
                                    'provider_response' => [
                                        'tool_usage' => [
                                            'image_gen' => [
                                                'input_tokens' => 10,
                                                'output_tokens' => 20,
                                                'total_tokens' => 30,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        );

        self::assertSame([
            'input' => 14,
            'cached_input' => 0,
            'output' => 6,
            'reasoning' => 0,
            'total' => 20,
            'image_generation_input' => 10,
            'image_generation_output' => 20,
            'image_generation_total' => 30,
            'tool_calls' => 0,
            'tool_call_details' => [],
        ], $extractor->fromResponse($response)->toArray());
    }

    public function testFromResponseNormalizesGeminiCachedInputMetadata(): void
    {
        $extractor = new AiAgentTokenUsageExtractor();
        $response = new AiAgentResponse(
            content: 'done',
            model: 'gemini:gemini-2.5-flash',
            metadata: [
                'final_response' => [
                    'usage' => [
                        'promptTokenCount' => 120,
                        'cachedContentTokenCount' => 40,
                        'candidatesTokenCount' => 15,
                        'totalTokenCount' => 135,
                    ],
                ],
            ],
        );

        self::assertSame([
            'input' => 120,
            'cached_input' => 40,
            'output' => 15,
            'reasoning' => 0,
            'total' => 135,
            'image_generation_input' => 0,
            'image_generation_output' => 0,
            'image_generation_total' => 0,
            'tool_calls' => 0,
            'tool_call_details' => [],
        ], $extractor->fromResponse($response)->toArray());
    }

    public function testFromResponseNormalizesAnthropicCacheReadTokens(): void
    {
        $extractor = new AiAgentTokenUsageExtractor();
        $response = new AiAgentResponse(
            content: 'done',
            model: 'anthropic:claude-sonnet-4-6',
            metadata: [
                'final_response' => [
                    'usage' => [
                        'input_tokens' => 90,
                        'cache_read_input_tokens' => 30,
                        'output_tokens' => 10,
                    ],
                ],
            ],
        );

        self::assertSame([
            'input' => 90,
            'cached_input' => 30,
            'output' => 10,
            'reasoning' => 0,
            'total' => 100,
            'image_generation_input' => 0,
            'image_generation_output' => 0,
            'image_generation_total' => 0,
            'tool_calls' => 0,
            'tool_call_details' => [],
        ], $extractor->fromResponse($response)->toArray());
    }
}
