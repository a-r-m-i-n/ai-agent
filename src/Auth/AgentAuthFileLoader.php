<?php

declare(strict_types=1);

namespace Armin\AiAgent\Auth;

use Armin\AiAgent\Exception\InvalidAuth;
use JsonException;

final class AgentAuthFileLoader
{
    public function load(string $path): AgentAuth
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw InvalidAuth::unreadableFile($path);
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidAuth::invalidJson($path);
        }

        if (!is_array($data)) {
            throw InvalidAuth::invalidFileStructure();
        }

        return AgentAuth::fromArray($data);
    }
}
