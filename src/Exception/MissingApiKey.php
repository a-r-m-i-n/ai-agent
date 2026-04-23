<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Exception;

use RuntimeException;

final class MissingApiKey extends RuntimeException
{
    public static function forEnvVar(string $envVar): self
    {
        return new self(sprintf('No API key configured. Use --key, --auth-file or set %s.', $envVar));
    }
}
