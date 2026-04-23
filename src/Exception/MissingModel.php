<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Exception;

use RuntimeException;

final class MissingModel extends RuntimeException
{
    public static function forEnvVar(string $envVar): self
    {
        return new self(sprintf('No model configured. Use --model or set %s.', $envVar));
    }
}
