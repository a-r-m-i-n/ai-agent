<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tool;

interface ToolInterface
{
    public function name(): string;

    public function execute(array $input): ToolResult;
}
