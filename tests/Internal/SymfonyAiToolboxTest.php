<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tests\Internal;

use Armin\AiAgent\Internal\SymfonyAiToolbox;
use Armin\AiAgent\Tool\SchemaAwareToolInterface;
use Armin\AiAgent\Tool\ToolDescriptionInterface;
use Armin\AiAgent\Tool\ToolInterface;
use Armin\AiAgent\Tool\ToolRegistry;
use Armin\AiAgent\Tool\ToolResult;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\ToolCall;

final class SymfonyAiToolboxTest extends TestCase
{
    public function testGetToolsExposesRegisteredTools(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new class implements ToolInterface {
            public function name(): string
            {
                return 'custom_tool';
            }

            public function execute(array $input): ToolResult
            {
                return ToolResult::success($input);
            }
        });

        $toolbox = new SymfonyAiToolbox($registry);
        $tools = $toolbox->getTools();

        self::assertCount(1, $tools);
        self::assertSame('custom_tool', $tools[0]->getName());
        self::assertSame('Executes the "custom_tool" tool.', $tools[0]->getDescription());
    }

    public function testExecuteReturnsJsonEncodedToolPayload(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new class implements ToolInterface {
            public function name(): string
            {
                return 'custom_tool';
            }

            public function execute(array $input): ToolResult
            {
                return ToolResult::success([
                    'value' => strtoupper((string) $input['value']),
                ]);
            }
        });

        $toolbox = new SymfonyAiToolbox($registry);
        $result = $toolbox->execute(new ToolCall('tool-call-1', 'custom_tool', ['value' => 'abc']));

        self::assertJson($result->getResult());
        self::assertSame(
            ['success' => true, 'payload' => ['value' => 'ABC']],
            json_decode($result->getResult(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testGetToolsIncludesSchemaWhenAvailable(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new class implements SchemaAwareToolInterface {
            public function name(): string
            {
                return 'custom_tool';
            }

            public function parameters(): array
            {
                return [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string'],
                    ],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ];
            }

            public function execute(array $input): ToolResult
            {
                return ToolResult::success($input);
            }
        });

        $toolbox = new SymfonyAiToolbox($registry);
        $tools = $toolbox->getTools();

        self::assertSame(['path' => ['type' => 'string']], $tools[0]->getParameters()['properties']);
        self::assertSame(['path'], $tools[0]->getParameters()['required']);
    }

    public function testGetToolsUsesExplicitToolDescriptionWhenAvailable(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new class implements ToolInterface, ToolDescriptionInterface {
            public function name(): string
            {
                return 'custom_tool';
            }

            public function description(): string
            {
                return 'Runs a custom action for the current task.';
            }

            public function execute(array $input): ToolResult
            {
                return ToolResult::success($input);
            }
        });

        $toolbox = new SymfonyAiToolbox($registry);
        $tools = $toolbox->getTools();

        self::assertSame('Runs a custom action for the current task.', $tools[0]->getDescription());
    }
}
