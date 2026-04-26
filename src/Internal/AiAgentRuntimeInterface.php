<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal;

use Armin\AiAgent\AiAgentResponse;

interface AiAgentRuntimeInterface
{
    public function request(string $prompt, ?string $responseClass = null): AiAgentResponse;

    public function requestStructured(string $prompt, string $responseClass): object;
}
