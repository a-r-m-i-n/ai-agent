<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tool\Builtin;

use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolResult;

final class WriteFileTool extends AbstractTool implements ToolInterface
{
    public function name(): string
    {
        return 'write_file';
    }

    public function execute(array $input): ToolResult
    {
        $path = $this->requireString($input, 'path');
        $contents = $input['contents'] ?? null;

        if (!is_string($contents)) {
            return ToolResult::failure([
                'path' => $path,
                'error' => 'The "contents" input must be a string.',
            ]);
        }

        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            return ToolResult::failure([
                'path' => $path,
                'error' => 'Unable to create directory.',
            ]);
        }

        $bytes = file_put_contents($path, $contents);

        if ($bytes === false) {
            return ToolResult::failure([
                'path' => $path,
                'error' => 'Unable to write file.',
            ]);
        }

        return ToolResult::success([
            'path' => $path,
            'bytes_written' => $bytes,
        ]);
    }
}
