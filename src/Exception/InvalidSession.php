<?php

declare(strict_types=1);

namespace Armin\AiAgent\Exception;

use InvalidArgumentException;

final class InvalidSession extends InvalidArgumentException
{
    public static function unreadableFile(string $path): self
    {
        return new self(sprintf('Session file "%s" could not be read.', $path));
    }

    public static function unwritableFile(string $path): self
    {
        return new self(sprintf('Session file "%s" could not be written.', $path));
    }

    public static function invalidJson(string $path): self
    {
        return new self(sprintf('Invalid session file "%s". The file must contain valid JSON.', $path));
    }

    public static function invalidFileStructure(string $details): self
    {
        return new self(sprintf('Invalid session file structure. %s', $details));
    }

    public static function unsupportedVersion(int|string|null $version): self
    {
        return new self(sprintf('Unsupported session file version "%s".', (string) $version));
    }
}
