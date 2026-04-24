<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tool\Builtin;

use Armin\CodexPhp\Tool\SchemaAwareToolInterface;
use Armin\CodexPhp\Tool\ToolDescriptionInterface;
use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolResult;

final class GenerateImageTool extends AbstractTool implements ToolInterface, SchemaAwareToolInterface, ToolDescriptionInterface
{
    public function name(): string
    {
        return 'generate_image';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'prompt' => [
                    'type' => 'string',
                    'description' => 'Prompt that describes the new image to generate.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Optional absolute or project-relative output path or directory.',
                ],
                'filename' => [
                    'type' => 'string',
                    'description' => 'Optional target filename. If no extension is given, the provider output format is used.',
                ],
                'overwrite' => [
                    'type' => 'boolean',
                    'description' => 'Whether an existing target file may be overwritten. Defaults to true.',
                ],
            ],
            'required' => ['prompt'],
        ];
    }

    public function description(): string
    {
        return 'Generates a new image from a text prompt and stores it on disk. Use this for new images instead of write_file. You may provide path, filename, or both.';
    }

    public function execute(array $input): ToolResult
    {
        return ToolResult::failure([
            'error' => 'The "generate_image" tool is executed internally by the runtime.',
            'input' => $input,
        ]);
    }
}
