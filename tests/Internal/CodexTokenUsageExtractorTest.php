<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Internal;

use Armin\CodexPhp\CodexResponse;
use Armin\CodexPhp\Internal\CodexTokenUsageExtractor;
use PHPUnit\Framework\TestCase;

final class CodexTokenUsageExtractorTest extends TestCase
{
    public function testFromResponseExtractsImageGenerationUsage(): void
    {
        $extractor = new CodexTokenUsageExtractor();
        $response = new CodexResponse(
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
        $extractor = new CodexTokenUsageExtractor();
        $response = new CodexResponse(
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
        $extractor = new CodexTokenUsageExtractor();
        $response = new CodexResponse(
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
}
