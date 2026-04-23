<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

use Armin\CodexPhp\Auth\CodexAuth;
use Armin\CodexPhp\CodexConfig;
use Armin\CodexPhp\Exception\MissingApiKey;

final class AuthResolver
{
    public function resolve(CodexConfig $config, ?string $apiKeyOverride = null): ResolvedAuth
    {
        if ($apiKeyOverride !== null && $apiKeyOverride !== '') {
            return new ResolvedAuth('api_key', apiKey: $apiKeyOverride);
        }

        $auth = $config->auth();
        if ($auth instanceof CodexAuth) {
            return match ($auth->authMode()) {
                CodexAuth::MODE_API_KEY => new ResolvedAuth('api_key', apiKey: $auth->apiKey()),
                CodexAuth::MODE_TOKENS => new ResolvedAuth(
                    'tokens',
                    accessToken: $auth->tokens()?->accessToken(),
                    accountId: $auth->tokens()?->accountId(),
                ),
            };
        }

        $apiKey = $config->apiKey();
        if ($apiKey === null) {
            throw MissingApiKey::forEnvVar($config->apiKeyEnvVar());
        }

        return new ResolvedAuth('api_key', apiKey: $apiKey);
    }
}
