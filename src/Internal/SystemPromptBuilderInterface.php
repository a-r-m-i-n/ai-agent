<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

interface SystemPromptBuilderInterface
{
    public function build(): string;
}
