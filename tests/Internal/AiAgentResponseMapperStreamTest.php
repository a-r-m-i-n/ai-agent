<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tests\Internal;

use Armin\AiAgent\Internal\AiAgentResponseMapper;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\ToolCall;

final class AiAgentResponseMapperStreamTest extends TestCase
{
    public function testMapConsumesStreamResultIntoFinalContent(): void
    {
        $mapper = new AiAgentResponseMapper();
        $result = new StreamResult((function (): \Generator {
            yield new TextDelta('Hel');
            yield new TextDelta('lo');
        })());

        $response = $mapper->map('openai:gpt-5', $result);

        self::assertSame('Hello', $response->content());
        self::assertSame('openai:gpt-5', $response->model());
    }

    public function testMapConsumesStreamToolCalls(): void
    {
        $mapper = new AiAgentResponseMapper();
        $result = new StreamResult((function (): \Generator {
            yield new TextDelta('Need ');
            yield new ToolCallComplete([
                new ToolCall('call-1', 'read_file', ['path' => 'composer.json']),
            ]);
        })());

        $response = $mapper->map('openai:gpt-5', $result);

        self::assertSame('Need ', $response->content());
        self::assertCount(1, $response->toolCalls());
        self::assertSame('read_file', $response->toolCalls()[0]['name']);
    }
}
