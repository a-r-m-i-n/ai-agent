<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tests\Internal;

use Armin\AiAgent\Exception\InvalidModel;
use Armin\AiAgent\Internal\ModelNameParser;
use PHPUnit\Framework\TestCase;

final class ModelNameParserTest extends TestCase
{
    public function testParseSupportsProviderPrefixedModelNames(): void
    {
        $parser = new ModelNameParser();
        $resolved = $parser->parse('gemini:gemini-2.5-flash-lite');

        self::assertSame('gemini', $resolved->provider());
        self::assertSame('gemini-2.5-flash-lite', $resolved->model());
        self::assertSame('gemini:gemini-2.5-flash-lite', $resolved->qualifiedName());
    }

    public function testParseRequiresProviderPrefix(): void
    {
        $parser = new ModelNameParser();

        $this->expectException(InvalidModel::class);

        $parser->parse('gpt-5');
    }

    public function testParseRejectsUnsupportedProvider(): void
    {
        $parser = new ModelNameParser();

        $this->expectException(InvalidModel::class);

        $parser->parse('ollama:llama3');
    }
}
