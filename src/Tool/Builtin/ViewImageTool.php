<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tool\Builtin;

use Armin\CodexPhp\Internal\LocalImageAttachmentResolver;
use Armin\CodexPhp\Tool\SchemaAwareToolInterface;
use Armin\CodexPhp\Tool\ToolDescriptionInterface;
use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolResult;

final class ViewImageTool extends AbstractTool implements ToolInterface, SchemaAwareToolInterface, ToolDescriptionInterface
{
    public function name(): string
    {
        return 'view_image';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Absolute or project-relative path of the image to load.',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function description(): string
    {
        return 'Loads a local image, validates it, and returns normalized image metadata together with base64-encoded image data.';
    }

    public function execute(array $input): ToolResult
    {
        $attachment = new LocalImageAttachmentResolver($this->defaultWorkingDirectory())
            ->resolve($this->requireString($input, 'path'));

        return ToolResult::success($attachment->toPayload());
    }
}
