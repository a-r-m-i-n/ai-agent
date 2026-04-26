<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tests\Internal;

use Armin\AiAgent\Internal\AiAgentResponseMapper;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;

final class AiAgentResponseMapperTest extends TestCase
{
    public function testMapCollectsTextAndToolCalls(): void
    {
        $mapper = new AiAgentResponseMapper();
        $result = new MultiPartResult([
            new TextResult('before '),
            new ToolCallResult([
                new ToolCall('call-1', 'read_file', ['path' => '/tmp/example.txt']),
            ]),
            new TextResult('after'),
        ]);
        $result->getMetadata()->add('provider', 'openai');

        $response = $mapper->map('openai:gpt-5', $result);

        self::assertSame('before after', $response->content());
        self::assertSame('openai:gpt-5', $response->model());
        self::assertSame('read_file', $response->toolCalls()[0]['name']);
        self::assertSame('openai', $response->metadata()['provider']);
    }
}
