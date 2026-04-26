<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal\Session;

final class AgentSessionResolver
{
    public function resolve(?string $session): ?ResolvedAgentSession
    {
        if ($session === null || $session === '') {
            return null;
        }

        if (is_file($session) && is_readable($session)) {
            $store = new AgentSessionStore($session);

            return new ResolvedAgentSession('file', $store->load(), $store);
        }

        if ($this->looksLikeInlineJson($session)) {
            return new ResolvedAgentSession('inline', AgentSessionStore::loadFromJson($session));
        }

        $store = new AgentSessionStore($session);

        return new ResolvedAgentSession('file', $store->load(), $store);
    }

    private function looksLikeInlineJson(string $session): bool
    {
        $trimmed = ltrim($session);

        return $trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[');
    }
}
