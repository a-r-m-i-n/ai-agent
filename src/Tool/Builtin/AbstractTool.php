<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tool\Builtin;

use Armin\CodexPhp\Exception\InvalidToolInput;

abstract class AbstractTool
{
    protected function requireString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidToolInput(sprintf('The "%s" input must be a non-empty string.', $key));
        }

        return $value;
    }
}
