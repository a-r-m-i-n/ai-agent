<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Internal;

use Armin\CodexPhp\Internal\ContextUsageFormatter;
use Armin\CodexPhp\Internal\ModelMetadataRegistry;
use PHPUnit\Framework\TestCase;

final class ContextUsageFormatterTest extends TestCase
{
    public function testFormatsPercentageForKnownModel(): void
    {
        $formatter = new ContextUsageFormatter();
        $metadata = (new ModelMetadataRegistry())->find('openai:gpt-5.4');

        self::assertSame('12.444 (1,2%)', $formatter->format(12444, $metadata));
    }

    public function testFormatsWithoutPercentageForUnknownModel(): void
    {
        $formatter = new ContextUsageFormatter();

        self::assertSame('12.444', $formatter->format(12444, null));
    }
}
