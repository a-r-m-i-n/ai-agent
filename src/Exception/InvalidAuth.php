<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Exception;

use InvalidArgumentException;

final class InvalidAuth extends InvalidArgumentException
{
    public static function invalidAuthMode(string $mode): self
    {
        return new self(sprintf('Invalid auth configuration. "auth_mode" must be "api_key", "tokens" or "chatgpt", got "%s".', $mode));
    }

    public static function missingAuthModeCredential(string $mode, string $field): self
    {
        return new self(sprintf('Invalid auth configuration. "auth_mode=%s" requires "%s".', $mode, $field));
    }

    public static function missingCredentials(): self
    {
        return new self('Invalid auth configuration. Either "api_key" or "tokens" must be set.');
    }

    public static function invalidJson(string $path): self
    {
        return new self(sprintf('Invalid auth file "%s". The file must contain valid JSON.', $path));
    }

    public static function unreadableFile(string $path): self
    {
        return new self(sprintf('Auth file "%s" could not be read.', $path));
    }

    public static function invalidFileStructure(): self
    {
        return new self('Invalid auth configuration. The auth payload must be a JSON object.');
    }

    public static function invalidField(string $field, string $expected): self
    {
        return new self(sprintf('Invalid auth configuration. Field "%s" must be %s.', $field, $expected));
    }
}
