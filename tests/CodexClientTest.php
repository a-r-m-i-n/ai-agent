<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests;

use Armin\CodexPhp\Auth\CodexAuth;
use Armin\CodexPhp\Auth\CodexAuthTokens;
use Armin\CodexPhp\CodexClient;
use Armin\CodexPhp\CodexConfig;
use Armin\CodexPhp\CodexResponse;
use Armin\CodexPhp\Exception\InvalidToolInput;
use Armin\CodexPhp\Exception\ToolNotFound;
use Armin\CodexPhp\Internal\CodexRuntimeInterface;
use Armin\CodexPhp\Tool\SchemaAwareToolInterface;
use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolRegistry;
use Armin\CodexPhp\Tool\ToolResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CodexClientTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR);
        putenv(CodexConfig::MODEL_ENV_VAR);
        $this->tempDirectory = sys_get_temp_dir() . '/codex-php-tests-' . bin2hex(random_bytes(4));
        mkdir($this->tempDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR);
        putenv(CodexConfig::MODEL_ENV_VAR);
        $this->removeDirectory($this->tempDirectory);
    }

    public function testBuiltinsAreRegisteredByDefault(): void
    {
        $client = new CodexClient();

        self::assertTrue($client->hasTool('read_file'));
        self::assertTrue($client->hasTool('write_file'));
        self::assertTrue($client->hasTool('run_command'));
    }

    public function testApiKeyIsReadFromEnvironment(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR . '=test-key');

        $client = new CodexClient(new CodexConfig());

        self::assertSame('test-key', $client->apiKey());
    }

    public function testApiKeyCanBeResolvedFromAuthObject(): void
    {
        $client = new CodexClient(new CodexConfig(auth: new CodexAuth(
            authMode: 'tokens',
            apiKey: null,
            tokens: new CodexAuthTokens('id', 'access', 'refresh', 'account'),
        )));

        self::assertNull($client->apiKey());
        self::assertNotNull($client->auth());
    }

    public function testMutableApiKeyOverrideTakesPrecedenceOverAuthObject(): void
    {
        $config = new CodexConfig(auth: new CodexAuth(
            authMode: CodexAuth::MODE_API_KEY,
            apiKey: 'auth-key',
        ));

        $config->setApiKey('override-key');

        self::assertSame('override-key', $config->apiKey());
    }

    public function testConfigExposesWorkingDirectoryAndSystemPromptSettings(): void
    {
        $config = new CodexConfig(
            workingDirectory: $this->tempDirectory,
            systemPrompt: 'Answer tersely.',
            systemPromptMode: 'replace',
        );

        self::assertSame($this->tempDirectory, $config->workingDirectory());
        self::assertSame('Answer tersely.', $config->systemPrompt());
        self::assertSame('replace', $config->systemPromptMode());
    }

    public function testInvalidSystemPromptModeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CodexConfig(systemPromptMode: 'invalid');
    }

    public function testMissingApiKeyReturnsNull(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR);

        $client = new CodexClient(new CodexConfig());

        self::assertNull($client->apiKey());
    }

    public function testReadAndWriteFileToolsWork(): void
    {
        $client = new CodexClient();
        $path = $this->tempDirectory . '/example.txt';

        $write = $client->runTool('write_file', [
            'path' => $path,
            'contents' => 'hello',
        ]);

        self::assertTrue($write->isSuccess());
        self::assertSame(5, $write->payload()['bytes_written']);

        $read = $client->runTool('read_file', [
            'path' => $path,
        ]);

        self::assertTrue($read->isSuccess());
        self::assertSame('hello', $read->payload()['contents']);
    }

    public function testReadAndWriteFileToolsResolveRelativePathsAgainstConfiguredWorkingDirectory(): void
    {
        $client = new CodexClient(new CodexConfig(workingDirectory: $this->tempDirectory));

        $write = $client->runTool('write_file', [
            'path' => 'nested/example.txt',
            'contents' => 'hello',
        ]);

        self::assertTrue($write->isSuccess());
        self::assertSame($this->tempDirectory . '/nested/example.txt', $write->payload()['path']);

        $read = $client->runTool('read_file', [
            'path' => 'nested/example.txt',
        ]);

        self::assertTrue($read->isSuccess());
        self::assertSame('hello', $read->payload()['contents']);
        self::assertSame($this->tempDirectory . '/nested/example.txt', $read->payload()['path']);
    }

    public function testWriteFileAllowsEmptyContents(): void
    {
        $client = new CodexClient();
        $path = $this->tempDirectory . '/empty.txt';

        $result = $client->runTool('write_file', [
            'path' => $path,
            'contents' => '',
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(0, $result->payload()['bytes_written']);
    }

    public function testReadFileReturnsFailureForMissingFile(): void
    {
        $client = new CodexClient();

        $result = $client->runTool('read_file', [
            'path' => $this->tempDirectory . '/missing.txt',
        ]);

        self::assertFalse($result->isSuccess());
        self::assertSame('File not found.', $result->payload()['error']);
    }

    public function testRunCommandReturnsOutput(): void
    {
        $client = new CodexClient();

        $result = $client->runTool('run_command', [
            'command' => ['sh', '-c', 'printf "ok"'],
            'cwd' => $this->tempDirectory,
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(0, $result->payload()['exit_code']);
        self::assertSame('ok', $result->payload()['stdout']);
        self::assertSame('', $result->payload()['stderr']);
        self::assertFalse($result->payload()['timed_out']);
        self::assertNull($result->payload()['timeout']);
    }

    public function testRunCommandUsesConfiguredWorkingDirectoryByDefault(): void
    {
        $client = new CodexClient(new CodexConfig(workingDirectory: $this->tempDirectory));

        $result = $client->runTool('run_command', [
            'command' => ['pwd'],
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame($this->tempDirectory, trim((string) $result->payload()['stdout']));
        self::assertSame($this->tempDirectory, $result->payload()['cwd']);
    }

    public function testRunCommandSupportsConfigurableTimeout(): void
    {
        $client = new CodexClient();

        $result = $client->runTool('run_command', [
            'command' => ['sh', '-c', 'sleep 1'],
            'cwd' => $this->tempDirectory,
            'timeout' => 0.1,
        ]);

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->payload()['timed_out']);
        self::assertSame(0.1, $result->payload()['timeout']);
        self::assertSame('Process timed out.', $result->payload()['error']);
    }

    public function testRunCommandRejectsInvalidTimeout(): void
    {
        $client = new CodexClient();

        $this->expectException(InvalidToolInput::class);

        $client->runTool('run_command', [
            'command' => ['pwd'],
            'timeout' => 0,
        ]);
    }

    public function testCustomToolCanBeRegistered(): void
    {
        $client = new CodexClient(registerBuiltins: false);
        $client->registerTool(new class implements ToolInterface {
            public function name(): string
            {
                return 'custom';
            }

            public function execute(array $input): ToolResult
            {
                return ToolResult::success([
                    'value' => strtoupper((string) ($input['value'] ?? '')),
                ]);
            }
        });

        $result = $client->runTool('custom', ['value' => 'test']);

        self::assertTrue($result->isSuccess());
        self::assertSame('TEST', $result->payload()['value']);
    }

    public function testBuiltinToolCanBeUnregistered(): void
    {
        $client = new CodexClient();
        $client->unregisterTool('read_file');

        self::assertFalse($client->hasTool('read_file'));
    }

    public function testBuiltinToolCanBeReplaced(): void
    {
        $client = new CodexClient();
        $client
            ->unregisterTool('read_file')
            ->registerTool(new class implements ToolInterface {
                public function name(): string
                {
                    return 'read_file';
                }

                public function execute(array $input): ToolResult
                {
                    return ToolResult::success([
                        'path' => $input['path'] ?? null,
                        'contents' => 'custom',
                    ]);
                }
            });

        $result = $client->runTool('read_file', ['path' => 'example.txt']);

        self::assertTrue($result->isSuccess());
        self::assertSame('custom', $result->payload()['contents']);
    }

    public function testProvidedRegistryGetsBuiltinDefaultsThroughRegistryApi(): void
    {
        $registry = new ToolRegistry();
        $client = new CodexClient(new CodexConfig(workingDirectory: $this->tempDirectory), $registry);

        self::assertTrue($client->hasTool('read_file'));
        self::assertTrue($client->hasTool('write_file'));
        self::assertTrue($client->hasTool('run_command'));
    }

    public function testRequestReturnsStructuredResponse(): void
    {
        $runtime = new class implements CodexRuntimeInterface {
            public function request(string $prompt): CodexResponse
            {
                return new CodexResponse(
                    content: 'hello world',
                    model: 'openai:gpt-5',
                    toolCalls: [
                        ['name' => 'read_file', 'arguments' => ['path' => '/tmp/example.txt']],
                    ],
                    metadata: ['provider' => 'openai'],
                );
            }
        };

        $client = new CodexClient(runtime: $runtime);
        $response = $client->request('Say hello');

        self::assertSame('hello world', $response->content());
        self::assertSame('openai:gpt-5', $response->model());
        self::assertSame('read_file', $response->toolCalls()[0]['name']);
        self::assertSame('openai', $response->metadata()['provider']);
    }

    public function testRequestTextReturnsOnlyContent(): void
    {
        $runtime = new class implements CodexRuntimeInterface {
            public function request(string $prompt): CodexResponse
            {
                return new CodexResponse('plain answer', 'openai:gpt-5');
            }
        };

        $client = new CodexClient(runtime: $runtime);

        self::assertSame('plain answer', $client->requestText('Prompt'));
    }

    public function testRequestUsesMutableConfigOverrides(): void
    {
        $config = new CodexConfig();
        $config
            ->setModel('openai:gpt-5.1')
            ->setApiKey('secret');

        self::assertSame('openai:gpt-5.1', $config->model());
        self::assertSame('secret', $config->apiKey());
    }

    public function testOpenAiTokenModeExecutesToolCallsThroughAgentLoop(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'body' => $options['body'] ?? '',
            ];

            if (\count($requests) === 1) {
                return new MockResponse(<<<TEXT
event: response.created
data: {"type":"response.created"}

event: response.output_item.done
data: {"type":"response.output_item.done","item":{"id":"fc_123","type":"function_call","status":"completed","arguments":"{\"path\":\"composer.json\"}","call_id":"call_123","name":"custom_read"}}

event: response.completed
data: {"type":"response.completed","response":{"id":"resp_1","output":[],"usage":{"input_tokens":10,"output_tokens":2,"total_tokens":12}}}

TEXT, ['http_code' => 200]);
            }

            return new MockResponse(<<<TEXT
event: response.created
data: {"type":"response.created"}

event: response.output_text.delta
data: {"type":"response.output_text.delta","delta":"composer.json enthaelt den Namen armin/codex-php."}

event: response.completed
data: {"type":"response.completed","response":{"id":"resp_2","output":[],"usage":{"input_tokens":20,"output_tokens":8,"total_tokens":28}}}

TEXT, ['http_code' => 200]);
        });

        $client = new CodexClient(
            new CodexConfig(
                model: 'openai:gpt-5.4-mini',
                auth: new CodexAuth(
                    authMode: CodexAuth::MODE_TOKENS,
                    tokens: new CodexAuthTokens('id', 'access', 'refresh', 'account'),
                ),
            ),
            registerBuiltins: false,
            httpClient: $httpClient,
        );
        $client->registerTool(new class implements SchemaAwareToolInterface {
            public function name(): string
            {
                return 'custom_read';
            }

            public function parameters(): array
            {
                return [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'path' => ['type' => 'string'],
                    ],
                    'required' => ['path'],
                ];
            }

            public function execute(array $input): ToolResult
            {
                return ToolResult::success([
                    'path' => $input['path'] ?? null,
                    'contents' => '{"name":"armin/codex-php"}',
                ]);
            }
        });

        $response = $client->request('Was steht in der Datei composer.json?');

        self::assertCount(2, $requests);
        self::assertStringContainsString('"type":"function_call_output"', $requests[1]['body']);
        self::assertStringContainsString('"call_id":"call_123"', $requests[1]['body']);
        self::assertSame('composer.json enthaelt den Namen armin/codex-php.', $response->content());
    }

    public function testUnknownToolThrows(): void
    {
        $client = new CodexClient(registerBuiltins: false);

        $this->expectException(ToolNotFound::class);

        $client->runTool('missing');
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
