<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tool\Builtin;

use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolDescriptionInterface;
use Armin\CodexPhp\Tool\SchemaAwareToolInterface;
use Armin\CodexPhp\Tool\ToolResult;
use Symfony\Component\Finder\Finder;

final class ReadFileTool extends AbstractTool implements ToolInterface, SchemaAwareToolInterface, ToolDescriptionInterface
{
    public function name(): string
    {
        return 'read_file';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Absolute or project-relative path of the file to read.',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function description(): string
    {
        return 'Reads the contents of a file from an absolute path or from a path relative to the configured working directory.';
    }

    public function execute(array $input): ToolResult
    {
        $path = $this->resolvePath($this->requireString($input, 'path'));
        $directory = dirname($path);

        if (!is_dir($directory)) {
            return ToolResult::failure([
                'path' => $path,
                'error' => 'File not found.',
            ]);
        }

        $finder = new Finder();
        $finder->files()->in($directory)->name(basename($path))->depth('== 0');

        if (!$finder->hasResults()) {
            return ToolResult::failure([
                'path' => $path,
                'error' => 'File not found.',
            ]);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return ToolResult::failure([
                'path' => $path,
                'error' => 'Unable to read file.',
            ]);
        }

        return ToolResult::success([
            'path' => $path,
            'contents' => $contents,
        ]);
    }
}
