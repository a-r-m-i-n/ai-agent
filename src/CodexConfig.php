<?php

declare(strict_types=1);

namespace Armin\CodexPhp;

final class CodexConfig
{
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly string $apiKeyEnvVar = 'CODEX_API_KEY',
        private readonly ?string $model = null,
        private readonly string $modelEnvVar = 'CODEX_DEFAULT_MODEL',
    ) {
    }

    public function apiKey(): ?string
    {
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
}
