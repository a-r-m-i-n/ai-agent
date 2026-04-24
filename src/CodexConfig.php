<?php

declare(strict_types=1);

namespace Armin\CodexPhp;

use Armin\CodexPhp\Auth\CodexAuth;

final class CodexConfig
{
    public const API_KEY_ENV_VAR = 'CODEX_API_KEY';
    public const MODEL_ENV_VAR = 'CODEX_DEFAULT_MODEL';

    public function __construct(
        private ?string $apiKey = null,
        private ?string $model = null,
        private ?CodexAuth $auth = null,
        private ?string $sessionFile = null,
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
        if ($this->apiKey !== null && $this->apiKey !== '') {
            return $this->apiKey;
        }

        if ($this->auth instanceof CodexAuth && $this->auth->authMode() === CodexAuth::MODE_API_KEY) {
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

    public function setAuth(?CodexAuth $auth): self
    {
        $this->auth = $auth;

        return $this;
    }

    public function sessionFile(): ?string
    {
        if ($this->sessionFile === null || $this->sessionFile === '') {
            return null;
        }

        return $this->sessionFile;
    }

    public function setSessionFile(?string $sessionFile): self
    {
        $this->sessionFile = $sessionFile;

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
}
