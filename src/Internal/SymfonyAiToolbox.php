<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\SchemaAwareToolInterface;
use Armin\CodexPhp\Tool\ToolRegistry;
use JsonException;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

final class SymfonyAiToolbox implements ToolboxInterface
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
    ) {
    }

    public function getTools(): array
    {
        return array_map(
            fn (ToolInterface $tool): Tool => new Tool(
                new ExecutionReference($tool::class, 'execute'),
                $tool->name(),
                sprintf('Executes the "%s" tool.', $tool->name()),
                $tool instanceof SchemaAwareToolInterface ? $tool->parameters() : null,
            ),
            array_values($this->toolRegistry->all()),
        );
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        if (!$this->toolRegistry->has($toolCall->getName())) {
            throw ToolNotFoundException::notFoundForToolCall($toolCall);
        }

        try {
            $result = $this->toolRegistry->get($toolCall->getName())->execute($toolCall->getArguments());
        } catch (\Throwable $e) {
            throw ToolExecutionException::executionFailed($toolCall, $e);
        }

        return new ToolResult($toolCall, $this->normalizeToolPayload($result->isSuccess(), $result->payload()));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function normalizeToolPayload(bool $success, array $payload): string
    {
        try {
            return json_encode([
                'success' => $success,
                'payload' => $payload,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return json_encode([
                'success' => $success,
                'payload' => [
                    'error' => 'Tool result could not be encoded as JSON.',
                ],
            ]) ?: '{"success":false,"payload":{"error":"Tool result could not be encoded as JSON."}}';
        }
    }
}
