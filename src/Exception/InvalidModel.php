<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Exception;

use InvalidArgumentException;

final class InvalidModel extends InvalidArgumentException
{
    public static function missingProviderPrefix(): self
    {
        return new self('Invalid model format. Use "provider:model", for example "openai:gpt-5".');
    }

    public static function unsupportedProvider(string $provider): self
    {
        return new self(sprintf(
            'Unsupported provider "%s". Supported providers are: openai, anthropic, gemini.',
            $provider,
        ));
    }
}
