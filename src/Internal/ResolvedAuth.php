<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal;

final class ResolvedAuth
{
    public function __construct(
        private readonly string $mode,
        private readonly ?string $apiKey = null,
        private readonly ?string $accessToken = null,
        private readonly ?string $accountId = null,
    ) {
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function apiKey(): ?string
    {
        return $this->apiKey;
    }

    public function accessToken(): ?string
    {
        return $this->accessToken;
    }

    public function accountId(): ?string
    {
        return $this->accountId;
    }
}
