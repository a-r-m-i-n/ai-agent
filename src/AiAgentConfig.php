<?php

declare(strict_types=1);

namespace Armin\AiAgent;

use Armin\AiAgent\Auth\AgentAuth;

final class AiAgentConfig
{
    public const API_KEY_ENV_VAR = 'AI_AGENT_API_KEY';
    public const MODEL_ENV_VAR = 'AI_AGENT_DEFAULT_MODEL';

    public function __construct(
        private ?string $apiKey = null,
        private ?string $model = null,
        private ?AgentAuth $auth = null,
        private ?string $session = null,
        private readonly ?string $workingDirectory = null,
        private readonly ?string $systemPrompt = null,
        private readonly string $systemPromptMode = 'append',
        private bool $enableBuiltinWebSearch = true,
        private bool $enableBuiltinImageGeneration = true,
    ) {
        if (!in_array($this->systemPromptMode, ['append', 'replace'], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid system prompt mode "%s". Supported values are "append" and "replace".', $this->systemPromptMode));
        }
    }

    public function auth(): ?AgentAuth
    {
        return $this->auth;
    }

    public function apiKey(): ?string
    {
        if ($this->apiKey !== null && $this->apiKey !== '') {
            return $this->apiKey;
        }

        if ($this->auth instanceof AgentAuth && $this->auth->authMode() === AgentAuth::MODE_API_KEY) {
            return $this->auth->credential();
        }

        $value = getenv(self::API_KEY_ENV_VAR);

        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    public function apiKeyEnvVar(): string
    {
        return self::API_KEY_ENV_VAR;
    }

    public function model(): ?string
    {
        if ($this->model !== null && $this->model !== '') {
            return $this->model;
        }

        $value = getenv(self::MODEL_ENV_VAR);

        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    public function modelEnvVar(): string
    {
        return self::MODEL_ENV_VAR;
    }

    public function setApiKey(?string $apiKey): self
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    public function setModel(?string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function setAuth(?AgentAuth $auth): self
    {
        $this->auth = $auth;

        return $this;
    }

    public function session(): ?string
    {
        if ($this->session === null || $this->session === '') {
            return null;
        }

        return $this->session;
    }

    public function setSession(?string $session): self
    {
        $this->session = $session;

        return $this;
    }

    public function workingDirectory(): ?string
    {
        if ($this->workingDirectory === null || $this->workingDirectory === '') {
            return null;
        }

        return $this->workingDirectory;
    }

    public function systemPrompt(): ?string
    {
        if ($this->systemPrompt === null || $this->systemPrompt === '') {
            return null;
        }

        return $this->systemPrompt;
    }

    public function systemPromptMode(): string
    {
        return $this->systemPromptMode;
    }

    public function enableBuiltinWebSearch(): bool
    {
        return $this->enableBuiltinWebSearch;
    }

    public function enableBuiltinImageGeneration(): bool
    {
        return $this->enableBuiltinImageGeneration;
    }

    public function setEnableBuiltinWebSearch(bool $enableBuiltinWebSearch): self
    {
        $this->enableBuiltinWebSearch = $enableBuiltinWebSearch;

        return $this;
    }

    public function setEnableBuiltinImageGeneration(bool $enableBuiltinImageGeneration): self
    {
        $this->enableBuiltinImageGeneration = $enableBuiltinImageGeneration;

        return $this;
    }
}
