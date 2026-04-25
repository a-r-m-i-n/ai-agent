<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tool\Builtin;

use Armin\CodexPhp\Exception\InvalidToolInput;
use Armin\CodexPhp\Tool\SchemaAwareToolInterface;
use Armin\CodexPhp\Tool\ToolDescriptionInterface;
use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolResult;

final class WriteFilesTool extends AbstractTool implements ToolInterface, SchemaAwareToolInterface, ToolDescriptionInterface
{
    public function name(): string
    {
        return 'write_files';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'files' => [
                    'type' => 'array',
                    'description' => 'Files to write with complete contents.',
                    'minItems' => 1,
                    'items' => [
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
                    ],
                ],
            ],
            'required' => ['files'],
        ];
    }

    public function description(): string
    {
        return 'Writes complete contents to multiple files and reports success or failure for each file independently.';
    }

    public function execute(array $input): ToolResult
    {
        $files = $input['files'] ?? null;

        if (!is_array($files) || $files === []) {
            throw new InvalidToolInput('The "files" input must be a non-empty array.');
        }

        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($files as $index => $file) {
            if (!is_array($file)) {
                throw new InvalidToolInput(sprintf('The file entry at index %d must be an object.', $index));
            }

            $path = $this->resolvePath($this->requireString($file, 'path'));
            $contents = $file['contents'] ?? null;

            if (!is_string($contents)) {
                throw new InvalidToolInput('The "contents" input must be a string.');
            }

            $directory = dirname($path);

            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                $results[] = [
                    'path' => $path,
                    'success' => false,
                    'error' => 'Unable to create directory.',
                ];
                ++$failureCount;
                continue;
            }

            $bytes = file_put_contents($path, $contents);

            if ($bytes === false) {
                $results[] = [
                    'path' => $path,
                    'success' => false,
                    'error' => 'Unable to write file.',
                ];
                ++$failureCount;
                continue;
            }

            $results[] = [
                'path' => $path,
                'success' => true,
                'bytes_written' => $bytes,
            ];
            ++$successCount;
        }

        return ToolResult::success([
            'results' => $results,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
        ]);
    }
}
