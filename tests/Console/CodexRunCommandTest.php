<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Console;

use Armin\CodexPhp\CodexClient;
use Armin\CodexPhp\CodexConfig;
use Armin\CodexPhp\CodexResponse;
use Armin\CodexPhp\Console\CodexRunCommand;
use Armin\CodexPhp\Exception\MissingApiKey;
use Armin\CodexPhp\Exception\MissingModel;
use Armin\CodexPhp\Internal\CodexRuntimeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class CodexRunCommandTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR);
        putenv(CodexConfig::MODEL_ENV_VAR);

        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        $this->temporaryFiles = [];
    }

    public function testCommandOutputsPlainTextUsingEnvironmentDefaults(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(CodexConfig::MODEL_ENV_VAR . '=openai:gpt-5');

        $tester = new CommandTester(new CodexRunCommand(client: $this->createClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
        ]);

        self::assertSame("Hello from Codex\n", $tester->getDisplay());
    }

    public function testOptionsOverrideEnvironmentValues(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR . '=env-key');
        putenv(CodexConfig::MODEL_ENV_VAR . '=openai:env-model');

        $tester = new CommandTester(new CodexRunCommand(client: $this->createClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
            '--model' => 'anthropic:claude-3-5-haiku-20241022',
            '--key' => 'override-key',
        ]);

        self::assertSame("Hello from Codex\n", $tester->getDisplay());
    }

    public function testExistingPromptFileIsLoadedAsPromptContent(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(CodexConfig::MODEL_ENV_VAR . '=openai:gpt-5');
        $promptFile = sys_get_temp_dir() . '/codex-prompt-' . bin2hex(random_bytes(4)) . '.txt';
        $this->temporaryFiles[] = $promptFile;
        file_put_contents($promptFile, 'Prompt from file');

        $tester = new CommandTester(new CodexRunCommand(client: $this->createEchoClientStub()));
        $tester->execute([
            'prompt' => $promptFile,
        ]);

        self::assertSame("Prompt from file\n", $tester->getDisplay());
    }

    public function testMissingPromptFilePathRemainsLiteralPrompt(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(CodexConfig::MODEL_ENV_VAR . '=openai:gpt-5');
        $missingPath = sys_get_temp_dir() . '/codex-missing-' . bin2hex(random_bytes(4)) . '.txt';

        $tester = new CommandTester(new CodexRunCommand(client: $this->createEchoClientStub()));
        $tester->execute([
            'prompt' => $missingPath,
        ]);

        self::assertSame($missingPath . "\n", $tester->getDisplay());
    }

    public function testPromptContainingAdditionalTextIsNotResolvedAsFile(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(CodexConfig::MODEL_ENV_VAR . '=openai:gpt-5');
        $promptFile = sys_get_temp_dir() . '/codex-prompt-' . bin2hex(random_bytes(4)) . '.txt';
        $this->temporaryFiles[] = $promptFile;
        file_put_contents($promptFile, 'Prompt from file');
        $prompt = $promptFile . ' please summarize';

        $tester = new CommandTester(new CodexRunCommand(client: $this->createEchoClientStub()));
        $tester->execute([
            'prompt' => $prompt,
        ]);

        self::assertSame($prompt . "\n", $tester->getDisplay());
    }

    public function testVerboseExecutionPrintsSystemPromptUserPromptOutputAndStatistics(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(CodexConfig::MODEL_ENV_VAR . '=openai:gpt-5');
        $sessionFile = sys_get_temp_dir() . '/codex-session-' . bin2hex(random_bytes(4)) . '.json';
        $this->temporaryFiles[] = $sessionFile;
        $config = new CodexConfig(sessionFile: $sessionFile);
        file_put_contents($sessionFile, json_encode([
            'version' => 1,
            'messages' => [
                [
                    'role' => 'assistant',
                    'content' => 'prior response',
                    'metadata' => [
                        'final_response' => [
                            'usage' => [
                                'input_tokens' => 1234,
                                'output_tokens' => 1,
                                'total_tokens' => 1235,
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $tester = new CommandTester(new CodexRunCommand(config: $config, client: $this->createClientStub($config)));
        $tester->execute([
            'prompt' => 'Say hello',
        ], [
            'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
            'decorated' => true,
        ]);

        $display = $tester->getDisplay();
        $rawDisplay = $tester->getDisplay(true);

        self::assertStringContainsString('System prompt:', $display);
        self::assertStringContainsString('Available tools:', $display);
        self::assertStringContainsString('User prompt:', $display);
        self::assertStringContainsString('Say hello', $display);
        self::assertStringContainsString('Output:', $display);
        self::assertStringContainsString("Hello from Codex\n", $display);
        self::assertStringContainsString('Statistics:', $display);
        self::assertStringContainsString("Hello from Codex\n", $display);
        self::assertStringContainsString('Metric', $display);
        self::assertStringContainsString('Request', $display);
        self::assertStringContainsString('Session', $display);
        self::assertStringContainsString('tool_call_details', $display);
        self::assertStringContainsString('estimated_cost', $display);
        self::assertStringContainsString('read_file:1', $display);
        self::assertStringContainsString('1.234', $display);
        self::assertStringContainsString('12.444 (1,2%)', $display);
        self::assertStringContainsString('$0.1081', $display);
        self::assertStringContainsString("\e[", $rawDisplay);
        self::assertStringContainsString("\e[90mSay hello\e[39m", $rawDisplay);
        self::assertStringNotContainsString("\e[90mread_file:1", $rawDisplay);
        self::assertStringContainsString('total', $display);
        self::assertMatchesRegularExpression('/\x1b\[[0-9;]*33;1m12\.444 \(1,2%\)\x1b\[[0-9;]*m/', $rawDisplay);
    }

    public function testVeryVerboseExecutionMatchesVerboseStructure(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(CodexConfig::MODEL_ENV_VAR . '=openai:gpt-5');

        $tester = new CommandTester(new CodexRunCommand(client: $this->createClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
        ], [
            'verbosity' => OutputInterface::VERBOSITY_VERY_VERBOSE,
            'decorated' => true,
        ]);

        $display = $tester->getDisplay();
        $promptPosition = strpos($display, 'System prompt:');
        $userPromptPosition = strpos($display, 'User prompt:');
        $outputPosition = strpos($display, 'Output:');
        $tablePosition = strpos($display, 'Statistics:');

        self::assertNotFalse($promptPosition);
        self::assertNotFalse($userPromptPosition);
        self::assertNotFalse($outputPosition);
        self::assertNotFalse($tablePosition);
        self::assertLessThan($userPromptPosition, $promptPosition);
        self::assertLessThan($outputPosition, $userPromptPosition);
        self::assertLessThan($tablePosition, $outputPosition);
        self::assertStringContainsString('Available tools:', $display);
        self::assertStringContainsString('tool_call_details', $display);
    }

    public function testDebugVerbosityMatchesVerboseBehavior(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(CodexConfig::MODEL_ENV_VAR . '=openai:gpt-5');

        $tester = new CommandTester(new CodexRunCommand(client: $this->createClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
        ], [
            'verbosity' => OutputInterface::VERBOSITY_DEBUG,
            'decorated' => true,
        ]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('System prompt:', $display);
        self::assertStringContainsString('User prompt:', $display);
        self::assertStringContainsString('Output:', $display);
        self::assertStringContainsString('Statistics:', $display);
    }

    public function testVerboseExecutionHidesRowsWhenRequestAndSessionTokensAreBothZero(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(CodexConfig::MODEL_ENV_VAR . '=openai:gpt-5');

        $tester = new CommandTester(new CodexRunCommand(client: $this->createZeroUsageClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
        ], [
            'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
        ]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('System prompt:', $display);
        self::assertStringContainsString('User prompt:', $display);
        self::assertStringContainsString('Output:', $display);
        self::assertStringContainsString('Statistics:', $display);
        self::assertStringContainsString("Hello from Codex\n", $display);
        self::assertStringContainsString('Metric', $display);
        self::assertStringNotContainsString('cached_input', $display);
        self::assertStringNotContainsString('image_generation_total', $display);
        self::assertStringNotContainsString('tool_call_details', $display);
        self::assertStringNotContainsString('total', $display);
        self::assertStringNotContainsString('estimated_cost', $display);
    }

    public function testVerboseExecutionOmitsContextPercentageAndCostForUnknownModel(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(CodexConfig::MODEL_ENV_VAR . '=openai:unknown-model');

        $tester = new CommandTester(new CodexRunCommand(client: $this->createUnknownModelClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
        ], [
            'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
        ]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('12.444', $display);
        self::assertStringNotContainsString('(1,2%)', $display);
        self::assertStringNotContainsString('estimated_cost', $display);
    }

    public function testSessionFileOptionMutatesConfigBeforeClientRequest(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(CodexConfig::MODEL_ENV_VAR . '=openai:gpt-5');
        $config = new CodexConfig();
        $sessionFile = sys_get_temp_dir() . '/codex-session-' . bin2hex(random_bytes(4)) . '.json';
        $this->temporaryFiles[] = $sessionFile;
        file_put_contents($sessionFile, json_encode(['version' => 1, 'messages' => []], JSON_THROW_ON_ERROR));
        $client = new CodexClient($config, runtime: new class($config) implements CodexRuntimeInterface {
            public function __construct(
                private readonly CodexConfig $config,
            ) {
            }

            public function request(string $prompt, ?string $responseClass = null): CodexResponse
            {
                return new CodexResponse(
                    content: 'Hello from Codex',
                    model: 'openai:gpt-5.4',
                    metadata: [
                        'system_prompt' => 'System prompt',
                        'session_file_seen' => $this->config->sessionFile(),
                    ],
                );
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                throw new \BadMethodCallException('not used');
            }
        });

        $tester = new CommandTester(new CodexRunCommand(config: $config, client: $client));
        $tester->execute([
            'prompt' => 'Say hello',
            '--session-file' => $sessionFile,
        ], [
            'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
        ]);

        self::assertSame($sessionFile, $config->sessionFile());
        self::assertStringContainsString('Hello from Codex', $tester->getDisplay());
    }

    public function testAuthFileProvidesCredentialWhenApiKeyIsMissing(): void
    {
        putenv(CodexConfig::MODEL_ENV_VAR . '=openai:gpt-5');

        $authFile = $this->createAuthFile([
            'auth_mode' => 'tokens',
            'api_key' => null,
            'tokens' => [
                'id_token' => 'abc',
                'access_token' => 'def',
                'refresh_token' => 'ghi',
                'account_id' => 'zzz',
            ],
        ]);

        $tester = new CommandTester(new CodexRunCommand(client: $this->createClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
            '--auth-file' => $authFile,
        ]);

        self::assertSame("Hello from Codex\n", $tester->getDisplay());
    }

    public function testKeyOptionOverridesAuthFile(): void
    {
        putenv(CodexConfig::MODEL_ENV_VAR . '=openai:gpt-5');

        $authFile = $this->createAuthFile([
            'auth_mode' => 'tokens',
            'api_key' => null,
            'tokens' => [
                'id_token' => 'abc',
                'access_token' => 'def',
                'refresh_token' => 'ghi',
                'account_id' => 'zzz',
            ],
        ]);

        $tester = new CommandTester(new CodexRunCommand(client: $this->createClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
            '--auth-file' => $authFile,
            '--key' => 'override-key',
        ]);

        self::assertSame("Hello from Codex\n", $tester->getDisplay());
    }

    public function testMissingApiKeyThrowsWhenNoEnvAndNoAuthFileAreAvailable(): void
    {
        putenv(CodexConfig::MODEL_ENV_VAR . '=openai:gpt-5');

        $tester = new CommandTester(new CodexRunCommand());

        $this->expectException(MissingApiKey::class);

        $tester->execute([
            'prompt' => 'Say hello',
        ]);
    }

    public function testMissingModelThrows(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR . '=test-key');

        $tester = new CommandTester(new CodexRunCommand());

        $this->expectException(MissingModel::class);

        $tester->execute([
            'prompt' => 'Say hello',
        ]);
    }

    public function testMissingApiKeyThrows(): void
    {
        putenv(CodexConfig::MODEL_ENV_VAR . '=openai:gpt-5');

        $tester = new CommandTester(new CodexRunCommand());

        $this->expectException(MissingApiKey::class);

        $tester->execute([
            'prompt' => 'Say hello',
        ]);
    }

    private function createClientStub(?CodexConfig $config = null): CodexClient
    {
        $runtime = new class implements CodexRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): CodexResponse
            {
                return new CodexResponse(
                    content: 'Hello from Codex',
                    model: 'openai:gpt-5.4',
                    toolCalls: [
                        ['name' => 'read_file', 'arguments' => ['path' => '/tmp/example.txt']],
                    ],
                    metadata: [
                        'provider' => 'openai',
                        'system_prompt' => "System prompt line 1\nSystem prompt line 2",
                        'final_response' => [
                            'id' => 'resp_123',
                            'usage' => [
                                'input_tokens' => 12444,
                                'input_tokens_details' => ['cached_tokens' => 2000],
                                'output_tokens' => 5432,
                                'output_tokens_details' => ['reasoning_tokens' => 1000],
                                'total_tokens' => 12444,
                            ],
                        ],
                    ],
                );
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                throw new \BadMethodCallException('not used');
            }
        };

        return new CodexClient($config ?? new CodexConfig(), runtime: $runtime);
    }

    private function createZeroUsageClientStub(): CodexClient
    {
        $runtime = new class implements CodexRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): CodexResponse
            {
                return new CodexResponse(
                    content: 'Hello from Codex',
                    model: 'openai:gpt-5.4',
                    metadata: [
                        'system_prompt' => 'System prompt',
                        'final_response' => [
                            'id' => 'resp_123',
                            'usage' => [
                                'total_tokens' => 0,
                            ],
                        ],
                    ],
                );
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                throw new \BadMethodCallException('not used');
            }
        };

        return new CodexClient(runtime: $runtime);
    }

    private function createEchoClientStub(): CodexClient
    {
        $runtime = new class implements CodexRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): CodexResponse
            {
                return new CodexResponse(
                    content: $prompt,
                    model: 'openai:gpt-5',
                    metadata: [
                        'system_prompt' => 'System prompt',
                    ],
                );
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                throw new \BadMethodCallException('not used');
            }
        };

        return new CodexClient(runtime: $runtime);
    }

    private function createUnknownModelClientStub(): CodexClient
    {
        $runtime = new class implements CodexRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): CodexResponse
            {
                return new CodexResponse(
                    content: 'Hello from Codex',
                    model: 'openai:unknown-model',
                    metadata: [
                        'system_prompt' => 'System prompt',
                        'final_response' => [
                            'usage' => [
                                'input_tokens' => 12444,
                                'output_tokens' => 10,
                                'total_tokens' => 12454,
                            ],
                        ],
                    ],
                );
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                throw new \BadMethodCallException('not used');
            }
        };

        return new CodexClient(runtime: $runtime);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createAuthFile(array $payload): string
    {
        $path = tempnam(sys_get_temp_dir(), 'codex-auth-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
