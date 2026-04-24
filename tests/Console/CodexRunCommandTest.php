<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Console;

use Armin\CodexPhp\CodexClient;
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
        putenv('CODEX_API_KEY');
        putenv('CODEX_DEFAULT_MODEL');

        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        $this->temporaryFiles = [];
    }

    public function testCommandOutputsJsonUsingEnvironmentDefaults(): void
    {
        putenv('CODEX_API_KEY=test-key');
        putenv('CODEX_DEFAULT_MODEL=openai:gpt-5');

        $tester = new CommandTester(new CodexRunCommand(client: $this->createClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
        ]);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Say hello', $payload['prompt']);
        self::assertSame('openai:gpt-5', $payload['model']);
        self::assertSame('env', $payload['model_source']);
        self::assertSame('env', $payload['api_key_source']);
        self::assertSame('Hello from Codex', $payload['content']);
        self::assertSame('read_file', $payload['tool_calls'][0]['name']);
        self::assertSame('openai', $payload['metadata']['provider']);
    }

    public function testOptionsOverrideEnvironmentValues(): void
    {
        putenv('CODEX_API_KEY=env-key');
        putenv('CODEX_DEFAULT_MODEL=openai:env-model');

        $tester = new CommandTester(new CodexRunCommand(client: $this->createClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
            '--model' => 'anthropic:claude-3-5-haiku-20241022',
            '--key' => 'override-key',
        ]);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('anthropic:claude-3-5-haiku-20241022', $payload['model']);
        self::assertSame('option', $payload['model_source']);
        self::assertSame('option', $payload['api_key_source']);
        self::assertSame('Hello from Codex', $payload['content']);
    }

    public function testDebugOutputsOnlyFinalResponseWhenRequested(): void
    {
        putenv('CODEX_API_KEY=test-key');
        putenv('CODEX_DEFAULT_MODEL=openai:gpt-5');

        $tester = new CommandTester(new CodexRunCommand(client: $this->createClientStub()));
        $tester->execute([
            'prompt' => 'Say hello',
            '--debug' => true,
        ]);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['id' => 'resp_123', 'usage' => ['total_tokens' => 12]], $payload);
    }

    public function testDebugAllOutputsFinalResponseAndStreamEvents(): void
    {
        putenv('CODEX_API_KEY=test-key');
        putenv('CODEX_DEFAULT_MODEL=openai:gpt-5');

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
        putenv('CODEX_DEFAULT_MODEL=openai:gpt-5');

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

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('auth_file', $payload['api_key_source']);
        self::assertSame('Hello from Codex', $payload['content']);
    }

    public function testKeyOptionOverridesAuthFile(): void
    {
        putenv('CODEX_DEFAULT_MODEL=openai:gpt-5');

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

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('option', $payload['api_key_source']);
    }

    public function testMissingApiKeyThrowsWhenNoEnvAndNoAuthFileAreAvailable(): void
    {
        putenv('CODEX_DEFAULT_MODEL=openai:gpt-5');

        $tester = new CommandTester(new CodexRunCommand());

        $this->expectException(MissingApiKey::class);

        $tester->execute([
            'prompt' => 'Say hello',
        ]);
    }

    public function testMissingModelThrows(): void
    {
        putenv('CODEX_API_KEY=test-key');

        $tester = new CommandTester(new CodexRunCommand());

        $this->expectException(MissingModel::class);

        $tester->execute([
            'prompt' => 'Say hello',
        ]);
    }

    public function testMissingApiKeyThrows(): void
    {
        putenv('CODEX_DEFAULT_MODEL=openai:gpt-5');

        $tester = new CommandTester(new CodexRunCommand());

        $this->expectException(MissingApiKey::class);

        $tester->execute([
            'prompt' => 'Say hello',
        ]);
    }

    private function createClientStub(): CodexClient
    {
        $runtime = new class implements CodexRuntimeInterface {
            public function request(string $prompt, ?string $modelOverride = null, ?string $apiKeyOverride = null): CodexResponse
            {
                return new CodexResponse(
                    content: 'Hello from Codex',
                    model: $modelOverride ?? 'openai:gpt-5',
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
