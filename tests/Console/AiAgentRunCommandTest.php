<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tests\Console;

use Armin\AiAgent\AiAgentClient;
use Armin\AiAgent\AiAgentConfig;
use Armin\AiAgent\AiAgentResponse;
use Armin\AiAgent\Console\AiAgentRunCommand;
use Armin\AiAgent\Exception\MissingApiKey;
use Armin\AiAgent\Exception\MissingModel;
use Armin\AiAgent\Internal\AiAgentRuntimeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class AiAgentRunCommandTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        putenv(AiAgentConfig::API_KEY_ENV_VAR);
        putenv(AiAgentConfig::MODEL_ENV_VAR);

        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        $this->temporaryFiles = [];
    }

    public function testCommandOutputsPlainTextUsingEnvironmentDefaults(): void
    {
        putenv(AiAgentConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(AiAgentConfig::MODEL_ENV_VAR . '=openai:gpt-5');

        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
        ]);

        self::assertSame("Hello from AI agent\n", $tester->getDisplay());
    }

    public function testOptionsOverrideEnvironmentValues(): void
    {
        putenv(AiAgentConfig::API_KEY_ENV_VAR . '=env-key');
        putenv(AiAgentConfig::MODEL_ENV_VAR . '=openai:env-model');

        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
            '--model' => 'anthropic:claude-3-5-haiku-20241022',
            '--key' => 'override-key',
        ]);

        self::assertSame("Hello from AI agent\n", $tester->getDisplay());
    }

    public function testExistingPromptFileIsLoadedAsPromptContent(): void
    {
        putenv(AiAgentConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(AiAgentConfig::MODEL_ENV_VAR . '=openai:gpt-5');
        $promptFile = sys_get_temp_dir() . '/ai-agent-prompt-' . bin2hex(random_bytes(4)) . '.txt';
        $this->temporaryFiles[] = $promptFile;
        file_put_contents($promptFile, 'Prompt from file');

        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createEchoClientStub()));
        $tester->execute([
            'prompt' => $promptFile,
        ]);

        self::assertSame("Prompt from file\n", $tester->getDisplay());
    }

    public function testMissingPromptFilePathRemainsLiteralPrompt(): void
    {
        putenv(AiAgentConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(AiAgentConfig::MODEL_ENV_VAR . '=openai:gpt-5');
        $missingPath = sys_get_temp_dir() . '/ai-agent-missing-' . bin2hex(random_bytes(4)) . '.txt';

        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createEchoClientStub()));
        $tester->execute([
            'prompt' => $missingPath,
        ]);

        self::assertSame($missingPath . "\n", $tester->getDisplay());
    }

    public function testPromptContainingAdditionalTextIsNotResolvedAsFile(): void
    {
        putenv(AiAgentConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(AiAgentConfig::MODEL_ENV_VAR . '=openai:gpt-5');
        $promptFile = sys_get_temp_dir() . '/ai-agent-prompt-' . bin2hex(random_bytes(4)) . '.txt';
        $this->temporaryFiles[] = $promptFile;
        file_put_contents($promptFile, 'Prompt from file');
        $prompt = $promptFile . ' please summarize';

        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createEchoClientStub()));
        $tester->execute([
            'prompt' => $prompt,
        ]);

        self::assertSame($prompt . "\n", $tester->getDisplay());
    }

    public function testVerboseExecutionPrintsUserPromptOutputAndStatisticsWithoutSystemPrompt(): void
    {
        putenv(AiAgentConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(AiAgentConfig::MODEL_ENV_VAR . '=openai:gpt-5');
        $sessionFile = sys_get_temp_dir() . '/ai-agent-session-' . bin2hex(random_bytes(4)) . '.json';
        $this->temporaryFiles[] = $sessionFile;
        $config = new AiAgentConfig(session: $sessionFile);
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

        $tester = new CommandTester(new AiAgentRunCommand(config: $config, client: $this->createClientStub($config)));
        $tester->execute([
            'prompt' => 'Say hello',
        ], [
            'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
            'decorated' => true,
        ]);

        $display = $tester->getDisplay();
        $rawDisplay = $tester->getDisplay(true);

        self::assertStringNotContainsString('System prompt:', $display);
        self::assertStringNotContainsString('Available tools:', $display);
        self::assertStringNotContainsString('Repository context:', $display);
        self::assertStringNotContainsString('Working directory: ' . getcwd(), $display);
        self::assertStringContainsString('User prompt:', $display);
        self::assertStringContainsString('Say hello', $display);
        self::assertStringContainsString('Output:', $display);
        self::assertStringContainsString("Hello from AI agent\n", $display);
        self::assertStringContainsString('Statistics:', $display);
        self::assertStringContainsString("Hello from AI agent\n", $display);
        self::assertStringContainsString('Metric', $display);
        self::assertStringContainsString('Request', $display);
        self::assertStringContainsString('Session', $display);
        self::assertStringContainsString('tool_call_details', $display);
        self::assertStringContainsString('estimated_cost', $display);
        self::assertStringContainsString('read_file:1', $display);
        self::assertStringContainsString('1.234', $display);
        self::assertStringContainsString('12.444 (1,2%)', $display);
        self::assertStringContainsString('1.235', $display);
        self::assertStringNotContainsString('1.235 (', $display);
        self::assertStringContainsString('$0.1081', $display);
        self::assertStringContainsString("\e[", $rawDisplay);
        self::assertStringContainsString("\e[90mSay hello\e[39m", $rawDisplay);
        self::assertStringNotContainsString("\e[90mread_file:1", $rawDisplay);
        self::assertStringContainsString('total', $display);
        self::assertMatchesRegularExpression('/\x1b\[[0-9;]*33;1m12\.444 \(1,2%\)\x1b\[[0-9;]*m/', $rawDisplay);
        self::assertStringNotContainsString('1.235 (', $rawDisplay);
    }

    public function testVeryVerboseExecutionAlsoPrintsSystemPrompt(): void
    {
        putenv(AiAgentConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(AiAgentConfig::MODEL_ENV_VAR . '=openai:gpt-5');

        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createClientStub()));
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
        self::assertStringContainsString('Repository context:', $display);
        self::assertStringContainsString('tool_call_details', $display);
    }

    public function testDebugVerbosityIncludesSystemPrompt(): void
    {
        putenv(AiAgentConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(AiAgentConfig::MODEL_ENV_VAR . '=openai:gpt-5');

        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createClientStub()));
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

    public function testDebugSystemPromptOutputsOnlyBuiltPromptWithoutClientRequest(): void
    {
        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createFailingClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
            '--debug' => 'system_prompt',
        ]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('Available tools:', $display);
        self::assertStringContainsString('Repository context:', $display);
        self::assertStringContainsString('Working directory: ' . getcwd(), $display);
        self::assertStringNotContainsString('Hello from AI agent', $display);
        self::assertStringNotContainsString('Statistics:', $display);
    }

    public function testDebugStatisticsOutputsSessionTableWithoutRequestColumnOrClientRequest(): void
    {
        $sessionFile = sys_get_temp_dir() . '/ai-agent-session-' . bin2hex(random_bytes(4)) . '.json';
        $this->temporaryFiles[] = $sessionFile;
        file_put_contents($sessionFile, json_encode([
            'version' => 1,
            'messages' => [
                [
                    'role' => 'assistant',
                    'content' => 'First response',
                    'tool_calls' => [
                        ['name' => 'read_file', 'arguments' => ['path' => '/tmp/one.txt']],
                    ],
                    'metadata' => [
                        'final_response' => [
                            'usage' => [
                                'input_tokens' => 1000,
                                'output_tokens' => 100,
                                'total_tokens' => 1100,
                            ],
                        ],
                    ],
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Second response',
                    'tool_calls' => [
                        ['name' => 'search', 'arguments' => ['query' => 'test']],
                    ],
                    'metadata' => [
                        'final_response' => [
                            'usage' => [
                                'input_tokens' => 2000,
                                'input_tokens_details' => ['cached_tokens' => 500],
                                'output_tokens' => 200,
                                'output_tokens_details' => ['reasoning_tokens' => 25],
                                'total_tokens' => 2200,
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createFailingClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
            '--session' => $sessionFile,
            '--model' => 'openai:gpt-5',
            '--debug' => 'stats',
        ], [
            'decorated' => true,
        ]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('Statistics:', $display);
        self::assertStringContainsString('Metric', $display);
        self::assertStringContainsString('Session', $display);
        self::assertStringNotContainsString('Request', $display);
        self::assertStringContainsString('3.300', $display);
        self::assertStringNotContainsString('3.300 (', $display);
        self::assertStringContainsString('read_file:1, search:1', $display);
        self::assertStringNotContainsString('Hello from AI agent', $display);
    }

    public function testDebugRejectsUnsupportedMode(): void
    {
        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createFailingClientStub()));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid debug mode "invalid"');

        $tester->execute([
            'prompt' => 'Say hello',
            '--debug' => 'invalid',
        ]);
    }

    public function testDebugHistoryOutputsRequestsResponsesAndToolCallsFromSession(): void
    {
        $sessionFile = sys_get_temp_dir() . '/ai-agent-session-' . bin2hex(random_bytes(4)) . '.json';
        $this->temporaryFiles[] = $sessionFile;
        file_put_contents($sessionFile, json_encode([
            'version' => 1,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'First prompt',
                ],
                [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [
                        ['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'README.md']],
                    ],
                ],
                [
                    'role' => 'tool',
                    'content' => '{"success":true}',
                    'tool_call_id' => 'call-1',
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Final answer one',
                ],
                [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [
                        ['id' => 'call-2', 'name' => 'search', 'arguments' => ['query' => 'package name']],
                    ],
                ],
                [
                    'role' => 'tool',
                    'content' => '{"success":true}',
                    'tool_call_id' => 'call-2',
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Final answer two',
                ],
                [
                    'role' => 'user',
                    'content' => 'Second prompt',
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Final answer three',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createFailingClientStub()));
        $tester->execute([
            'prompt' => 'Ignored prompt',
            '--session' => $sessionFile,
            '--debug' => 'history',
        ]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('Request 1', $display);
        self::assertStringContainsString('User prompt:', $display);
        self::assertStringContainsString('First prompt', $display);
        self::assertStringContainsString('Response 1:', $display);
        self::assertStringContainsString('Response 2:', $display);
        self::assertStringContainsString('Response:', $display);
        self::assertStringContainsString('Tool calls:', $display);
        self::assertStringContainsString('read_file [call-1] {"path":"README.md"}', $display);
        self::assertStringContainsString('search [call-2] {"query":"package name"}', $display);
        self::assertStringContainsString('Final answer two', $display);
        self::assertStringContainsString('Request 2', $display);
        self::assertStringContainsString('Second prompt', $display);
        self::assertStringContainsString('Final answer three', $display);
        self::assertStringNotContainsString('Hello from AI agent', $display);
        self::assertStringNotContainsString('Tool result:', $display);
        self::assertStringNotContainsString('tool_call_id: call-1', $display);
        self::assertStringNotContainsString('{"success":true}', $display);
        self::assertStringNotContainsString('Content:', $display);
    }

    public function testDebugHistoryRequiresSessionFileOption(): void
    {
        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createFailingClientStub()));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Debug mode "history" requires --session.');

        $tester->execute([
            'prompt' => 'Say hello',
            '--debug' => 'history',
        ]);
    }

    public function testDebugHistoryRejectsInvalidInlineSession(): void
    {
        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createFailingClientStub()));

        $this->expectException(\Armin\AiAgent\Exception\InvalidSession::class);

        $tester->execute([
            'prompt' => 'Say hello',
            '--session' => '{invalid',
            '--debug' => 'history',
        ]);
    }

    public function testDebugHistorySupportsInlineSessionPayload(): void
    {
        $payload = json_encode([
            'version' => 1,
            'messages' => [
                ['role' => 'user', 'content' => 'Inline prompt'],
                ['role' => 'assistant', 'content' => 'Inline answer'],
            ],
        ], JSON_THROW_ON_ERROR);

        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createFailingClientStub()));
        $tester->execute([
            'prompt' => 'Ignored prompt',
            '--session' => $payload,
            '--debug' => 'history',
        ]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('Inline prompt', $display);
        self::assertStringContainsString('Inline answer', $display);
    }

    public function testDebugHistoryFallsBackToFinalResponseTextWhenAssistantContentIsEmpty(): void
    {
        $sessionFile = sys_get_temp_dir() . '/ai-agent-session-' . bin2hex(random_bytes(4)) . '.json';
        $this->temporaryFiles[] = $sessionFile;
        file_put_contents($sessionFile, json_encode([
            'version' => 1,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Prompt',
                ],
                [
                    'role' => 'assistant',
                    'content' => '',
                    'metadata' => [
                        'final_response' => [
                            'output' => [
                                [
                                    'type' => 'message',
                                    'content' => [
                                        [
                                            'type' => 'output_text',
                                            'text' => 'Recovered final answer',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createFailingClientStub()));
        $tester->execute([
            'prompt' => 'Ignored prompt',
            '--session' => $sessionFile,
            '--debug' => 'history',
        ]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('Response:', $display);
        self::assertStringContainsString('Recovered final answer', $display);
    }

    public function testVerboseExecutionHidesRowsWhenRequestAndSessionTokensAreBothZero(): void
    {
        putenv(AiAgentConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(AiAgentConfig::MODEL_ENV_VAR . '=openai:gpt-5');

        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createZeroUsageClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
        ], [
            'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
        ]);

        $display = $tester->getDisplay();

        self::assertStringNotContainsString('System prompt:', $display);
        self::assertStringContainsString('User prompt:', $display);
        self::assertStringContainsString('Output:', $display);
        self::assertStringContainsString('Statistics:', $display);
        self::assertStringContainsString("Hello from AI agent\n", $display);
        self::assertStringContainsString('Metric', $display);
        self::assertStringNotContainsString('cached_input', $display);
        self::assertStringNotContainsString('image_generation_total', $display);
        self::assertStringNotContainsString('tool_call_details', $display);
        self::assertStringNotContainsString('total', $display);
        self::assertStringNotContainsString('estimated_cost', $display);
    }

    public function testVerboseExecutionOmitsContextPercentageAndCostForUnknownModel(): void
    {
        putenv(AiAgentConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(AiAgentConfig::MODEL_ENV_VAR . '=openai:unknown-model');

        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createUnknownModelClientStub()));
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
        putenv(AiAgentConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(AiAgentConfig::MODEL_ENV_VAR . '=openai:gpt-5');
        $config = new AiAgentConfig();
        $sessionFile = sys_get_temp_dir() . '/ai-agent-session-' . bin2hex(random_bytes(4)) . '.json';
        $this->temporaryFiles[] = $sessionFile;
        file_put_contents($sessionFile, json_encode(['version' => 1, 'messages' => []], JSON_THROW_ON_ERROR));
        $client = new AiAgentClient($config, runtime: new class($config) implements AiAgentRuntimeInterface {
            public function __construct(
                private readonly AiAgentConfig $config,
            ) {
            }

            public function request(string $prompt, ?string $responseClass = null): AiAgentResponse
            {
                return new AiAgentResponse(
                    content: 'Hello from AI agent',
                    model: 'openai:gpt-5.4',
                    metadata: [
                        'system_prompt' => 'System prompt',
                        'session_seen' => $this->config->session(),
                    ],
                );
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                throw new \BadMethodCallException('not used');
            }
        });

        $tester = new CommandTester(new AiAgentRunCommand(config: $config, client: $client));
        $tester->execute([
            'prompt' => 'Say hello',
            '--session' => $sessionFile,
        ], [
            'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
        ]);

        self::assertSame($sessionFile, $config->session());
        self::assertStringContainsString('Hello from AI agent', $tester->getDisplay());
    }

    public function testAuthFileProvidesCredentialWhenApiKeyIsMissing(): void
    {
        putenv(AiAgentConfig::MODEL_ENV_VAR . '=openai:gpt-5');

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

        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
            '--auth-file' => $authFile,
        ]);

        self::assertSame("Hello from AI agent\n", $tester->getDisplay());
    }

    public function testAuthFileProvidesCredentialWhenChatGptModeIsUsed(): void
    {
        putenv(AiAgentConfig::MODEL_ENV_VAR . '=openai:gpt-5');

        $authFile = $this->createAuthFile([
            'auth_mode' => 'chatgpt',
            'api_key' => null,
            'tokens' => [
                'id_token' => 'abc',
                'access_token' => 'def',
                'refresh_token' => 'ghi',
                'account_id' => 'zzz',
            ],
        ]);

        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
            '--auth-file' => $authFile,
        ]);

        self::assertSame("Hello from AI agent\n", $tester->getDisplay());
    }

    public function testKeyOptionOverridesAuthFile(): void
    {
        putenv(AiAgentConfig::MODEL_ENV_VAR . '=openai:gpt-5');

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

        $tester = new CommandTester(new AiAgentRunCommand(client: $this->createClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
            '--auth-file' => $authFile,
            '--key' => 'override-key',
        ]);

        self::assertSame("Hello from AI agent\n", $tester->getDisplay());
    }

    public function testMissingApiKeyThrowsWhenNoEnvAndNoAuthFileAreAvailable(): void
    {
        putenv(AiAgentConfig::MODEL_ENV_VAR . '=openai:gpt-5');

        $tester = new CommandTester(new AiAgentRunCommand());

        $this->expectException(MissingApiKey::class);

        $tester->execute([
            'prompt' => 'Say hello',
        ]);
    }

    public function testMissingModelThrows(): void
    {
        putenv(AiAgentConfig::API_KEY_ENV_VAR . '=test-key');

        $tester = new CommandTester(new AiAgentRunCommand());

        $this->expectException(MissingModel::class);

        $tester->execute([
            'prompt' => 'Say hello',
        ]);
    }

    public function testMissingApiKeyThrows(): void
    {
        putenv(AiAgentConfig::MODEL_ENV_VAR . '=openai:gpt-5');

        $tester = new CommandTester(new AiAgentRunCommand());

        $this->expectException(MissingApiKey::class);

        $tester->execute([
            'prompt' => 'Say hello',
        ]);
    }

    private function createClientStub(?AiAgentConfig $config = null): AiAgentClient
    {
        $runtime = new class implements AiAgentRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): AiAgentResponse
            {
                return new AiAgentResponse(
                    content: 'Hello from AI agent',
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

        return new AiAgentClient($config ?? new AiAgentConfig(), runtime: $runtime);
    }

    private function createZeroUsageClientStub(): AiAgentClient
    {
        $runtime = new class implements AiAgentRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): AiAgentResponse
            {
                return new AiAgentResponse(
                    content: 'Hello from AI agent',
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

        return new AiAgentClient(runtime: $runtime);
    }

    private function createEchoClientStub(): AiAgentClient
    {
        $runtime = new class implements AiAgentRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): AiAgentResponse
            {
                return new AiAgentResponse(
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

        return new AiAgentClient(runtime: $runtime);
    }

    private function createUnknownModelClientStub(): AiAgentClient
    {
        $runtime = new class implements AiAgentRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): AiAgentResponse
            {
                return new AiAgentResponse(
                    content: 'Hello from AI agent',
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

        return new AiAgentClient(runtime: $runtime);
    }

    private function createFailingClientStub(): AiAgentClient
    {
        $runtime = new class implements AiAgentRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): AiAgentResponse
            {
                throw new \RuntimeException('Client request must not be executed.');
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                throw new \BadMethodCallException('not used');
            }
        };

        return new AiAgentClient(runtime: $runtime);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createAuthFile(array $payload): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ai-agent-auth-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
