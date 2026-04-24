<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Internal;

use Armin\CodexPhp\Auth\CodexAuth;
use Armin\CodexPhp\CodexConfig;
use Armin\CodexPhp\Exception\InvalidSession;
use Armin\CodexPhp\Exception\ModelDoesNotSupportImageInput;
use Armin\CodexPhp\Internal\SymfonyAiCodexRuntime;
use Armin\CodexPhp\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;

final class SymfonyAiCodexRuntimeTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . '/codex-php-runtime-' . bin2hex(random_bytes(4));
        mkdir($this->tempDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDirectory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tempDirectory);
    }

    public function testPromptImageReferenceCreatesMultimodalUserMessageAndMetadata(): void
    {
        $path = $this->tempDirectory . '/image.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK9sAAAAASUVORK5CYII=', true));

        $holder = (object) ['input' => null];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
        );

        $response = $runtime->request('Beschreibe image.png');

        self::assertInstanceOf(MessageBag::class, $holder->input);
        $messages = $holder->input->getMessages();
        self::assertCount(2, $messages);
        $userMessage = $holder->input->getUserMessage();
        self::assertNotNull($userMessage);
        self::assertSame('Beschreibe image.png', $userMessage->asText());
        self::assertTrue($userMessage->hasImageContent());
        self::assertCount(2, $userMessage->getContent());
        self::assertInstanceOf(Image::class, $userMessage->getContent()[1]);
        self::assertArrayHasKey('attached_images', $response->metadata());
        self::assertCount(1, $response->metadata()['attached_images']);
        self::assertSame($path, $response->metadata()['attached_images'][0]['path']);
    }

    public function testMultipleImageReferencesAreDeduplicated(): void
    {
        $path = $this->tempDirectory . '/image.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK9sAAAAASUVORK5CYII=', true));

        $holder = (object) ['input' => null];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
        );

        $response = $runtime->request('Beschreibe image.png und image.png erneut.');

        $userMessage = $holder->input->getUserMessage();
        self::assertNotNull($userMessage);
        self::assertCount(2, $userMessage->getContent());
        self::assertCount(1, $response->metadata()['attached_images']);
    }

    public function testPromptWithoutImageReferenceStaysTextOnly(): void
    {
        $holder = (object) ['input' => null];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
        );

        $response = $runtime->request('Nur Text ohne Bild.');

        $userMessage = $holder->input->getUserMessage();
        self::assertNotNull($userMessage);
        self::assertCount(1, $userMessage->getContent());
        self::assertFalse($userMessage->hasImageContent());
        self::assertArrayNotHasKey('attached_images', $response->metadata());
    }

    public function testPromptImageReferenceUsesCurrentWorkingDirectoryWhenNoConfiguredWorkingDirectoryExists(): void
    {
        $workingDirectory = $this->tempDirectory . '/cwd';
        mkdir($workingDirectory, 0777, true);
        $path = $workingDirectory . '/image.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK9sAAAAASUVORK5CYII=', true));

        $holder = (object) ['input' => null];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            null,
        );

        $previousWorkingDirectory = getcwd();
        chdir($workingDirectory);

        try {
            $response = $runtime->request('Beschreibe image.png');
        } finally {
            if (is_string($previousWorkingDirectory) && $previousWorkingDirectory !== '') {
                chdir($previousWorkingDirectory);
            }
        }

        self::assertArrayHasKey('attached_images', $response->metadata());
        self::assertSame($path, $response->metadata()['attached_images'][0]['path']);
    }

    public function testModelWithoutImageSupportThrowsForDetectedImageReference(): void
    {
        $path = $this->tempDirectory . '/image.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK9sAAAAASUVORK5CYII=', true));

        $holder = (object) ['input' => null];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT],
            $holder,
        );

        $this->expectException(ModelDoesNotSupportImageInput::class);

        $runtime->request('Beschreibe image.png');
    }

    public function testExistingSessionMessagesAreLoadedBeforeCurrentPrompt(): void
    {
        $sessionFile = $this->tempDirectory . '/session.json';
        file_put_contents($sessionFile, json_encode([
            'version' => 1,
            'messages' => [
                ['role' => 'user', 'content' => 'Earlier user question'],
                [
                    'role' => 'assistant',
                    'content' => 'Earlier assistant answer',
                    'tool_calls' => [
                        ['name' => 'read_file', 'arguments' => ['path' => 'composer.json']],
                    ],
                    'metadata' => ['provider' => 'openai'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $holder = (object) ['input' => null];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            sessionFile: $sessionFile,
        );

        $response = $runtime->request('Current prompt');

        self::assertInstanceOf(MessageBag::class, $holder->input);
        $messages = $holder->input->getMessages();
        self::assertCount(4, $messages);
        self::assertSame('Earlier user question', $messages[1]->asText());
        self::assertSame('Earlier assistant answer', $messages[2]->getContent());
        self::assertTrue($messages[2]->hasToolCalls());
        self::assertSame('Current prompt', $messages[3]->asText());
        self::assertSame($sessionFile, $response->metadata()['session']['file']);
        self::assertSame(2, $response->metadata()['session']['loaded_messages']);
        self::assertSame(4, $response->metadata()['session']['stored_messages']);
    }

    public function testSuccessfulRequestCreatesOrUpdatesSessionFile(): void
    {
        $sessionFile = $this->tempDirectory . '/session.json';
        $holder = (object) ['input' => null];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            sessionFile: $sessionFile,
            metadata: ['provider' => 'test', 'final_response' => ['id' => 'resp_1']],
        );

        self::assertFileDoesNotExist($sessionFile);

        $runtime->request('Persist this prompt');

        self::assertFileExists($sessionFile);
        $payload = json_decode((string) file_get_contents($sessionFile), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $payload['version']);
        self::assertCount(2, $payload['messages']);
        self::assertSame(['role' => 'user', 'content' => 'Persist this prompt'], $payload['messages'][0]);
        self::assertSame('assistant', $payload['messages'][1]['role']);
        self::assertSame('ok', $payload['messages'][1]['content']);
        self::assertSame('test', $payload['messages'][1]['metadata']['provider']);
        self::assertSame('resp_1', $payload['messages'][1]['metadata']['final_response']['id']);
    }

    public function testInvalidSessionFileThrowsClearException(): void
    {
        $sessionFile = $this->tempDirectory . '/broken.json';
        file_put_contents($sessionFile, '{invalid');

        $holder = (object) ['input' => null];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            sessionFile: $sessionFile,
        );

        $this->expectException(InvalidSession::class);
        $this->expectExceptionMessage('must contain valid JSON');

        $runtime->request('Current prompt');
    }

    private function createRuntime(
        array $capabilities,
        object $holder,
        string|false|null $workingDirectory = false,
        ?string $sessionFile = null,
        array $metadata = ['provider' => 'test'],
    ): SymfonyAiCodexRuntime
    {
        $platform = new class($capabilities, $holder, $metadata) implements PlatformInterface {
            public function __construct(
                private readonly array $capabilities,
                private readonly object $holder,
                private readonly array $metadata,
            ) {
            }

            public function invoke(string $model, array|string|object $input, array $options = []): DeferredResult
            {
                $this->holder->input = $input;

                return new DeferredResult(
                    new class($this->metadata) implements ResultConverterInterface {
                        public function __construct(
                            private readonly array $metadata,
                        ) {
                        }

                        public function supports(Model $model): bool
                        {
                            return true;
                        }

                        public function convert(RawResultInterface $result, array $options = []): TextResult
                        {
                            $textResult = new TextResult('ok');
                            foreach ($this->metadata as $key => $value) {
                                $textResult->getMetadata()->add($key, $value);
                            }

                            return $textResult;
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

        return new SymfonyAiCodexRuntime(
            new CodexConfig(
                model: 'openai:gpt-5.4-mini',
                auth: new CodexAuth(authMode: CodexAuth::MODE_API_KEY, apiKey: 'test-key'),
                sessionFile: $sessionFile,
                workingDirectory: $workingDirectory === false ? $this->tempDirectory : $workingDirectory,
            ),
            new ToolRegistry(),
            platform: $platform,
        );
    }
}
