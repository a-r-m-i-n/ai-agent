<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tool\Builtin;

use Armin\CodexPhp\Exception\InvalidToolInput;
use Armin\CodexPhp\Tool\SchemaAwareToolInterface;
use Armin\CodexPhp\Tool\ToolDescriptionInterface;
use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolResult;
use Symfony\Component\Finder\Finder;

final class FindFilesTool extends AbstractTool implements ToolInterface, SchemaAwareToolInterface, ToolDescriptionInterface
{
    public function name(): string
    {
        return 'find_files';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Absolute or project-relative directory path to search in.',
                ],
                'filter' => [
                    'type' => 'string',
                    'description' => 'Optional Symfony Finder name filter such as "*.php".',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function description(): string
    {
        return 'Lists files in a directory using Symfony Finder and optionally filters them by filename pattern such as "*.php".';
    }

    public function execute(array $input): ToolResult
    {
        $path = $this->resolvePath($this->requireString($input, 'path'));
        $filter = $input['filter'] ?? null;

        if ($filter !== null && (!is_string($filter) || $filter === '')) {
            throw new InvalidToolInput('The "filter" input must be a non-empty string when provided.');
        }

        if (!is_dir($path)) {
            return ToolResult::failure([
                'path' => $path,
                'filter' => $filter,
                'files' => [],
                'error' => 'Directory not found.',
            ]);
        }

        $finder = new Finder();
        $finder->files()->in($path);

        if (is_string($filter)) {
            $finder->name($filter);
        }

        $files = [];
        foreach ($finder as $file) {
            $files[] = $file->getRealPath() ?: $file->getPathname();
        }

        sort($files);

        return ToolResult::success([
            'path' => $path,
            'filter' => $filter,
            'files' => $files,
            'count' => \count($files),
        ]);
    }
}
