<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Auth;

use Armin\CodexPhp\Exception\InvalidAuth;
use JsonException;

final class CodexAuthFileLoader
{
    public function load(string $path): CodexAuth
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

        return CodexAuth::fromArray($data);
    }
}
