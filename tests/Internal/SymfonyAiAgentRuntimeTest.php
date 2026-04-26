<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tests\Internal;

use Armin\AiAgent\Auth\AgentAuth;
use Armin\AiAgent\AiAgentConfig;
use Armin\AiAgent\Exception\InvalidSession;
use Armin\AiAgent\Exception\ModelDoesNotSupportImageInput;
use Armin\AiAgent\Internal\SymfonyAiAgentRuntime;
use Armin\AiAgent\Tool\Builtin\ViewImageTool;
use Armin\AiAgent\Tool\ToolInterface;
use Armin\AiAgent\Tool\ToolRegistry;
use Armin\AiAgent\Tool\ToolResult;
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
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;

final class SymfonyAiAgentRuntimeTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . '/ai-agent-runtime-' . bin2hex(random_bytes(4));
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

    public function testStructuredRequestAddsResponseFormatAndDisablesStreaming(): void
    {
        $holder = (object) ['inputs' => [], 'options' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE, Capability::OUTPUT_STRUCTURED],
            $holder,
        );

        $response = $runtime->request('Return JSON.', RuntimeStructuredResponse::class);

        self::assertSame('ok', $response->content());
        self::assertFalse($holder->options[0]['stream']);
        self::assertSame('json_schema', $holder->options[0]['response_format']['type']);
        self::assertSame('RuntimeStructuredResponse', $holder->options[0]['response_format']['json_schema']['name']);
        self::assertTrue($holder->options[0]['response_format']['json_schema']['strict']);
        self::assertArrayHasKey('message', $holder->options[0]['response_format']['json_schema']['schema']['properties']);
    }

    public function testOpenAiRequestIncludesFunctionToolsAndHostedToolsByDefault(): void
    {
        $holder = (object) ['inputs' => [], 'options' => []];
        $registry = new ToolRegistry();
        $registry->register($this->createTool('custom_tool', fn (array $input): ToolResult => ToolResult::success($input)));
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            toolRegistry: $registry,
        );

        $response = $runtime->request('Inspect this.');

        self::assertCount(3, $holder->options[0]['tools']);
        self::assertSame('custom_tool', $holder->options[0]['tools'][0]->getName());
        self::assertSame(['type' => 'web_search'], $holder->options[0]['tools'][1]);
        self::assertSame(['type' => 'image_generation'], $holder->options[0]['tools'][2]);
        self::assertSame(['requested' => ['web_search', 'image_generation'], 'enabled' => ['web_search', 'image_generation'], 'skipped' => []], $response->metadata()['hosted_tools']);
        self::assertStringContainsString('Use hosted web search', $response->metadata()['system_prompt']);
        self::assertStringContainsString('Use hosted image generation', $response->metadata()['system_prompt']);
    }

    public function testOpenAiRequestOmitsHostedToolsWhenDisabled(): void
    {
        $holder = (object) ['inputs' => [], 'options' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            configOverrides: [
                'enableBuiltinWebSearch' => false,
                'enableBuiltinImageGeneration' => false,
            ],
        );

        $response = $runtime->request('Inspect this.');

        self::assertArrayNotHasKey('tools', $holder->options[0]);
        self::assertSame(['requested' => [], 'enabled' => [], 'skipped' => []], $response->metadata()['hosted_tools']);
        self::assertStringNotContainsString('Hosted provider tools:', $response->metadata()['system_prompt']);
    }

    public function testAnthropicRequestIncludesHostedWebSearchOnlyAndPreservesFunctionTools(): void
    {
        $holder = (object) ['inputs' => [], 'options' => []];
        $registry = new ToolRegistry();
        $registry->register($this->createTool('custom_tool', fn (array $input): ToolResult => ToolResult::success($input)));
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            toolRegistry: $registry,
            model: 'anthropic:claude-sonnet-4-5',
        );

        $response = $runtime->request('Inspect this.');

        self::assertCount(2, $holder->options[0]['tools']);
        self::assertSame('custom_tool', $holder->options[0]['tools'][0]->getName());
        self::assertSame(['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5], $holder->options[0]['tools'][1]);
        self::assertSame([
            'requested' => ['web_search', 'image_generation'],
            'enabled' => ['web_search'],
            'skipped' => [['tool' => 'image_generation', 'reason' => 'unsupported_provider']],
        ], $response->metadata()['hosted_tools']);
        self::assertStringContainsString('Use hosted web search', $response->metadata()['system_prompt']);
        self::assertStringNotContainsString('Use hosted image generation', $response->metadata()['system_prompt']);
    }

    public function testGeminiRequestUsesServerToolForWebSearchAndKeepsFunctionToolsSeparate(): void
    {
        $holder = (object) ['inputs' => [], 'options' => []];
        $registry = new ToolRegistry();
        $registry->register($this->createTool('custom_tool', fn (array $input): ToolResult => ToolResult::success($input)));
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            toolRegistry: $registry,
            model: 'gemini:gemini-2.5-pro',
        );

        $response = $runtime->request('Inspect this.');

        self::assertCount(1, $holder->options[0]['tools']);
        self::assertSame('custom_tool', $holder->options[0]['tools'][0]->getName());
        self::assertTrue($holder->options[0]['server_tools']['google_search']);
        self::assertSame([
            'requested' => ['web_search', 'image_generation'],
            'enabled' => ['web_search'],
            'skipped' => [['tool' => 'image_generation', 'reason' => 'unsupported_provider']],
        ], $response->metadata()['hosted_tools']);
        self::assertStringContainsString('Use hosted web search', $response->metadata()['system_prompt']);
        self::assertStringNotContainsString('Use hosted image generation', $response->metadata()['system_prompt']);
    }

    public function testOpenAiHostedImageGenerationIsPersistedAndProducesFallbackContent(): void
    {
        $path = $this->tempDirectory . '/image.jpg';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK9sAAAAASUVORK5CYII=', true));

        $holder = (object) ['inputs' => [], 'options' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            results: [
                new TextResult(''),
            ],
            metadata: [
                'provider' => 'test',
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
                    'partial_image_b64' => base64_encode('generated-binary'),
                    'output_format' => 'jpeg',
                ]],
            ],
        );

        $response = $runtime->request("Erstelle ein neues Bild 'cat.jpg' im selben Ordner und nutze image.jpg als Vorlage.");

        self::assertSame('Generated image saved to cat.jpg.', $response->content());
        self::assertCount(1, $response->generatedImages());
        self::assertSame($this->tempDirectory . '/cat.jpg', $response->generatedImages()[0]['path']);
        self::assertSame('generated-binary', file_get_contents($this->tempDirectory . '/cat.jpg'));
    }

    public function testStructuredRequestStillReturnsJsonTextInAiAgentResponse(): void
    {
        $holder = (object) ['inputs' => [], 'options' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE, Capability::OUTPUT_STRUCTURED],
            $holder,
            results: [
                new TextResult('{"message":"hello","count":2}'),
            ],
        );

        $response = $runtime->request('Return JSON.', RuntimeStructuredResponse::class);

        self::assertSame('{"message":"hello","count":2}', $response->content());
    }

    public function testRequestStructuredReturnsHydratedDtoObject(): void
    {
        $holder = (object) ['inputs' => [], 'options' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE, Capability::OUTPUT_STRUCTURED],
            $holder,
            results: [
                new TextResult('{"message":"hello","count":2}'),
            ],
        );

        $response = $runtime->requestStructured('Return JSON.', RuntimeStructuredResponse::class);

        self::assertInstanceOf(RuntimeStructuredResponse::class, $response);
        self::assertSame('hello', $response->message);
        self::assertSame(2, $response->count);
    }

    public function testStructuredRequestRejectsMissingResponseClass(): void
    {
        $holder = (object) ['inputs' => [], 'options' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE, Capability::OUTPUT_STRUCTURED],
            $holder,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $runtime->request('Return JSON.', 'Missing\\Dto');
    }

    public function testStructuredRequestThrowsWhenModelDoesNotSupportIt(): void
    {
        $holder = (object) ['inputs' => [], 'options' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not support structured output');

        $runtime->request('Return JSON.', RuntimeStructuredResponse::class);
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
        self::assertArrayNotHasKey('metadata', $payload['messages'][1]);
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
        self::assertArrayNotHasKey('metadata', $payload['messages'][0]);
        self::assertArrayNotHasKey('metadata', $payload['messages'][1]);
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
        $toolRegistry = $this->createRegistryWithOptionalImageTools($this->tempDirectory, withViewImage: true);
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
        self::assertSame('Bitte analysiere das Bild.', $followUpMessages[4]->asText());
        self::assertTrue($followUpMessages[4]->hasImageContent());
        self::assertCount(2, $followUpMessages[4]->getContent());
        self::assertCount(1, $response->metadata()['attached_images']);
        self::assertSame($path, $response->metadata()['attached_images'][0]['path']);
    }

    public function testStructuredFinalOutputStillWorksWithToolLoopAndSessionReplay(): void
    {
        $sessionFile = $this->tempDirectory . '/session.json';
        file_put_contents($sessionFile, json_encode([
            'version' => 1,
            'messages' => [
                ['role' => 'user', 'content' => 'Earlier prompt'],
                ['role' => 'assistant', 'content' => 'Earlier answer'],
            ],
        ], JSON_THROW_ON_ERROR));

        $holder = (object) ['inputs' => [], 'options' => []];
        $toolRegistry = new ToolRegistry();
        $toolRegistry->register($this->createTool('find_files', static fn (): ToolResult => ToolResult::success([
            'path' => '/tmp',
            'filter' => '*.json',
            'count' => 1,
            'files' => ['/tmp/result.json'],
        ])));

        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE, Capability::OUTPUT_STRUCTURED],
            $holder,
            sessionFile: $sessionFile,
            results: [
                new ToolCallResult([new ToolCall('call-1', 'find_files', ['path' => '/tmp', 'filter' => '*.json'])]),
                new TextResult('{"message":"done","count":1}'),
            ],
            toolRegistry: $toolRegistry,
        );

        $response = $runtime->requestStructured('Return JSON.', RuntimeStructuredResponse::class);

        self::assertSame('done', $response->message);
        self::assertCount(2, $holder->inputs);
        self::assertFalse($holder->options[0]['stream']);
        self::assertFalse($holder->options[1]['stream']);
        self::assertSame(6, $holder->inputs[1]->count());
    }

    public function testMultipleViewImageToolCallsReuseOriginalPromptForFollowUpMessage(): void
    {
        $firstPath = $this->tempDirectory . '/first.png';
        $secondPath = $this->tempDirectory . '/second.png';
        $pixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK9sAAAAASUVORK5CYII=', true);
        file_put_contents($firstPath, $pixel);
        file_put_contents($secondPath, $pixel);

        $holder = (object) ['inputs' => []];
        $toolRegistry = $this->createRegistryWithOptionalImageTools($this->tempDirectory, withViewImage: true);
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            results: [
                new ToolCallResult([
                    new ToolCall('call-1', 'view_image', ['path' => 'first.png']),
                    new ToolCall('call-2', 'view_image', ['path' => 'second.png']),
                ]),
                new TextResult('final'),
            ],
            toolRegistry: $toolRegistry,
        );

        $response = $runtime->request('Bitte analysiere beide Bilder.');

        self::assertSame('final', $response->content());
        self::assertCount(2, $holder->inputs);
        $followUpMessages = $holder->inputs[1]->getMessages();
        self::assertCount(6, $followUpMessages);
        self::assertTrue($followUpMessages[2]->hasToolCalls());
        self::assertInstanceOf(ToolCallMessage::class, $followUpMessages[3]);
        self::assertInstanceOf(ToolCallMessage::class, $followUpMessages[4]);
        self::assertTrue($followUpMessages[5]->hasImageContent());
        self::assertCount(3, $followUpMessages[5]->getContent());
        self::assertSame('Bitte analysiere beide Bilder.', $followUpMessages[5]->asText());
        self::assertCount(2, $response->metadata()['attached_images']);
        self::assertSame($firstPath, $response->metadata()['attached_images'][0]['path']);
        self::assertSame($secondPath, $response->metadata()['attached_images'][1]['path']);
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

    public function testSessionPersistsOnlyTokenRelevantAssistantMetadata(): void
    {
        $sessionFile = $this->tempDirectory . '/session.json';
        $holder = (object) ['inputs' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            sessionFile: $sessionFile,
            metadata: [
                'provider' => 'test',
                'final_response' => [
                    'id' => 'resp_1',
                    'usage' => ['total_tokens' => 12],
                    'status' => 'completed',
                ],
                'stream_events' => [['type' => 'response.completed']],
            ],
            results: [
                new ToolCallResult([new ToolCall('call-1', 'search', ['path' => '.'])]),
                new TextResult('done'),
            ],
            toolRegistry: ToolRegistry::withBuiltins($this->tempDirectory),
        );

        $runtime->request('Erzeuge ein Bild.');
        $payload = json_decode((string) file_get_contents($sessionFile), true, 512, JSON_THROW_ON_ERROR);
        $metadata = $payload['messages'][3]['metadata'];

        self::assertArrayNotHasKey('provider', $metadata);
        self::assertArrayNotHasKey('stream_events', $metadata);
        self::assertArrayNotHasKey('attached_images', $metadata);
        self::assertArrayNotHasKey('session', $metadata);
        self::assertSame(['usage' => ['total_tokens' => 12]], $metadata['final_response']);
        self::assertSame([
            'metadata' => [
                'final_response' => ['usage' => ['total_tokens' => 12]],
            ],
        ], $metadata['request_assistant_messages'][0]);
    }

    public function testSessionPersistsFinalResponseOutputForHistoryDebugging(): void
    {
        $sessionFile = $this->tempDirectory . '/session.json';
        $holder = (object) ['inputs' => []];
        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            sessionFile: $sessionFile,
            metadata: [
                'provider' => 'test',
                'final_response' => [
                    'usage' => ['total_tokens' => 12],
                    'output' => [
                        [
                            'type' => 'message',
                            'content' => [
                                [
                                    'type' => 'output_text',
                                    'text' => 'Persisted final answer',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            results: [
                new TextResult(''),
            ],
        );

        $runtime->request('Persist output');
        $payload = json_decode((string) file_get_contents($sessionFile), true, 512, JSON_THROW_ON_ERROR);
        $metadata = $payload['messages'][1]['metadata'];

        self::assertSame([
            'usage' => ['total_tokens' => 12],
            'output' => [
                [
                    'type' => 'message',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => 'Persisted final answer',
                        ],
                    ],
                ],
            ],
        ], $metadata['final_response']);
    }

    public function testSessionDoesNotPersistToolOutputsOrBinaryAttachments(): void
    {
        $path = $this->tempDirectory . '/image.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK9sAAAAASUVORK5CYII=', true));
        $sessionFile = $this->tempDirectory . '/session.json';
        $holder = (object) ['inputs' => []];
        $toolRegistry = $this->createRegistryWithOptionalImageTools($this->tempDirectory, withViewImage: true);

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

    public function testSessionIsPersistedIncrementallyBeforeLaterException(): void
    {
        $path = $this->tempDirectory . '/image.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK9sAAAAASUVORK5CYII=', true));
        $sessionFile = $this->tempDirectory . '/session.json';
        $holder = (object) ['inputs' => []];
        $toolRegistry = new ToolRegistry();
        $toolRegistry->register($this->createTool('view_image', fn (array $input): ToolResult => ToolResult::success([
            'path' => $input['path'],
        ])));

        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT],
            $holder,
            sessionFile: $sessionFile,
            results: [
                new ToolCallResult([new ToolCall('call-1', 'view_image', ['path' => 'image.png'])]),
            ],
            toolRegistry: $toolRegistry,
        );

        $this->expectException(ModelDoesNotSupportImageInput::class);
        $this->expectExceptionMessage('does not support image input');

        try {
            $runtime->request('Analysiere das Bild.');
        } finally {
            self::assertFileExists($sessionFile);
            $payload = json_decode((string) file_get_contents($sessionFile), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(1, $payload['version']);
            self::assertCount(3, $payload['messages']);
            self::assertSame(['role' => 'user', 'content' => 'Analysiere das Bild.'], $payload['messages'][0]);
            self::assertSame('assistant', $payload['messages'][1]['role']);
            self::assertSame('call-1', $payload['messages'][1]['tool_calls'][0]['id']);
            self::assertSame('tool', $payload['messages'][2]['role']);
            self::assertSame('call-1', $payload['messages'][2]['tool_call_id']);
        }
    }

    public function testMaxToolIterationsThrowsForInfiniteToolLoop(): void
    {
        $holder = (object) ['inputs' => []];
        $results = array_fill(0, 101, new ToolCallResult([new ToolCall('call-1', 'find_files', ['path' => '/tmp'])]));
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

    public function testMaxToolIterationsAllowsFinalResponseAfterHundredthToolRound(): void
    {
        $holder = (object) ['inputs' => []];
        $results = array_fill(0, 100, new ToolCallResult([new ToolCall('call-1', 'find_files', ['path' => '/tmp'])]));
        $results[] = new TextResult('final');
        $toolRegistry = new ToolRegistry();
        $toolRegistry->register($this->createTool('find_files', static fn (): ToolResult => ToolResult::success(['files' => []])));

        $runtime = $this->createRuntime(
            [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::INPUT_IMAGE],
            $holder,
            results: $results,
            toolRegistry: $toolRegistry,
        );

        $response = $runtime->request('Loop.');

        self::assertSame('final', $response->content());
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
        string $model = 'openai:gpt-5.4-mini',
        array $configOverrides = [],
    ): SymfonyAiAgentRuntime {
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

        return new SymfonyAiAgentRuntime(
            new AiAgentConfig(
                model: $model,
                auth: new AgentAuth(authMode: AgentAuth::MODE_API_KEY, apiKey: 'test-key'),
                sessionFile: $sessionFile,
                workingDirectory: $workingDirectory === false ? $this->tempDirectory : $workingDirectory,
                enableBuiltinWebSearch: $configOverrides['enableBuiltinWebSearch'] ?? true,
                enableBuiltinImageGeneration: $configOverrides['enableBuiltinImageGeneration'] ?? true,
            ),
            $toolRegistry ?? new ToolRegistry(),
            platform: $platform,
        );
    }

    private function createRegistryWithOptionalImageTools(
        ?string $workingDirectory,
        bool $withViewImage = false,
    ): ToolRegistry {
        $registry = ToolRegistry::withBuiltins($workingDirectory);

        if ($withViewImage) {
            $registry->register(new ViewImageTool($workingDirectory));
        }

        return $registry;
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

final readonly class RuntimeStructuredResponse
{
    public function __construct(
        public string $message = '',
        public int $count = 0,
    ) {
    }
}
