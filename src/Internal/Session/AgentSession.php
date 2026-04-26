<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal\Session;

final class AgentSession
{
    /**
     * @param list<array{
     *     role: 'user'|'assistant'|'tool',
     *     content: string,
     *     tool_calls?: list<array{id?: string, name: string, arguments: array<string, mixed>}>,
     *     tool_call_id?: string,
     *     metadata?: array<string, mixed>
     * }> $messages
     */
    public function __construct(
        private array $messages = [],
    ) {
    }

    /**
     * @return list<array{
     *     role: 'user'|'assistant'|'tool',
     *     content: string,
     *     tool_calls?: list<array{id?: string, name: string, arguments: array<string, mixed>}>,
     *     tool_call_id?: string,
     *     metadata?: array<string, mixed>
     * }>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * @return list<array{
     *     role: 'user'|'assistant'|'tool',
     *     content: string,
     *     tool_calls?: list<array{id?: string, name: string, arguments: array<string, mixed>}>,
     *     tool_call_id?: string
     * }>
     */
    public function replayMessages(): array
    {
        $messages = [];

        foreach ($this->messages as $message) {
            $replayMessage = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];

            if ('assistant' === $message['role'] && isset($message['tool_calls']) && is_array($message['tool_calls']) && $message['tool_calls'] !== []) {
                $replayMessage['tool_calls'] = $message['tool_calls'];
            }

            if ('tool' === $message['role'] && isset($message['tool_call_id']) && is_string($message['tool_call_id'])) {
                $replayMessage['tool_call_id'] = $message['tool_call_id'];
            }

            $messages[] = $replayMessage;
        }

        return $messages;
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
     * @param list<array{id?: string, name: string, arguments: array<string, mixed>}> $toolCalls
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

    public function appendToolMessage(string $content, string $toolCallId): void
    {
        $this->messages[] = [
            'role' => 'tool',
            'content' => $content,
            'tool_call_id' => $toolCallId,
        ];
    }
}
