<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Internal;

use Armin\CodexPhp\Internal\CodexResponseMapper;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;

final class CodexResponseMapperTest extends TestCase
{
    public function testMapCollectsTextAndToolCalls(): void
    {
        $mapper = new CodexResponseMapper();
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
