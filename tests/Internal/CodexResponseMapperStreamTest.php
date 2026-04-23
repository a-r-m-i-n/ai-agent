<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Internal;

use Armin\CodexPhp\Internal\CodexResponseMapper;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;

final class CodexResponseMapperStreamTest extends TestCase
{
    public function testMapConsumesStreamResultIntoFinalContent(): void
    {
        $mapper = new CodexResponseMapper();
        $result = new StreamResult((function (): \Generator {
            yield new TextDelta('Hel');
            yield new TextDelta('lo');
        })());

        $response = $mapper->map('openai:gpt-5', $result);

        self::assertSame('Hello', $response->content());
        self::assertSame('openai:gpt-5', $response->model());
    }
}
