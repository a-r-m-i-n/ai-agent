<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Internal;

use Armin\CodexPhp\Auth\CodexAuth;
use Armin\CodexPhp\CodexConfig;
use Armin\CodexPhp\Exception\InvalidSession;
use Armin\CodexPhp\Exception\ModelDoesNotSupportImageInput;
use Armin\CodexPhp\Exception\ModelDoesNotSupportImageOutput;
use Armin\CodexPhp\Internal\SymfonyAiCodexRuntime;
use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolRegistry;
use Armin\CodexPhp\Tool\ToolResult;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
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

        $holder = (object) ['inputs' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
        );

        $response = $runtime->request('Beschreibe image.png');

        self::assertInstanceOf(MessageBag::class, $holder->inputs[0]);
        $messages = $holder->inputs[0]->getMessages();
        self::assertCount(2, $messages);
        $userMessage = $holder->inputs[0]->getUserMessage();
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

        $holder = (object) ['inputs' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
        );

        $response = $runtime->request('Beschreibe image.png und image.png erneut.');

        $userMessage = $holder->inputs[0]->getUserMessage();
        self::assertNotNull($userMessage);
        self::assertCount(2, $userMessage->getContent());
        self::assertCount(1, $response->metadata()['attached_images']);
    }

    public function testPromptWithoutImageReferenceStaysTextOnly(): void
    {
        $holder = (object) ['inputs' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
        );

        $response = $runtime->request('Nur Text ohne Bild.');

        $userMessage = $holder->inputs[0]->getUserMessage();
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

        $holder = (object) ['inputs' => []];
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

        $holder = (object) ['inputs' => []];
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
                        ['id' => 'call_123', 'name' => 'read_file', 'arguments' => ['path' => 'composer.json']],
                    ],
                    'metadata' => ['provider' => 'openai'],
                ],
                [
                    'role' => 'tool',
                    'content' => '{"success":true,"payload":{"path":"composer.json"}}',
                    'tool_call_id' => 'call_123',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $holder = (object) ['inputs' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            sessionFile: $sessionFile,
        );

        $response = $runtime->request('Current prompt');

        self::assertInstanceOf(MessageBag::class, $holder->inputs[0]);
        $messages = $holder->inputs[0]->getMessages();
        self::assertCount(5, $messages);
        self::assertSame('Earlier user question', $messages[1]->asText());
        self::assertSame('Earlier assistant answer', $messages[2]->getContent());
        self::assertTrue($messages[2]->hasToolCalls());
        self::assertInstanceOf(ToolCallMessage::class, $messages[3]);
        self::assertSame('Current prompt', $messages[4]->asText());
        self::assertSame($sessionFile, $response->metadata()['session']['file']);
        self::assertSame(3, $response->metadata()['session']['loaded_messages']);
        self::assertSame(3, $response->metadata()['session']['replayed_messages']);
        self::assertSame(5, $response->metadata()['session']['stored_messages']);
    }

    public function testSuccessfulRequestCreatesOrUpdatesSessionFile(): void
    {
        $sessionFile = $this->tempDirectory . '/session.json';
        $holder = (object) ['inputs' => []];
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

    public function testSessionMetadataIsArchivedButNotUsedForReplay(): void
    {
        $sessionFile = $this->tempDirectory . '/session.json';
        file_put_contents($sessionFile, json_encode([
            'version' => 1,
            'messages' => [
                [
                    'role' => 'assistant',
                    'content' => 'Earlier assistant answer',
                    'tool_calls' => [
                        ['name' => 'read_file', 'arguments' => ['path' => 'composer.json']],
                    ],
                    'metadata' => [
                        'provider' => 'openai',
                        'final_response' => ['id' => 'resp_older'],
                        'stream_events' => [['type' => 'response.completed']],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $holder = (object) ['inputs' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            sessionFile: $sessionFile,
        );

        $runtime->request('Current prompt');

        self::assertInstanceOf(MessageBag::class, $holder->inputs[0]);
        $messages = $holder->inputs[0]->getMessages();
        self::assertCount(3, $messages);
        self::assertSame('Earlier assistant answer', $messages[1]->getContent());
        self::assertTrue($messages[1]->hasToolCalls());
        self::assertSame('Current prompt', $messages[2]->asText());

        $payload = json_decode((string) file_get_contents($sessionFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('resp_older', $payload['messages'][0]['metadata']['final_response']['id']);
        self::assertSame('response.completed', $payload['messages'][0]['metadata']['stream_events'][0]['type']);
    }

    public function testLegacySessionWithoutToolOutputsRemainsReplayable(): void
    {
        $sessionFile = $this->tempDirectory . '/session.json';
        file_put_contents($sessionFile, json_encode([
            'version' => 1,
            'messages' => [
                [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [
                        ['name' => 'find_files', 'arguments' => ['path' => '/tmp']],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $holder = (object) ['inputs' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            sessionFile: $sessionFile,
        );

        $runtime->request('Current prompt');

        $messages = $holder->inputs[0]->getMessages();
        self::assertCount(3, $messages);
        self::assertTrue($messages[1]->hasToolCalls());
        self::assertSame('Current prompt', $messages[2]->asText());
    }

    public function testViewImageToolCallAddsImageAttachmentToNextModelCall(): void
    {
        $path = $this->tempDirectory . '/image.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK9sAAAAASUVORK5CYII=', true));

        $holder = (object) ['inputs' => []];
        $toolRegistry = ToolRegistry::withBuiltins($this->tempDirectory);
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            results: [
                new ToolCallResult([new ToolCall('call-1', 'view_image', ['path' => 'image.png'])]),
                new TextResult('final'),
            ],
            toolRegistry: $toolRegistry,
        );

        $response = $runtime->request('Bitte analysiere das Bild.');

        self::assertSame('final', $response->content());
        self::assertCount(2, $holder->inputs);
        $followUpMessages = $holder->inputs[1]->getMessages();
        self::assertCount(5, $followUpMessages);
        self::assertTrue($followUpMessages[2]->hasToolCalls());
        self::assertInstanceOf(ToolCallMessage::class, $followUpMessages[3]);
        self::assertStringNotContainsString('base64', $followUpMessages[3]->getContent());
        self::assertStringNotContainsString('data_url', $followUpMessages[3]->getContent());
        self::assertSame('Analyze the attached image input from the previous view_image tool result.', $followUpMessages[4]->asText());
        self::assertTrue($followUpMessages[4]->hasImageContent());
        self::assertCount(2, $followUpMessages[4]->getContent());
        self::assertCount(1, $response->metadata()['attached_images']);
        self::assertSame($path, $response->metadata()['attached_images'][0]['path']);
    }

    public function testNormalToolCallStaysTextOnlyWithoutImageAttachment(): void
    {
        $holder = (object) ['inputs' => []];
        $toolRegistry = new ToolRegistry();
        $toolRegistry->register($this->createTool('find_files', static fn (): ToolResult => ToolResult::success([
            'path' => '/tmp',
            'filter' => '*.png',
            'count' => 1,
            'files' => ['/tmp/image.png'],
        ])));

        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            results: [
                new ToolCallResult([new ToolCall('call-1', 'find_files', ['path' => '/tmp', 'filter' => '*.png'])]),
                new TextResult('final'),
            ],
            toolRegistry: $toolRegistry,
        );

        $runtime->request('Suche Bilder.');

        self::assertCount(2, $holder->inputs);
        $followUpMessages = $holder->inputs[1]->getMessages();
        self::assertCount(4, $followUpMessages);
        self::assertTrue($followUpMessages[2]->hasToolCalls());
        self::assertInstanceOf(ToolCallMessage::class, $followUpMessages[3]);
        self::assertStringContainsString('image.png', $followUpMessages[3]->getContent());
    }

    public function testFindFilesThenViewImageThenFinalResponseUsesCorrectOrder(): void
    {
        $path = $this->tempDirectory . '/tree.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK9sAAAAASUVORK5CYII=', true));

        $holder = (object) ['inputs' => []];
        $toolRegistry = new ToolRegistry();
        $toolRegistry->register($this->createTool('find_files', fn (): ToolResult => ToolResult::success([
            'path' => $this->tempDirectory,
            'filter' => '*.png',
            'count' => 1,
            'files' => [$path],
        ])));
        $toolRegistry->register($this->createTool('view_image', fn (array $input): ToolResult => ToolResult::success([
            'path' => $input['path'],
            'mime_type' => 'image/png',
            'width' => 1,
            'height' => 1,
            'was_resized' => false,
            'final_width' => 1,
            'final_height' => 1,
        ])));

        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            results: [
                new ToolCallResult([new ToolCall('call-1', 'find_files', ['path' => $this->tempDirectory, 'filter' => '*.png'])]),
                new ToolCallResult([new ToolCall('call-2', 'view_image', ['path' => $path])]),
                new TextResult('final'),
            ],
            toolRegistry: $toolRegistry,
        );

        $runtime->request('Analysiere den Baum.');

        self::assertCount(3, $holder->inputs);
        self::assertCount(4, $holder->inputs[1]->getMessages());
        self::assertCount(7, $holder->inputs[2]->getMessages());
        self::assertTrue($holder->inputs[2]->getMessages()[4]->hasToolCalls());
        self::assertInstanceOf(ToolCallMessage::class, $holder->inputs[2]->getMessages()[5]);
        self::assertTrue($holder->inputs[2]->getMessages()[6]->hasImageContent());
    }

    public function testFailedViewImageDoesNotAddAttachmentMessage(): void
    {
        $holder = (object) ['inputs' => []];
        $toolRegistry = new ToolRegistry();
        $toolRegistry->register($this->createTool('view_image', static function (): ToolResult {
            throw new \RuntimeException('broken image');
        }));

        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            results: [
                new ToolCallResult([new ToolCall('call-1', 'view_image', ['path' => 'missing.png'])]),
                new TextResult('final'),
            ],
            toolRegistry: $toolRegistry,
        );

        $runtime->request('Analysiere das Bild.');

        self::assertCount(2, $holder->inputs);
        self::assertCount(4, $holder->inputs[1]->getMessages());
        self::assertInstanceOf(ToolCallMessage::class, $holder->inputs[1]->getMessages()[3]);
        self::assertStringContainsString('broken image', $holder->inputs[1]->getMessages()[3]->getContent());
    }

    public function testGenerateImageToolCallStoresImageAndReturnsMetadata(): void
    {
        $holder = (object) ['inputs' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE, Capability::OUTPUT_IMAGE],
            $holder,
            results: [
                new ToolCallResult([new ToolCall('call-1', 'generate_image', [
                    'prompt' => 'Draw a castle',
                    'filename' => 'castle',
                ])]),
                new BinaryResult('castle-binary', 'image/webp'),
                new TextResult('Saved to castle.webp'),
            ],
            toolRegistry: ToolRegistry::withBuiltins($this->tempDirectory),
        );

        $response = $runtime->request('Bitte erstelle ein Bild.');

        self::assertSame('Saved to castle.webp', $response->content());
        self::assertCount(1, $response->generatedImages());
        self::assertSame($this->tempDirectory . '/castle.webp', $response->generatedImages()[0]['path']);
        self::assertSame('castle.webp', $response->generatedImages()[0]['filename']);
        self::assertSame('image/webp', $response->generatedImages()[0]['mime_type']);
        self::assertSame('castle-binary', file_get_contents($this->tempDirectory . '/castle.webp'));
        self::assertCount(3, $holder->inputs);
        self::assertSame('Draw a castle', $holder->inputs[1]);
    }

    public function testGenerateImageUsesCurrentWorkingDirectoryWhenNoConfiguredWorkingDirectoryExists(): void
    {
        $workingDirectory = $this->tempDirectory . '/cwd';
        mkdir($workingDirectory, 0777, true);
        $holder = (object) ['inputs' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE, Capability::OUTPUT_IMAGE],
            $holder,
            null,
            results: [
                new ToolCallResult([new ToolCall('call-1', 'generate_image', ['prompt' => 'Draw a river'])]),
                new BinaryResult('river', 'image/png'),
                new TextResult('done'),
            ],
            toolRegistry: ToolRegistry::withBuiltins(null),
        );

        $previousWorkingDirectory = getcwd();
        chdir($workingDirectory);

        try {
            $response = $runtime->request('Erzeuge ein Bild.');
        } finally {
            if (is_string($previousWorkingDirectory) && $previousWorkingDirectory !== '') {
                chdir($previousWorkingDirectory);
            }
        }

        self::assertMatchesRegularExpression('#^' . preg_quote($workingDirectory, '#') . '/new_image_[a-f0-9]{12}\.png$#', $response->generatedImages()[0]['path']);
        self::assertFileExists($response->generatedImages()[0]['path']);
    }

    public function testGenerateImageWithoutModelCapabilityThrowsClearException(): void
    {
        $holder = (object) ['inputs' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            results: [
                new ToolCallResult([new ToolCall('call-1', 'generate_image', ['prompt' => 'Draw a cat'])]),
            ],
            toolRegistry: ToolRegistry::withBuiltins($this->tempDirectory),
        );

        $this->expectException(ModelDoesNotSupportImageOutput::class);

        $runtime->request('Erzeuge ein Bild.');
    }

    public function testSessionStoresGeneratedImageMetadataWithoutBinaryData(): void
    {
        $sessionFile = $this->tempDirectory . '/session.json';
        $holder = (object) ['inputs' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE, Capability::OUTPUT_IMAGE],
            $holder,
            sessionFile: $sessionFile,
            results: [
                new ToolCallResult([new ToolCall('call-1', 'generate_image', ['prompt' => 'Draw a tree'])]),
                new BinaryResult('tree-binary', 'image/png'),
                new TextResult('done'),
            ],
            toolRegistry: ToolRegistry::withBuiltins($this->tempDirectory),
        );

        $response = $runtime->request('Erzeuge ein Bild.');
        $payload = json_decode((string) file_get_contents($sessionFile), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(4, $payload['messages']);
        self::assertSame($response->generatedImages(), $payload['messages'][3]['metadata']['generated_images']);
        self::assertStringNotContainsString('tree-binary', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testSessionDoesNotPersistToolOutputsOrBinaryAttachments(): void
    {
        $path = $this->tempDirectory . '/image.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK9sAAAAASUVORK5CYII=', true));
        $sessionFile = $this->tempDirectory . '/session.json';
        $holder = (object) ['inputs' => []];
        $toolRegistry = ToolRegistry::withBuiltins($this->tempDirectory);

        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            sessionFile: $sessionFile,
            results: [
                new ToolCallResult([new ToolCall('call-1', 'view_image', ['path' => 'image.png'])]),
                new TextResult('final'),
            ],
            toolRegistry: $toolRegistry,
        );

        $runtime->request('Beschreibe image.png');

        $payload = json_decode((string) file_get_contents($sessionFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(4, $payload['messages']);
        self::assertSame('user', $payload['messages'][0]['role']);
        self::assertSame('assistant', $payload['messages'][1]['role']);
        self::assertSame('tool', $payload['messages'][2]['role']);
        self::assertSame('assistant', $payload['messages'][3]['role']);
        self::assertArrayHasKey('tool_calls', $payload['messages'][1]);
        self::assertSame('call-1', $payload['messages'][1]['tool_calls'][0]['id']);
        self::assertSame('call-1', $payload['messages'][2]['tool_call_id']);
        self::assertStringNotContainsString('base64', json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('data:image', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testMaxToolIterationsThrowsForInfiniteToolLoop(): void
    {
        $holder = (object) ['inputs' => []];
        $results = array_fill(0, 16, new ToolCallResult([new ToolCall('call-1', 'find_files', ['path' => '/tmp'])]));
        $toolRegistry = new ToolRegistry();
        $toolRegistry->register($this->createTool('find_files', static fn (): ToolResult => ToolResult::success(['files' => []])));

        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            results: $results,
            toolRegistry: $toolRegistry,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Maximum tool iterations exceeded');

        $runtime->request('Loop.');
    }

    public function testInvalidSessionFileThrowsClearException(): void
    {
        $sessionFile = $this->tempDirectory . '/broken.json';
        file_put_contents($sessionFile, '{invalid');

        $holder = (object) ['inputs' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            sessionFile: $sessionFile,
        );

        $this->expectException(InvalidSession::class);
        $this->expectExceptionMessage('must contain valid JSON');

        $runtime->request('Current prompt');
    }

    /**
     * @param list<string> $capabilities
     * @param list<ResultInterface> $results
     * @param array<string, mixed> $metadata
     */
    private function createRuntime(
        array $capabilities,
        object $holder,
        string|false|null $workingDirectory = false,
        ?string $sessionFile = null,
        array $metadata = ['provider' => 'test'],
        array $results = [],
        ?ToolRegistry $toolRegistry = null,
    ): SymfonyAiCodexRuntime {
        $platform = new class($capabilities, $holder, $metadata, $results) implements PlatformInterface {
            /**
             * @param list<string> $capabilities
             * @param list<ResultInterface> $results
             */
            public function __construct(
                private readonly array $capabilities,
                private readonly object $holder,
                private readonly array $metadata,
                private readonly array $results,
            ) {
            }

            public function invoke(string $model, array|string|object $input, array $options = []): DeferredResult
            {
                $index = \count($this->holder->inputs);
                $this->holder->inputs[] = $input instanceof MessageBag ? clone $input : $input;
                $this->holder->options[] = $options;

                return new DeferredResult(
                    new class($this->metadata, $this->results[$index] ?? new TextResult('ok')) implements ResultConverterInterface {
                        public function __construct(
                            private readonly array $metadata,
                            private readonly ResultInterface $result,
                        ) {
                        }

                        public function supports(Model $model): bool
                        {
                            return true;
                        }

                        public function convert(RawResultInterface $result, array $options = []): ResultInterface
                        {
                            foreach ($this->metadata as $key => $value) {
                                $this->result->getMetadata()->add($key, $value);
                            }

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
                    /**
                     * @param list<string> $capabilities
                     */
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

        $holder->options ??= [];

        return new SymfonyAiCodexRuntime(
            new CodexConfig(
                model: 'openai:gpt-5.4-mini',
                auth: new CodexAuth(authMode: CodexAuth::MODE_API_KEY, apiKey: 'test-key'),
                sessionFile: $sessionFile,
                workingDirectory: $workingDirectory === false ? $this->tempDirectory : $workingDirectory,
            ),
            $toolRegistry ?? new ToolRegistry(),
            platform: $platform,
        );
    }

    /**
     * @param callable(array<string, mixed>): ToolResult $executor
     */
    private function createTool(string $name, callable $executor): ToolInterface
    {
        return new class($name, $executor) implements ToolInterface {
            public function __construct(
                private readonly string $name,
                private readonly mixed $executor,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function execute(array $input): ToolResult
            {
                return ($this->executor)($input);
            }
        };
    }
}
