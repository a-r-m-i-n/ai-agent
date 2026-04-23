<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Auth;

use Armin\CodexPhp\Exception\InvalidAuth;

final class CodexAuth
{
    public const MODE_API_KEY = 'api_key';
    public const MODE_TOKENS = 'tokens';

    public function __construct(
        private readonly string $authMode = '',
        private readonly ?string $apiKey = null,
        private readonly ?CodexAuthTokens $tokens = null,
        private readonly ?string $lastRefresh = null,
    ) {
        $this->validate();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $authMode = $data['auth_mode'] ?? '';
        if (!is_string($authMode)) {
            throw InvalidAuth::invalidField('auth_mode', 'a string');
        }

        $apiKey = $data['api_key'] ?? null;
        if ($apiKey !== null && !is_string($apiKey)) {
            throw InvalidAuth::invalidField('api_key', 'null or a string');
        }
        if ($apiKey === '') {
            $apiKey = null;
        }

        $lastRefresh = $data['last_refresh'] ?? null;
        if ($lastRefresh !== null && !is_string($lastRefresh)) {
            throw InvalidAuth::invalidField('last_refresh', 'null or a string');
        }

        $tokens = null;
        if (array_key_exists('tokens', $data) && $data['tokens'] !== null) {
            if (!is_array($data['tokens'])) {
                throw InvalidAuth::invalidField('tokens', 'null or an object');
            }

            $tokens = new CodexAuthTokens(
                self::requireString($data['tokens'], 'tokens.id_token'),
                self::requireString($data['tokens'], 'tokens.access_token'),
                self::requireString($data['tokens'], 'tokens.refresh_token'),
                self::requireString($data['tokens'], 'tokens.account_id'),
            );
        }

        return new self($authMode, $apiKey, $tokens, $lastRefresh);
    }

    public static function fromFile(string $path): self
    {
        return (new CodexAuthFileLoader())->load($path);
    }

    public function authMode(): string
    {
        return $this->authMode;
    }

    public function apiKey(): ?string
    {
        return $this->apiKey;
    }

    public function tokens(): ?CodexAuthTokens
    {
        return $this->tokens;
    }

    public function lastRefresh(): ?string
    {
        return $this->lastRefresh;
    }

    public function accessToken(): ?string
    {
        return $this->tokens?->accessToken();
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== null;
    }

    public function hasTokens(): bool
    {
        return $this->tokens !== null;
    }

    public function credential(): string
    {
        return match ($this->authMode) {
            self::MODE_API_KEY => $this->apiKey ?? throw InvalidAuth::missingAuthModeCredential(self::MODE_API_KEY, 'api_key'),
            self::MODE_TOKENS => $this->tokens?->accessToken() ?? throw InvalidAuth::missingAuthModeCredential(self::MODE_TOKENS, 'tokens'),
        };
    }

    /**
     * @return array{
     *   auth_mode: string,
     *   api_key?: string|null,
     *   tokens?: array{id_token: string, access_token: string, refresh_token: string, account_id: string},
     *   last_refresh?: string|null
     * }
     */
    public function toArray(): array
    {
        $data = [
            'auth_mode' => $this->authMode,
            'api_key' => $this->apiKey,
        ];

        if ($this->tokens !== null) {
            $data['tokens'] = $this->tokens->toArray();
        }

        if ($this->lastRefresh !== null) {
            $data['last_refresh'] = $this->lastRefresh;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireString(array $data, string $field): string
    {
        $segments = explode('.', $field);
        $key = end($segments);
        $value = $data[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw InvalidAuth::invalidField($field, 'a non-empty string');
        }

        return $value;
    }

    private function validate(): void
    {
        if (!in_array($this->authMode, [self::MODE_API_KEY, self::MODE_TOKENS], true)) {
            throw InvalidAuth::invalidAuthMode($this->authMode);
        }

        if ($this->authMode === self::MODE_API_KEY && $this->apiKey === null) {
            throw InvalidAuth::missingAuthModeCredential(self::MODE_API_KEY, 'api_key');
        }

        if ($this->authMode === self::MODE_TOKENS && $this->tokens === null) {
            throw InvalidAuth::missingAuthModeCredential(self::MODE_TOKENS, 'tokens');
        }
    }
}
