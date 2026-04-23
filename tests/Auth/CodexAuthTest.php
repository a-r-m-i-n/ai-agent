<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Auth;

use Armin\CodexPhp\Auth\CodexAuth;
use Armin\CodexPhp\Exception\InvalidAuth;
use PHPUnit\Framework\TestCase;

final class CodexAuthTest extends TestCase
{
    public function testFromArrayAcceptsApiKeyWithoutTokens(): void
    {
        $auth = CodexAuth::fromArray([
            'auth_mode' => 'api_key',
            'api_key' => 'secret',
            'last_refresh' => '2026-04-08T13:44:58.467138412Z',
        ]);

        self::assertSame('secret', $auth->credential());
        self::assertFalse($auth->hasTokens());
    }

    public function testFromArrayAcceptsTokensWithoutApiKey(): void
    {
        $auth = CodexAuth::fromArray([
            'auth_mode' => 'tokens',
            'api_key' => null,
            'tokens' => [
                'id_token' => 'abc',
                'access_token' => 'def',
                'refresh_token' => 'ghi',
                'account_id' => 'zzz',
            ],
        ]);

        self::assertSame('def', $auth->credential());
        self::assertTrue($auth->hasTokens());
        self::assertFalse($auth->hasApiKey());
    }

    public function testFromArrayRejectsInvalidAuthMode(): void
    {
        $this->expectException(InvalidAuth::class);

        CodexAuth::fromArray([
            'auth_mode' => 'token',
            'api_key' => null,
        ]);
    }

    public function testFromArrayRequiresApiKeyForApiKeyMode(): void
    {
        $this->expectException(InvalidAuth::class);

        CodexAuth::fromArray([
            'auth_mode' => 'api_key',
            'api_key' => null,
        ]);
    }

    public function testLoaderParsesAuthFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'codex-auth-');
        self::assertNotFalse($path);

        file_put_contents($path, json_encode([
            'auth_mode' => 'tokens',
            'api_key' => null,
            'tokens' => [
                'id_token' => 'abc',
                'access_token' => 'def',
                'refresh_token' => 'ghi',
                'account_id' => 'zzz',
            ],
            'last_refresh' => '2026-04-08T13:44:58.467138412Z',
        ], JSON_THROW_ON_ERROR));

        try {
            $auth = CodexAuth::fromFile($path);
            self::assertSame('def', $auth->credential());
            self::assertSame('2026-04-08T13:44:58.467138412Z', $auth->lastRefresh());
        } finally {
            @unlink($path);
        }
    }
}
