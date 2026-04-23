<?php

declare(strict_types=1);

namespace Armin\CodexPhp;

final class CodexResponse
{
    /**
     * @param list<array{name: string, arguments: array<string, mixed>}> $toolCalls
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly string $content,
        private readonly string $model,
        private readonly array $toolCalls = [],
        private readonly array $metadata = [],
    ) {
    }

    public function content(): string
    {
        return $this->content;
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    public function toolCalls(): array
    {
        return $this->toolCalls;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array{
     *     model: string,
     *     content: string,
     *     tool_calls: list<array{name: string, arguments: array<string, mixed>}>,
     *     metadata: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'content' => $this->content,
            'tool_calls' => $this->toolCalls,
            'metadata' => $this->metadata,
        ];
    }
}
