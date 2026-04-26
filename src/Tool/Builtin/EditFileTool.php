<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tool\Builtin;

use Armin\AiAgent\Exception\InvalidToolInput;
use Armin\AiAgent\Tool\SchemaAwareToolInterface;
use Armin\AiAgent\Tool\ToolDescriptionInterface;
use Armin\AiAgent\Tool\ToolInterface;
use Armin\AiAgent\Tool\ToolResult;

final class EditFileTool extends AbstractTool implements ToolInterface, SchemaAwareToolInterface, ToolDescriptionInterface
{
    public function name(): string
    {
        return 'edit_file';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Absolute or project-relative path of the file to edit.',
                ],
                'edits' => [
                    'type' => 'array',
                    'description' => 'Sequential text replacements to apply to the current file contents.',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'old_text' => [
                                'type' => 'string',
                                'description' => 'Exact text to replace.',
                            ],
                            'new_text' => [
                                'type' => 'string',
                                'description' => 'Replacement text.',
                            ],
                            'replace_all' => [
                                'type' => 'boolean',
                                'description' => 'When true, replace all matches instead of requiring exactly one match.',
                            ],
                        ],
                        'required' => ['old_text', 'new_text'],
                    ],
                ],
            ],
            'required' => ['path', 'edits'],
        ];
    }

    public function description(): string
    {
        return 'Applies validated text replacements to an existing file. Use this for targeted edits instead of rewriting the whole file.';
    }

    public function execute(array $input): ToolResult
    {
        $path = $this->resolvePath($this->requireString($input, 'path'));
        $edits = $input['edits'] ?? null;

        if (!is_file($path)) {
            return ToolResult::failure([
                'path' => $path,
                'error' => 'File not found.',
            ]);
        }

        if (!is_readable($path)) {
            return ToolResult::failure([
                'path' => $path,
                'error' => 'Unable to read file.',
            ]);
        }

        if (!is_array($edits) || $edits === []) {
            throw new InvalidToolInput('The "edits" input must be a non-empty array.');
        }

        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            return ToolResult::failure([
                'path' => $path,
                'error' => 'Unable to read file.',
            ]);
        }

        $updatedContents = $contents;
        $totalReplacements = 0;

        foreach ($edits as $index => $edit) {
            if (!is_array($edit)) {
                throw new InvalidToolInput(sprintf('The edit at index %d must be an object.', $index));
            }

            $oldText = $this->requireString($edit, 'old_text');

            if (!array_key_exists('new_text', $edit) || !is_string($edit['new_text'])) {
                throw new InvalidToolInput('The "new_text" input must be a string.');
            }

            $replaceAll = $edit['replace_all'] ?? false;

            if (!is_bool($replaceAll)) {
                throw new InvalidToolInput('The "replace_all" input must be a boolean when provided.');
            }

            $occurrences = substr_count($updatedContents, $oldText);

            if ($occurrences === 0) {
                return ToolResult::failure([
                    'path' => $path,
                    'error' => sprintf('Edit %d did not match any text.', $index),
                ]);
            }

            if ($replaceAll === false && $occurrences !== 1) {
                return ToolResult::failure([
                    'path' => $path,
                    'error' => sprintf('Edit %d matched %d times; expected exactly one match.', $index, $occurrences),
                ]);
            }

            if ($replaceAll === false) {
                $position = strpos($updatedContents, $oldText);

                if ($position === false) {
                    return ToolResult::failure([
                        'path' => $path,
                        'error' => sprintf('Edit %d did not match any text.', $index),
                    ]);
                }

                $updatedContents = substr_replace($updatedContents, $edit['new_text'], $position, strlen($oldText));
            } else {
                $updatedContents = str_replace($oldText, $edit['new_text'], $updatedContents, $count);
            }

            $totalReplacements += $replaceAll ? $occurrences : 1;
        }

        $bytesWritten = file_put_contents($path, $updatedContents);

        if ($bytesWritten === false) {
            return ToolResult::failure([
                'path' => $path,
                'error' => 'Unable to write file.',
            ]);
        }

        return ToolResult::success([
            'path' => $path,
            'applied_edits' => count($edits),
            'total_replacements' => $totalReplacements,
            'bytes_written' => $bytesWritten,
        ]);
    }
}
