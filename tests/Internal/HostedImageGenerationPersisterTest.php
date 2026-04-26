<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tests\Internal;

use Armin\AiAgent\Internal\HostedImageGenerationPersister;
use PHPUnit\Framework\TestCase;

final class HostedImageGenerationPersisterTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . '/ai-agent-hosted-image-' . bin2hex(random_bytes(4));
        mkdir($this->tempDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDirectory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tempDirectory);
    }

    public function testPersistSavesHostedOpenAiImageUsingPromptFilenameAndAttachedImageDirectory(): void
    {
        mkdir($this->tempDirectory . '/test_file', 0777, true);

        $metadata = [
            'final_response' => [
                'tool_usage' => [
                    'image_gen' => [
                        'input_tokens' => 10,
                        'output_tokens' => 20,
                        'total_tokens' => 30,
                    ],
                ],
            ],
            'stream_events' => [[
                'type' => 'response.image_generation_call.partial_image',
                'partial_image_b64' => base64_encode('image-binary'),
                'output_format' => 'jpeg',
                'revised_prompt' => 'expressionist cat',
            ]],
        ];

        $images = (new HostedImageGenerationPersister($this->tempDirectory))->persist(
            'openai',
            'gpt-5.4-mini',
            "Erstelle ein neues Bild 'cat.jpg' im selben Ordner.",
            $metadata,
            [['path' => $this->tempDirectory . '/test_file/image.jpg']],
        );

        self::assertCount(1, $images);
        self::assertSame($this->tempDirectory . '/test_file/cat.jpg', $images[0]['path']);
        self::assertSame('cat.jpg', $images[0]['filename']);
        self::assertSame('image/jpeg', $images[0]['mime_type']);
        self::assertSame('image-binary', file_get_contents($this->tempDirectory . '/test_file/cat.jpg'));
    }
}
