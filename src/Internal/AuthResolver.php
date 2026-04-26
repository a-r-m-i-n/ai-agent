<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal;

use Armin\AiAgent\Auth\AgentAuth;
use Armin\AiAgent\AiAgentConfig;
use Armin\AiAgent\Exception\MissingApiKey;

final class AuthResolver
{
    public function resolve(AiAgentConfig $config): ResolvedAuth
    {
        $apiKey = $config->apiKey();
        if ($apiKey !== null) {
            return new ResolvedAuth('api_key', apiKey: $apiKey);
        }

        $auth = $config->auth();
        if ($auth instanceof AgentAuth) {
            return match ($auth->authMode()) {
                AgentAuth::MODE_API_KEY => new ResolvedAuth('api_key', apiKey: $auth->apiKey()),
                AgentAuth::MODE_TOKENS, AgentAuth::MODE_CHATGPT => new ResolvedAuth(
                    'tokens',
                    accessToken: $auth->tokens()?->accessToken(),
                    accountId: $auth->tokens()?->accountId(),
                ),
            };
        }

        throw MissingApiKey::forEnvVar($config->apiKeyEnvVar());
    }
}
