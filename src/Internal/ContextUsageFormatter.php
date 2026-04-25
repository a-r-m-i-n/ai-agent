<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

final class ContextUsageFormatter
{
    public function format(int $tokens, ?ModelMetadata $metadata): string
    {
        $formatted = number_format($tokens, 0, ',', '.');

        if (!$metadata instanceof ModelMetadata || $metadata->contextWindow() <= 0) {
            return $formatted;
        }

        $percentage = ($tokens / $metadata->contextWindow()) * 100;

        return sprintf('%s (%s%%)', $formatted, number_format($percentage, 1, ',', '.'));
    }
}
