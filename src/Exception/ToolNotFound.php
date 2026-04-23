<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Exception;

use RuntimeException;

final class ToolNotFound extends RuntimeException
{
    public static function forName(string $name): self
    {
        return new self(sprintf('Tool "%s" is not registered.', $name));
    }
}
