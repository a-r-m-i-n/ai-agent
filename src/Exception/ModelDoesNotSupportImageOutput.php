<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Exception;

final class ModelDoesNotSupportImageOutput extends \RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self(sprintf('The configured model "%s" does not support image output.', $model));
    }
}
