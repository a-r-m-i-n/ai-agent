<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tool\Builtin;

use Armin\AiAgent\Tool\ToolInterface;
use Armin\AiAgent\Tool\ToolDescriptionInterface;
use Armin\AiAgent\Tool\SchemaAwareToolInterface;
use Armin\AiAgent\Tool\ToolResult;

final class WriteFileTool extends AbstractTool implements ToolInterface, SchemaAwareToolInterface, ToolDescriptionInterface
{
    public function name(): string
    {
        return 'write_file';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Absolute or project-relative path of the file to write.',
                ],
                'contents' => [
                    'type' => 'string',
                    'description' => 'Complete file contents to write.',
                ],
            ],
            'required' => ['path', 'contents'],
        ];
    }

    public function description(): string
    {
        return 'Writes complete file contents to an absolute path or to a path relative to the configured working directory.';
    }

    public function execute(array $input): ToolResult
    {
        $path = $this->resolvePath($this->requireString($input, 'path'));
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
