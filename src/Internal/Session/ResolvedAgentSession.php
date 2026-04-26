<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal\Session;

final class ResolvedAgentSession
{
    public function __construct(
        private readonly string $mode,
        private readonly AgentSession $session,
        private readonly ?AgentSessionStore $store = null,
    ) {
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function session(): AgentSession
    {
        return $this->session;
    }

    public function store(): ?AgentSessionStore
    {
        return $this->store;
    }

    public function isFileMode(): bool
    {
        return $this->mode === 'file';
    }
}
