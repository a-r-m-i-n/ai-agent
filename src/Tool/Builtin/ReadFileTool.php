<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tool\Builtin;

use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolResult;
use Symfony\Component\Finder\Finder;

final class ReadFileTool extends AbstractTool implements ToolInterface
{
    public function name(): string
    {
        return 'read_file';
    }

    public function execute(array $input): ToolResult
    {
        $path = $this->requireString($input, 'path');
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
