<?php

declare(strict_types=1);

namespace Armin\CodexPhp;

use Armin\CodexPhp\Auth\CodexAuth;

final class CodexConfig
{
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly string $apiKeyEnvVar = 'CODEX_API_KEY',
        private readonly ?string $model = null,
        private readonly string $modelEnvVar = 'CODEX_DEFAULT_MODEL',
        private readonly ?CodexAuth $auth = null,
        private readonly ?string $workingDirectory = null,
        private readonly ?string $systemPrompt = null,
        private readonly string $systemPromptMode = 'append',
    ) {
        if (!in_array($this->systemPromptMode, ['append', 'replace'], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid system prompt mode "%s". Supported values are "append" and "replace".', $this->systemPromptMode));
        }
    }

    public function auth(): ?CodexAuth
    {
        return $this->auth;
    }

    public function apiKey(): ?string
    {
        if ($this->auth instanceof CodexAuth && $this->auth->authMode() === CodexAuth::MODE_API_KEY) {
            return $this->auth->credential();
        }

        if ($this->apiKey !== null && $this->apiKey !== '') {
            return $this->apiKey;
        }

        $value = getenv($this->apiKeyEnvVar);

        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    public function apiKeyEnvVar(): string
    {
        return $this->apiKeyEnvVar;
    }

    public function resolveApiKey(?string $override = null): ?string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }

        if ($this->auth instanceof CodexAuth && $this->auth->authMode() === CodexAuth::MODE_API_KEY) {
            return $this->auth->credential();
        }

        return $this->apiKey();
    }

    public function model(): ?string
    {
        if ($this->model !== null && $this->model !== '') {
            return $this->model;
        }

        $value = getenv($this->modelEnvVar);

        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    public function resolveModel(?string $override = null): ?string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }

        return $this->model();
    }

    public function modelEnvVar(): string
    {
        return $this->modelEnvVar;
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
}
