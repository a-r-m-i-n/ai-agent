<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

use Armin\CodexPhp\CodexResponse;

interface CodexRuntimeInterface
{
    public function request(string $prompt): CodexResponse;
}
