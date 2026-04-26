<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tool\Builtin;

use Armin\AiAgent\Exception\InvalidToolInput;

abstract class AbstractTool
{
    public function __construct(
        private readonly ?string $workingDirectory = null,
    ) {
    }

    protected function requireString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidToolInput(sprintf('The "%s" input must be a non-empty string.', $key));
        }

        return $value;
    }

    protected function optionalPositiveInt(array $input, string $key): ?int
    {
        if (!array_key_exists($key, $input) || $input[$key] === null) {
            return null;
        }

        $value = $input[$key];

        if (!is_int($value) || $value <= 0) {
            throw new InvalidToolInput(sprintf('The "%s" input must be a positive integer when provided.', $key));
        }

        return $value;
    }

    protected function resolvePath(string $path): string
    {
        if ($path === '' || $this->workingDirectory === null || $this->workingDirectory === '' || $this->isAbsolutePath($path)) {
            return $path;
        }

        return rtrim($this->workingDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }

    protected function defaultWorkingDirectory(): ?string
    {
        return $this->workingDirectory;
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }
}
