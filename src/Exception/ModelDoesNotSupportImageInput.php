<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Exception;

use RuntimeException;

final class ModelDoesNotSupportImageInput extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self(sprintf(
            'The model "%s" does not support image input, but local image references were detected in the prompt.',
            $model,
        ));
    }
}
