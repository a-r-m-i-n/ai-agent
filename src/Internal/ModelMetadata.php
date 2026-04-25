<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

final class ModelMetadata
{
    /**
     * @param array{url: string, retrieved_at?: string} $source
     */
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly int $contextWindow,
        private readonly int $maxOutputTokens,
        private readonly ModelPricing $pricing,
        private readonly array $source,
    ) {
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function qualifiedName(): string
    {
        return $this->provider . ':' . $this->model;
    }

    public function contextWindow(): int
    {
        return $this->contextWindow;
    }

    public function maxOutputTokens(): int
    {
        return $this->maxOutputTokens;
    }

    public function pricing(): ModelPricing
    {
        return $this->pricing;
    }

    /**
     * @return array{url: string, retrieved_at?: string}
     */
    public function source(): array
    {
        return $this->source;
    }
}
