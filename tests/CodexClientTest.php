<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests;

use Armin\CodexPhp\Auth\CodexAuth;
use Armin\CodexPhp\Auth\CodexAuthTokens;
use Armin\CodexPhp\CodexClient;
use Armin\CodexPhp\CodexConfig;
use Armin\CodexPhp\CodexResponse;
use Armin\CodexPhp\Exception\ToolNotFound;
use Armin\CodexPhp\Internal\CodexRuntimeInterface;
use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolResult;
use PHPUnit\Framework\TestCase;

final class CodexClientTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        putenv('CODEX_API_KEY');
        putenv('CODEX_DEFAULT_MODEL');
        $this->tempDirectory = sys_get_temp_dir() . '/codex-php-tests-' . bin2hex(random_bytes(4));
        mkdir($this->tempDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        putenv('CODEX_API_KEY');
        putenv('CODEX_DEFAULT_MODEL');
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
        putenv('CODEX_API_KEY=test-key');

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

    public function testMissingApiKeyReturnsNull(): void
    {
        putenv('CODEX_API_KEY');

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

    public function testRequestReturnsStructuredResponse(): void
    {
        $runtime = new class implements CodexRuntimeInterface {
            public function request(string $prompt, ?string $modelOverride = null, ?string $apiKeyOverride = null): CodexResponse
            {
                return new CodexResponse(
                    content: 'hello world',
                    model: $modelOverride ?? 'openai:gpt-5',
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
            public function request(string $prompt, ?string $modelOverride = null, ?string $apiKeyOverride = null): CodexResponse
            {
                return new CodexResponse('plain answer', 'openai:gpt-5');
            }
        };

        $client = new CodexClient(runtime: $runtime);

        self::assertSame('plain answer', $client->requestText('Prompt'));
    }

    public function testRequestPassesOverridesToRuntime(): void
    {
        $runtime = new class implements CodexRuntimeInterface {
            public ?string $model = null;
            public ?string $apiKey = null;

            public function request(string $prompt, ?string $modelOverride = null, ?string $apiKeyOverride = null): CodexResponse
            {
                $this->model = $modelOverride;
                $this->apiKey = $apiKeyOverride;

                return new CodexResponse('ok', $modelOverride ?? 'default');
            }
        };

        $client = new CodexClient(runtime: $runtime);
        $client->request('Prompt', 'openai:gpt-5.1', 'secret');

        self::assertSame('openai:gpt-5.1', $runtime->model);
        self::assertSame('secret', $runtime->apiKey);
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
