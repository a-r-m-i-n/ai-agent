<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

use Armin\CodexPhp\Tool\ToolDescriptionInterface;
use Armin\CodexPhp\Tool\ToolInterface;

final class ToolMetadata
{
    public static function description(ToolInterface $tool): string
    {
        if ($tool instanceof ToolDescriptionInterface) {
            return $tool->description();
        }

        return sprintf('Executes the "%s" tool.', $tool->name());
    }
}
