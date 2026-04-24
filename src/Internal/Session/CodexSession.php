<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal\Session;

final class CodexSession
{
    /**
     * @param list<array{
     *     role: 'user'|'assistant',
     *     content: string,
     *     tool_calls?: list<array{name: string, arguments: array<string, mixed>}>,
     *     metadata?: array<string, mixed>
     * }> $messages
     */
    public function __construct(
        private array $messages = [],
    ) {
    }

    /**
     * @return list<array{
     *     role: 'user'|'assistant',
     *     content: string,
     *     tool_calls?: list<array{name: string, arguments: array<string, mixed>}>,
     *     metadata?: array<string, mixed>
     * }>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    public function count(): int
    {
        return \count($this->messages);
    }

    public function appendUserMessage(string $content): void
    {
        $this->messages[] = [
            'role' => 'user',
            'content' => $content,
        ];
    }

    /**
     * @param list<array{name: string, arguments: array<string, mixed>}> $toolCalls
     * @param array<string, mixed> $metadata
     */
    public function appendAssistantMessage(string $content, array $toolCalls = [], array $metadata = []): void
    {
        $message = [
            'role' => 'assistant',
            'content' => $content,
        ];

        if ($toolCalls !== []) {
            $message['tool_calls'] = $toolCalls;
        }

        if ($metadata !== []) {
            $message['metadata'] = $metadata;
        }

        $this->messages[] = $message;
    }
}
