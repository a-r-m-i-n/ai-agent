<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tests;

use Armin\AiAgent\AiAgentTokenUsage;
use PHPUnit\Framework\TestCase;

final class AiAgentTokenUsageTest extends TestCase
{
    public function testToJsonFiltersZeroValuesButKeepsTotal(): void
    {
        $usage = new AiAgentTokenUsage(
            input: 10,
            cachedInput: 0,
            output: 2,
            reasoning: 0,
            total: 0,
            imageGenerationInput: 0,
            imageGenerationOutput: 4,
            imageGenerationTotal: 0,
            toolCalls: 2,
            toolCallDetails: ['read_file' => 2],
        );

        self::assertSame(
            '{"input":10,"output":2,"total":0,"image_generation_output":4,"tool_calls":2,"tool_call_details":{"read_file":2}}',
            $usage->toJson(),
        );
    }

    public function testToJsonSupportsPrettyPrinting(): void
    {
        $usage = new AiAgentTokenUsage(
            input: 1,
            total: 1,
            toolCalls: 1,
            toolCallDetails: ['shell' => 1],
        );

        self::assertSame(
            "{\n    \"input\": 1,\n    \"total\": 1,\n    \"tool_calls\": 1,\n    \"tool_call_details\": {\n        \"shell\": 1\n    }\n}",
            $usage->toJson(true),
        );
    }
}
