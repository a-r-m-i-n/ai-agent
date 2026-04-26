<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

interface SystemPromptBuilderInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function build(array $context = []): string;
}
