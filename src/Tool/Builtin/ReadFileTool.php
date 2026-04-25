<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tool\Builtin;

use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolDescriptionInterface;
use Armin\CodexPhp\Tool\SchemaAwareToolInterface;
use Armin\CodexPhp\Tool\ToolResult;
use Symfony\Component\Finder\Finder;
use Armin\CodexPhp\Exception\InvalidToolInput;

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
                'start_line' => [
                    'type' => 'integer',
                    'description' => '1-based first line to include.',
                ],
                'end_line' => [
                    'type' => 'integer',
                    'description' => '1-based last line to include.',
                ],
                'max_lines' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of lines to return from the selected range.',
                ],
                'tail_lines' => [
                    'type' => 'integer',
                    'description' => 'Return the last N lines of the file. Cannot be combined with start_line or end_line.',
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
        $startLine = $this->optionalPositiveInt($input, 'start_line');
        $endLine = $this->optionalPositiveInt($input, 'end_line');
        $maxLines = $this->optionalPositiveInt($input, 'max_lines');
        $tailLines = $this->parseTailLines($input);

        if ($tailLines !== null && ($startLine !== null || $endLine !== null)) {
            $tailLines = null;
        }

        if ($startLine !== null && $endLine !== null && $startLine > $endLine) {
            throw new InvalidToolInput('The "start_line" input must be less than or equal to "end_line".');
        }

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

        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $hasTrailingNewline = preg_match("/(?:\r\n|\n|\r)$/", $contents) === 1;

        if ($contents === '') {
            $lines = [];
        } elseif ($hasTrailingNewline) {
            array_pop($lines);
        }

        $totalLines = count($lines);

        if ($startLine !== null && $startLine > $totalLines) {
            return ToolResult::failure([
                'path' => $path,
                'error' => 'Start line is outside the file.',
            ]);
        }

        if ($tailLines !== null) {
            $sliceStart = max($totalLines - $tailLines, 0);
            $slice = array_slice($lines, $sliceStart);
            $actualStartLine = $slice === [] ? 0 : $sliceStart + 1;
            $actualEndLine = $slice === [] ? 0 : $totalLines;
        } else {
            $sliceStart = $startLine !== null ? $startLine - 1 : 0;
            $sliceLength = null;

            if ($endLine !== null) {
                $sliceLength = $endLine - ($startLine ?? 1) + 1;
            }

            $slice = array_slice($lines, $sliceStart, $sliceLength);
            $actualStartLine = $slice === [] ? 0 : $sliceStart + 1;
            $actualEndLine = $slice === [] ? 0 : $sliceStart + count($slice);
        }

        $truncated = false;

        if ($maxLines !== null && count($slice) > $maxLines) {
            $slice = array_slice($slice, 0, $maxLines);
            $actualEndLine = $actualStartLine === 0 ? 0 : $actualStartLine + count($slice) - 1;
            $truncated = true;
        }

        return ToolResult::success([
            'path' => $path,
            'contents' => implode("\n", $slice),
            'start_line' => $actualStartLine,
            'end_line' => $actualEndLine,
            'total_lines' => $totalLines,
            'truncated' => $truncated,
        ]);
    }

    private function parseTailLines(array $input): ?int
    {
        if (!array_key_exists('tail_lines', $input) || $input['tail_lines'] === null || $input['tail_lines'] === 0) {
            return null;
        }

        $value = $input['tail_lines'];

        if (!is_int($value) || $value < 0) {
            throw new InvalidToolInput('The "tail_lines" input must be a positive integer when provided.');
        }

        return $value;
    }
}
