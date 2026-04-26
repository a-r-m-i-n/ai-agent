<?php

declare(strict_types=1);

namespace Armin\AiAgent\Auth;

final class AgentAuthTokens
{
    public function __construct(
        private readonly string $idToken,
        private readonly string $accessToken,
        private readonly string $refreshToken,
        private readonly string $accountId,
    ) {
    }

    public function idToken(): string
    {
        return $this->idToken;
    }

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function refreshToken(): string
    {
        return $this->refreshToken;
    }

    public function accountId(): string
    {
        return $this->accountId;
    }

    /**
     * @return array{id_token: string, access_token: string, refresh_token: string, account_id: string}
     */
    public function toArray(): array
    {
        return [
            'id_token' => $this->idToken,
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'account_id' => $this->accountId,
        ];
    }
}
