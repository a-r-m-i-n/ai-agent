<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tool;

final class ToolResult
{
    private function __construct(
        private readonly bool $success,
        private readonly array $payload,
    ) {
    }

    public static function success(array $payload = []): self
    {
        return new self(true, $payload);
    }

    public static function failure(array $payload = []): self
    {
        return new self(false, $payload);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function payload(): array
    {
        return $this->payload;
    }
}
