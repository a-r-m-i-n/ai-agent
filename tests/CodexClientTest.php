<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests;

use Armin\CodexPhp\Auth\CodexAuth;
use Armin\CodexPhp\Auth\CodexAuthTokens;
use Armin\CodexPhp\CodexClient;
use Armin\CodexPhp\CodexConfig;
use Armin\CodexPhp\CodexResponse;
use Armin\CodexPhp\CodexTokenUsage;
use Armin\CodexPhp\Exception\InvalidToolInput;
use Armin\CodexPhp\Exception\InvalidSession;
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
        self::assertTrue($client->hasTool('view_image'));
        self::assertTrue($client->hasTool('find_files'));
        self::assertTrue($client->hasTool('generate_image'));
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
            sessionFile: $this->tempDirectory . '/session.json',
            workingDirectory: $this->tempDirectory,
            systemPrompt: 'Answer tersely.',
            systemPromptMode: 'replace',
        );

        self::assertSame($this->tempDirectory . '/session.json', $config->sessionFile());
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

    public function testFindFilesListsFilesInDirectory(): void
    {
        $client = new CodexClient();
        $directory = $this->tempDirectory . '/files';
        mkdir($directory, 0777, true);
        file_put_contents($directory . '/a.php', '<?php');
        file_put_contents($directory . '/b.txt', 'text');

        $result = $client->runTool('find_files', [
            'path' => $directory,
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame($directory, $result->payload()['path']);
        self::assertNull($result->payload()['filter']);
        self::assertSame(2, $result->payload()['count']);
        self::assertSame([
            $directory . '/a.php',
            $directory . '/b.txt',
        ], $result->payload()['files']);
    }

    public function testFindFilesAppliesFilterAndWorkingDirectory(): void
    {
        $client = new CodexClient(new CodexConfig(workingDirectory: $this->tempDirectory));
        mkdir($this->tempDirectory . '/nested', 0777, true);
        file_put_contents($this->tempDirectory . '/nested/a.php', '<?php');
        file_put_contents($this->tempDirectory . '/nested/b.txt', 'text');

        $result = $client->runTool('find_files', [
            'path' => 'nested',
            'filter' => '*.php',
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame($this->tempDirectory . '/nested', $result->payload()['path']);
        self::assertSame('*.php', $result->payload()['filter']);
        self::assertSame(1, $result->payload()['count']);
        self::assertSame([
            $this->tempDirectory . '/nested/a.php',
        ], $result->payload()['files']);
    }

    public function testFindFilesReturnsFailureForMissingDirectory(): void
    {
        $client = new CodexClient();

        $result = $client->runTool('find_files', [
            'path' => $this->tempDirectory . '/missing',
        ]);

        self::assertFalse($result->isSuccess());
        self::assertSame('Directory not found.', $result->payload()['error']);
        self::assertSame([], $result->payload()['files']);
    }

    public function testViewImageReturnsCompactImageMetadata(): void
    {
        $client = new CodexClient();
        $path = $this->tempDirectory . '/pixel.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK9sAAAAASUVORK5CYII=', true));

        $result = $client->runTool('view_image', ['path' => $path]);

        self::assertTrue($result->isSuccess());
        self::assertSame($path, $result->payload()['path']);
        self::assertSame('image/png', $result->payload()['mime_type']);
        self::assertSame(1, $result->payload()['width']);
        self::assertSame(1, $result->payload()['height']);
        self::assertFalse($result->payload()['was_resized']);
        self::assertSame(1, $result->payload()['final_width']);
        self::assertSame(1, $result->payload()['final_height']);
        self::assertArrayNotHasKey('base64', $result->payload());
        self::assertArrayNotHasKey('data_url', $result->payload());
    }

    public function testViewImageResolvesRelativePathsAgainstConfiguredWorkingDirectory(): void
    {
        $client = new CodexClient(new CodexConfig(workingDirectory: $this->tempDirectory));
        $path = $this->tempDirectory . '/images/pixel.png';
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK9sAAAAASUVORK5CYII=', true));

        $result = $client->runTool('view_image', ['path' => 'images/pixel.png']);

        self::assertTrue($result->isSuccess());
        self::assertSame($path, $result->payload()['path']);
    }

    public function testViewImageResolvesRelativePathsAgainstCurrentWorkingDirectoryWhenNoConfigExists(): void
    {
        $client = new CodexClient();
        $workingDirectory = $this->tempDirectory . '/cwd';
        mkdir($workingDirectory, 0777, true);
        $path = $workingDirectory . '/image.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK9sAAAAASUVORK5CYII=', true));

        $previousWorkingDirectory = getcwd();
        chdir($workingDirectory);

        try {
            $result = $client->runTool('view_image', ['path' => 'image.png']);
        } finally {
            if (is_string($previousWorkingDirectory) && $previousWorkingDirectory !== '') {
                chdir($previousWorkingDirectory);
            }
        }

        self::assertTrue($result->isSuccess());
        self::assertSame($path, $result->payload()['path']);
    }

    public function testViewImageFailsForMissingFile(): void
    {
        $client = new CodexClient();

        $this->expectException(InvalidToolInput::class);
        $this->expectExceptionMessage('was not found');

        $client->runTool('view_image', ['path' => $this->tempDirectory . '/missing.png']);
    }

    public function testViewImageResizesLargeImages(): void
    {
        if (!class_exists(\Imagick::class) && !function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('No supported image extension available.');
        }

        $client = new CodexClient();
        $path = $this->tempDirectory . '/large.png';
        $this->createLargePng($path, 4096, 1024);

        $result = $client->runTool('view_image', ['path' => $path]);

        self::assertTrue($result->isSuccess());
        self::assertTrue($result->payload()['was_resized']);
        self::assertSame(2048, $result->payload()['final_width']);
        self::assertSame(512, $result->payload()['final_height']);
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
        self::assertTrue($client->hasTool('view_image'));
        self::assertTrue($client->hasTool('find_files'));
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

    public function testGetRequestTokensReturnsZeroUsageBeforeAnyRequest(): void
    {
        $client = new CodexClient(runtime: new class implements CodexRuntimeInterface {
            public function request(string $prompt): CodexResponse
            {
                return new CodexResponse($prompt, 'openai:gpt-5');
            }
        });

        self::assertSame((new CodexTokenUsage())->toArray(), $client->getRequestTokens()->toArray());
    }

    public function testGetRequestTokensReturnsUsageFromLastRequest(): void
    {
        $runtime = new class implements CodexRuntimeInterface {
            public function request(string $prompt): CodexResponse
            {
                return new CodexResponse(
                    content: 'hello world',
                    model: 'openai:gpt-5',
                    metadata: [
                        'final_response' => [
                            'usage' => [
                                'input_tokens' => 10,
                                'input_tokens_details' => ['cached_tokens' => 3],
                                'output_tokens' => 4,
                                'output_tokens_details' => ['reasoning_tokens' => 2],
                                'total_tokens' => 14,
                            ],
                        ],
                    ],
                );
            }
        };

        $client = new CodexClient(runtime: $runtime);
        $client->request('Say hello');

        self::assertSame([
            'input' => 10,
            'cached_input' => 3,
            'output' => 4,
            'reasoning' => 2,
            'total' => 14,
            'image_generation_input' => 0,
            'image_generation_output' => 0,
            'image_generation_total' => 0,
        ], $client->getRequestTokens()->toArray());
    }

    public function testGetRequestTokensAggregatesToolLoopAndGeneratedImageUsage(): void
    {
        $runtime = new class implements CodexRuntimeInterface {
            public function request(string $prompt): CodexResponse
            {
                return new CodexResponse(
                    content: 'final',
                    model: 'openai:gpt-5',
                    metadata: [
                        'final_response' => [
                            'usage' => [
                                'input_tokens' => 20,
                                'output_tokens' => 8,
                                'total_tokens' => 28,
                            ],
                        ],
                        'request_assistant_messages' => [
                            [
                                'metadata' => [
                                    'final_response' => [
                                        'usage' => [
                                            'input_tokens' => 10,
                                            'output_tokens' => 2,
                                            'total_tokens' => 12,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'generated_images' => [
                            [
                                'provider_response' => [
                                    'tool_usage' => [
                                        'image_gen' => [
                                            'input_tokens' => 100,
                                            'output_tokens' => 50,
                                            'total_tokens' => 150,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                );
            }
        };

        $client = new CodexClient(runtime: $runtime);
        $client->request('Generate something');

        self::assertSame([
            'input' => 30,
            'cached_input' => 0,
            'output' => 10,
            'reasoning' => 0,
            'total' => 40,
            'image_generation_input' => 100,
            'image_generation_output' => 50,
            'image_generation_total' => 150,
        ], $client->getRequestTokens()->toArray());
    }

    public function testGetRequestTokensTracksOnlyMostRecentRequest(): void
    {
        $runtime = new class implements CodexRuntimeInterface {
            private int $calls = 0;

            public function request(string $prompt): CodexResponse
            {
                ++$this->calls;

                return new CodexResponse(
                    content: $prompt,
                    model: 'openai:gpt-5',
                    metadata: [
                        'final_response' => [
                            'usage' => [
                                'input_tokens' => $this->calls,
                                'output_tokens' => $this->calls * 2,
                                'total_tokens' => $this->calls * 3,
                            ],
                        ],
                    ],
                );
            }
        };

        $client = new CodexClient(runtime: $runtime);
        $client->request('first');
        $client->request('second');

        self::assertSame([
            'input' => 2,
            'cached_input' => 0,
            'output' => 4,
            'reasoning' => 0,
            'total' => 6,
            'image_generation_input' => 0,
            'image_generation_output' => 0,
            'image_generation_total' => 0,
        ], $client->getRequestTokens()->toArray());
    }

    public function testGetSessionTokensReturnsZeroWhenSessionFileIsNotConfiguredOrMissing(): void
    {
        $withoutSession = new CodexClient();
        $missingSession = new CodexClient(new CodexConfig(sessionFile: $this->tempDirectory . '/missing-session.json'));

        self::assertSame((new CodexTokenUsage())->toArray(), $withoutSession->getSessionTokens()->toArray());
        self::assertSame((new CodexTokenUsage())->toArray(), $missingSession->getSessionTokens()->toArray());
    }

    public function testGetSessionTokensAggregatesAssistantMessagesFromSessionFile(): void
    {
        $sessionFile = $this->tempDirectory . '/session.json';
        file_put_contents($sessionFile, json_encode([
            'version' => 1,
            'messages' => [
                ['role' => 'user', 'content' => 'Prompt'],
                [
                    'role' => 'assistant',
                    'content' => 'step 1',
                    'tool_calls' => [
                        ['id' => 'call_1', 'name' => 'find_files', 'arguments' => ['path' => '/tmp']],
                    ],
                    'metadata' => [
                        'final_response' => [
                            'usage' => [
                                'input_tokens' => 5,
                                'output_tokens' => 1,
                                'total_tokens' => 6,
                            ],
                        ],
                    ],
                ],
                ['role' => 'tool', 'content' => '{}', 'tool_call_id' => 'call_1'],
                [
                    'role' => 'assistant',
                    'content' => 'step 2',
                    'metadata' => [
                        'final_response' => [
                            'usage' => [
                                'input_tokens' => 7,
                                'input_tokens_details' => ['cached_tokens' => 2],
                                'output_tokens' => 3,
                                'output_tokens_details' => ['reasoning_tokens' => 1],
                                'total_tokens' => 10,
                            ],
                        ],
                        'generated_images' => [
                            [
                                'provider_response' => [
                                    'tool_usage' => [
                                        'image_gen' => [
                                            'input_tokens' => 20,
                                            'output_tokens' => 40,
                                            'total_tokens' => 60,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $client = new CodexClient(new CodexConfig(sessionFile: $sessionFile));

        self::assertSame([
            'input' => 12,
            'cached_input' => 2,
            'output' => 4,
            'reasoning' => 1,
            'total' => 16,
            'image_generation_input' => 20,
            'image_generation_output' => 40,
            'image_generation_total' => 60,
        ], $client->getSessionTokens()->toArray());
    }

    public function testGetSessionTokensAggregatesNestedAssistantUsageFromSlimSessionFormat(): void
    {
        $sessionFile = $this->tempDirectory . '/session.json';
        file_put_contents($sessionFile, json_encode([
            'version' => 1,
            'messages' => [
                [
                    'role' => 'assistant',
                    'content' => 'final',
                    'metadata' => [
                        'final_response' => [
                            'usage' => [
                                'input_tokens' => 20,
                                'output_tokens' => 8,
                                'total_tokens' => 28,
                            ],
                        ],
                        'request_assistant_messages' => [
                            [
                                'metadata' => [
                                    'final_response' => [
                                        'usage' => [
                                            'input_tokens' => 10,
                                            'output_tokens' => 2,
                                            'total_tokens' => 12,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'generated_images' => [
                            [
                                'provider_response' => [
                                    'tool_usage' => [
                                        'image_gen' => [
                                            'input_tokens' => 100,
                                            'output_tokens' => 50,
                                            'total_tokens' => 150,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $client = new CodexClient(new CodexConfig(sessionFile: $sessionFile));

        self::assertSame([
            'input' => 30,
            'cached_input' => 0,
            'output' => 10,
            'reasoning' => 0,
            'total' => 40,
            'image_generation_input' => 100,
            'image_generation_output' => 50,
            'image_generation_total' => 150,
        ], $client->getSessionTokens()->toArray());
    }

    public function testGetSessionTokensReturnsZeroForLegacySessionWithoutUsageMetadata(): void
    {
        $sessionFile = $this->tempDirectory . '/session.json';
        file_put_contents($sessionFile, json_encode([
            'version' => 1,
            'messages' => [
                ['role' => 'assistant', 'content' => 'legacy', 'metadata' => ['provider' => 'openai']],
            ],
        ], JSON_THROW_ON_ERROR));

        $client = new CodexClient(new CodexConfig(sessionFile: $sessionFile));

        self::assertSame((new CodexTokenUsage())->toArray(), $client->getSessionTokens()->toArray());
    }

    public function testGetSessionTokensThrowsForInvalidSessionFile(): void
    {
        $sessionFile = $this->tempDirectory . '/broken.json';
        file_put_contents($sessionFile, '{invalid');

        $client = new CodexClient(new CodexConfig(sessionFile: $sessionFile));

        $this->expectException(InvalidSession::class);

        $client->getSessionTokens();
    }

    public function testRequestUsesMutableConfigOverrides(): void
    {
        $config = new CodexConfig();
        $config
            ->setModel('openai:gpt-5.1')
            ->setApiKey('secret')
            ->setSessionFile($this->tempDirectory . '/session.json');

        self::assertSame('openai:gpt-5.1', $config->model());
        self::assertSame('secret', $config->apiKey());
        self::assertSame($this->tempDirectory . '/session.json', $config->sessionFile());
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

    private function createLargePng(string $path, int $width, int $height): void
    {
        if (class_exists(\Imagick::class)) {
            $image = new \Imagick();
            $image->newImage($width, $height, new \ImagickPixel('red'));
            $image->setImageFormat('png');
            file_put_contents($path, $image->getImagesBlob());

            return;
        }

        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        $red = imagecolorallocate($image, 255, 0, 0);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $red);
        imagepng($image, $path);
        imagedestroy($image);
    }
}
