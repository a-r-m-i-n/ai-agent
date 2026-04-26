<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tool;

interface SchemaAwareToolInterface extends ToolInterface
{
    /**
     * @return array<string, mixed>
     */
    public function parameters(): array;
}
