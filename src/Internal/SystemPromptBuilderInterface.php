<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal;

interface SystemPromptBuilderInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function build(array $context = []): string;
}
