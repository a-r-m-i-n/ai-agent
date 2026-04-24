<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Internal;

use Armin\CodexPhp\Exception\ModelDoesNotSupportImageOutput;
use Armin\CodexPhp\Internal\ImageGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAi\DallE\Base64Image;
use Symfony\AI\Platform\Bridge\OpenAi\DallE\ImageResult;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Armin\CodexPhp\Internal\ResolvedAuth;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;

final class ImageGeneratorTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . '/codex-php-image-generator-' . bin2hex(random_bytes(4));
        mkdir($this->tempDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDirectory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tempDirectory);
    }

    public function testOpenAiImageResultIsStoredWithGeneratedDefaultFilename(): void
    {
        $generator = new ImageGenerator($this->tempDirectory);
        $platform = $this->createPlatform(
            [Capability::OUTPUT_IMAGE],
            new ImageResult('revised', [new Base64Image(base64_encode('png-binary'))]),
        );

        $image = $generator->generate($platform, 'openai', 'gpt-image-1', ['prompt' => 'Draw a tree']);

        self::assertMatchesRegularExpression('#/new_image_[a-f0-9]{12}\.png$#', $image->toMetadata()['path']);
        self::assertSame('image/png', $image->toMetadata()['mime_type']);
        self::assertSame('revised', $image->toMetadata()['revised_prompt']);
        self::assertSame('png-binary', file_get_contents($image->toMetadata()['path']));
    }

    public function testBinaryResultUsesProvidedFilenameAndMimeTypeExtension(): void
    {
        $generator = new ImageGenerator($this->tempDirectory);
        $platform = $this->createPlatform(
            [Capability::OUTPUT_IMAGE],
            new BinaryResult('webp-binary', 'image/webp'),
        );

        $image = $generator->generate($platform, 'gemini', 'gemini-2.5-flash-image', [
            'prompt' => 'Draw a fox',
            'filename' => 'fox',
        ]);

        self::assertSame($this->tempDirectory . '/fox.webp', $image->toMetadata()['path']);
        self::assertSame('fox.webp', $image->toMetadata()['filename']);
    }

    public function testOverwriteFalseCreatesAlternativeFilename(): void
    {
        file_put_contents($this->tempDirectory . '/image.png', 'existing');
        $generator = new ImageGenerator($this->tempDirectory);
        $platform = $this->createPlatform(
            [Capability::OUTPUT_IMAGE],
            new BinaryResult('fresh', 'image/png'),
        );

        $image = $generator->generate($platform, 'gemini', 'gemini-2.5-flash-image', [
            'prompt' => 'Draw a mountain',
            'filename' => 'image.png',
            'overwrite' => false,
        ]);

        self::assertNotSame($this->tempDirectory . '/image.png', $image->toMetadata()['path']);
        self::assertSame('existing', file_get_contents($this->tempDirectory . '/image.png'));
        self::assertSame('fresh', file_get_contents($image->toMetadata()['path']));
    }

    public function testModelWithoutOutputImageSupportThrowsClearException(): void
    {
        $generator = new ImageGenerator($this->tempDirectory);
        $platform = $this->createPlatform([], new BinaryResult('data', 'image/png'));

        $this->expectException(ModelDoesNotSupportImageOutput::class);
        $this->expectExceptionMessage('does not support image output');

        $generator->generate($platform, 'openai', 'gpt-5.4-mini', ['prompt' => 'Draw a cat']);
    }

    public function testOpenAiTokenImageGenerationUsesResponsesEndpoint(): void
    {
        $capturedUrl = null;
        $capturedOptions = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedOptions) {
            $capturedUrl = $url;
            $capturedOptions = $options;

            return new MockResponse(implode("\n\n", [
                'event: response.image_generation_call.partial_image' . "\n" . 'data: ' . json_encode([
                    'type' => 'response.image_generation_call.partial_image',
                    'partial_image_b64' => base64_encode('token-image'),
                    'output_format' => 'png',
                    'revised_prompt' => 'revised',
                ], JSON_THROW_ON_ERROR),
                'event: response.completed' . "\n" . 'data: ' . json_encode([
                    'type' => 'response.completed',
                    'response' => ['id' => 'resp_1'],
                ], JSON_THROW_ON_ERROR),
            ]), ['http_code' => 200]);
        });
        $generator = new ImageGenerator(
            $this->tempDirectory,
            $httpClient,
            new ResolvedAuth('tokens', accessToken: 'access-123', accountId: 'account-456'),
        );
        $platform = $this->createPlatform([Capability::OUTPUT_IMAGE], new BinaryResult('unused', 'image/png'));

        $image = $generator->generate($platform, 'openai', 'gpt-5.4-mini', ['prompt' => 'Draw a tree']);

        self::assertSame('https://chatgpt.com/backend-api/codex/responses', $capturedUrl);
        self::assertStringContainsString('"type":"image_generation"', $capturedOptions['body']);
        self::assertStringContainsString('"type":"input_text"', $capturedOptions['body']);
        self::assertStringContainsString('"text":"Draw a tree"', $capturedOptions['body']);
        self::assertStringContainsString('"stream":true', $capturedOptions['body']);
        self::assertSame('token-image', file_get_contents($image->toMetadata()['path']));
        self::assertSame('revised', $image->toMetadata()['revised_prompt']);
    }

    private function createPlatform(array $capabilities, ResultInterface $result): PlatformInterface
    {
        return new class($capabilities, $result) implements PlatformInterface {
            public function __construct(
                private readonly array $capabilities,
                private readonly ResultInterface $result,
            ) {
            }

            public function invoke(string $model, array|string|object $input, array $options = []): DeferredResult
            {
                return new DeferredResult(
                    new class($this->result) implements ResultConverterInterface {
                        public function __construct(
                            private readonly ResultInterface $result,
                        ) {
                        }

                        public function supports(Model $model): bool
                        {
                            return true;
                        }

                        public function convert(RawResultInterface $result, array $options = []): ResultInterface
                        {
                            return $this->result;
                        }

                        public function getTokenUsageExtractor(): null
                        {
                            return null;
                        }
                    },
                    new class implements RawResultInterface {
                        public function getData(): array
                        {
                            return [];
                        }

                        public function getDataStream(): iterable
                        {
                            return [];
                        }

                        public function getObject(): object
                        {
                            return (object) [];
                        }
                    },
                );
            }

            public function getModelCatalog(): ModelCatalogInterface
            {
                return new class($this->capabilities) implements ModelCatalogInterface {
                    public function __construct(
                        private readonly array $capabilities,
                    ) {
                    }

                    public function getModel(string $modelName): Model
                    {
                        return new Model($modelName, $this->capabilities);
                    }

                    public function getModels(): array
                    {
                        return [];
                    }
                };
            }
        };
    }
}
