<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tests\Internal;

use Armin\AiAgent\Internal\Session\AgentSession;
use Armin\AiAgent\Internal\Session\AgentSessionStore;
use PHPUnit\Framework\TestCase;

final class AgentSessionStoreTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . '/ai-agent-session-store-' . bin2hex(random_bytes(4));
        mkdir($this->tempDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDirectory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tempDirectory);
    }

    public function testSavePreservesGeneratedImageFileMetadata(): void
    {
        $store = new AgentSessionStore($this->tempDirectory . '/session.json');
        $session = new AgentSession();
        $session->appendAssistantMessage('done', metadata: [
            'generated_images' => [[
                'path' => '/tmp/out/cat_art.png',
                'filename' => 'cat_art.png',
                'mime_type' => 'image/png',
                'extension' => 'png',
                'size' => 123,
                'provider' => 'openai',
                'model' => 'gpt-5.4-mini',
                'revised_prompt' => 'painted cat',
                'provider_response' => [
                    'tool_usage' => [
                        'image_gen' => [
                            'input_tokens' => 10,
                            'output_tokens' => 20,
                            'total_tokens' => 30,
                        ],
                    ],
                ],
            ]],
        ]);

        $store->save($session);
        $loaded = json_decode((string) file_get_contents($store->path()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('/tmp/out/cat_art.png', $loaded['messages'][0]['metadata']['generated_images'][0]['path']);
        self::assertSame('cat_art.png', $loaded['messages'][0]['metadata']['generated_images'][0]['filename']);
        self::assertSame('image/png', $loaded['messages'][0]['metadata']['generated_images'][0]['mime_type']);
        self::assertSame(123, $loaded['messages'][0]['metadata']['generated_images'][0]['size']);
        self::assertSame('painted cat', $loaded['messages'][0]['metadata']['generated_images'][0]['revised_prompt']);
    }

    public function testSavePersistsImportantAssistantMetadataFields(): void
    {
        $store = new AgentSessionStore($this->tempDirectory . '/session.json');
        $session = new AgentSession();
        $session->appendAssistantMessage('done', metadata: [
            'provider' => 'openai',
            'model' => 'openai:gpt-5.4-mini',
            'attached_images' => [[
                'path' => '/tmp/in/reference.png',
                'filename' => 'reference.png',
                'mime_type' => 'image/png',
                'base64' => 'ignored',
            ]],
            'final_response' => [
                'id' => 'resp_123',
                'status' => 'completed',
                'created_at' => 1234567890,
                'usage' => ['total_tokens' => 12],
                'output' => [['type' => 'message']],
                'object' => 'response',
            ],
        ]);

        $store->save($session);
        $loaded = json_decode((string) file_get_contents($store->path()), true, 512, JSON_THROW_ON_ERROR);
        $metadata = $loaded['messages'][0]['metadata'];

        self::assertSame('openai', $metadata['provider']);
        self::assertSame('openai:gpt-5.4-mini', $metadata['model']);
        self::assertSame([
            'path' => '/tmp/in/reference.png',
            'filename' => 'reference.png',
            'mime_type' => 'image/png',
        ], $metadata['attached_images'][0]);
        self::assertSame([
            'id' => 'resp_123',
            'status' => 'completed',
            'created_at' => 1234567890,
            'usage' => ['total_tokens' => 12],
            'output' => [['type' => 'message']],
        ], $metadata['final_response']);
    }

    public function testSaveAndLoadSupportSystemMessages(): void
    {
        $store = new AgentSessionStore($this->tempDirectory . '/session.json');
        $session = new AgentSession([
            ['role' => 'system', 'content' => 'Stored system prompt'],
            ['role' => 'user', 'content' => 'Prompt'],
        ]);

        $store->save($session);
        $loaded = $store->load();

        self::assertSame('Stored system prompt', $loaded->messages()[0]['content']);
        self::assertSame('system', $loaded->messages()[0]['role']);
    }
}
