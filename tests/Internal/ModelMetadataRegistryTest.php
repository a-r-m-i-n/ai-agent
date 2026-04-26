<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tests\Internal;

use Armin\AiAgent\Internal\ModelMetadataRegistry;
use PHPUnit\Framework\TestCase;

final class ModelMetadataRegistryTest extends TestCase
{
    public function testKnownModelReturnsMetadata(): void
    {
        $registry = new ModelMetadataRegistry();

        $metadata = $registry->find('openai:gpt-5.4');

        self::assertNotNull($metadata);
        self::assertSame(1050000, $metadata->contextWindow());
        self::assertSame(2.5, $metadata->pricing()->inputPerMillionUsd());
        self::assertSame(0.25, $metadata->pricing()->cachedInputPerMillionUsd());
        self::assertSame(15.0, $metadata->pricing()->outputPerMillionUsd());
        self::assertSame(10.0, $metadata->pricing()->imageInputPerMillionUsd());
        self::assertSame(40.0, $metadata->pricing()->imageOutputPerMillionUsd());
    }

    public function testUnknownModelReturnsNull(): void
    {
        $registry = new ModelMetadataRegistry();

        self::assertNull($registry->find('openai:does-not-exist'));
    }

    public function testRegistryJsonIsParseableAndContainsAllExpectedModels(): void
    {
        $path = dirname(__DIR__, 2) . '/src/Internal/Resources/models.json';
        $contents = file_get_contents($path);

        self::assertIsString($contents);

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertCount(10, $decoded['models']);
    }
}
