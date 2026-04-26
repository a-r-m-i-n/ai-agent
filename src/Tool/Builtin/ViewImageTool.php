<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tool\Builtin;

use Armin\AiAgent\Internal\LocalImageAttachmentResolver;
use Armin\AiAgent\Tool\SchemaAwareToolInterface;
use Armin\AiAgent\Tool\ToolDescriptionInterface;
use Armin\AiAgent\Tool\ToolInterface;
use Armin\AiAgent\Tool\ToolResult;

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
        return 'Loads a local image, validates it, returns compact image metadata, and signals the runtime to attach the image as real model image input in the next step.';
    }

    public function execute(array $input): ToolResult
    {
        $attachment = new LocalImageAttachmentResolver($this->defaultWorkingDirectory())
            ->resolve($this->requireString($input, 'path'));

        return ToolResult::success($attachment->toPayload());
    }
}
