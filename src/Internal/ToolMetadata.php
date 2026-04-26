<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal;

use Armin\AiAgent\Tool\ToolDescriptionInterface;
use Armin\AiAgent\Tool\ToolInterface;

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
