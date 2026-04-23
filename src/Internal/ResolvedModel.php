<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

final class ResolvedModel
{
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly string $qualifiedName,
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
        return $this->qualifiedName;
    }
}
