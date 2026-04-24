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

    public function testDebugOutputsJsonPayloadWhenRequested(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(CodexConfig::MODEL_ENV_VAR . '=openai:gpt-5');
        $sessionFile = sys_get_temp_dir() . '/codex-session-' . bin2hex(random_bytes(4)) . '.json';
        $this->temporaryFiles[] = $sessionFile;

        $tester = new CommandTester(new CodexRunCommand(client: $this->createClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
            '--debug' => true,
            '--session-file' => $sessionFile,
        ]);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Say hello', $payload['prompt']);
        self::assertSame('openai:gpt-5', $payload['model']);
        self::assertSame('env', $payload['model_source']);
        self::assertSame('env', $payload['api_key_source']);
        self::assertSame($sessionFile, $payload['session_file']);
        self::assertSame('Hello from Codex', $payload['content']);
        self::assertSame('read_file', $payload['tool_calls'][0]['name']);
        self::assertSame('openai', $payload['metadata']['provider']);
    }

    public function testSessionFileOptionMutatesConfigBeforeClientRequest(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(CodexConfig::MODEL_ENV_VAR . '=openai:gpt-5');
        $config = new CodexConfig();
        $sessionFile = sys_get_temp_dir() . '/codex-session-' . bin2hex(random_bytes(4)) . '.json';
        $this->temporaryFiles[] = $sessionFile;
        $client = new CodexClient($config, runtime: new class($config) implements CodexRuntimeInterface {
            public function __construct(
                private readonly CodexConfig $config,
            ) {
            }

            public function request(string $prompt): CodexResponse
            {
                return new CodexResponse(
                    content: 'Hello from Codex',
                    model: 'openai:gpt-5',
                    metadata: ['session_file_seen' => $this->config->sessionFile()],
                );
            }
        });

        $tester = new CommandTester(new CodexRunCommand(config: $config, client: $client));
        $tester->execute([
            'prompt' => 'Say hello',
            '--debug' => true,
            '--session-file' => $sessionFile,
        ]);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($sessionFile, $config->sessionFile());
        self::assertSame($sessionFile, $payload['metadata']['session_file_seen']);
    }

    public function testDebugAllOutputsFinalResponseAndStreamEvents(): void
    {
        putenv(CodexConfig::API_KEY_ENV_VAR . '=test-key');
        putenv(CodexConfig::MODEL_ENV_VAR . '=openai:gpt-5');

        $tester = new CommandTester(new CodexRunCommand(client: $this->createClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
            '--debug-all' => true,
        ]);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['id' => 'resp_123', 'usage' => ['total_tokens' => 12]], $payload['final_response']);
        self::assertSame([
            ['type' => 'response.created'],
            ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'usage' => ['total_tokens' => 12]]],
        ], $payload['stream_events']);
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
            '--debug' => true,
        ]);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('option', $payload['api_key_source']);
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

    private function createClientStub(): CodexClient
    {
        $runtime = new class implements CodexRuntimeInterface {
            public function request(string $prompt): CodexResponse
            {
                return new CodexResponse(
                    content: 'Hello from Codex',
                    model: 'openai:gpt-5',
                    toolCalls: [
                        ['name' => 'read_file', 'arguments' => ['path' => '/tmp/example.txt']],
                    ],
                    metadata: [
                        'provider' => 'openai',
                        'final_response' => ['id' => 'resp_123', 'usage' => ['total_tokens' => 12]],
                        'stream_events' => [
                            ['type' => 'response.created'],
                            ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'usage' => ['total_tokens' => 12]]],
                        ],
                    ],
                );
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
