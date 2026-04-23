<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal\Provider;

use Armin\CodexPhp\Internal\ResolvedAuth;
use Symfony\AI\Platform\Bridge\Anthropic;
use Symfony\AI\Platform\Bridge\Anthropic\Contract\AnthropicContract;
use Symfony\AI\Platform\Bridge\Gemini;
use Symfony\AI\Platform\Bridge\Gemini\Contract\GeminiContract;
use Symfony\AI\Platform\Bridge\OpenAi;
use Symfony\AI\Platform\Bridge\OpenAi\Contract\OpenAiContract;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TokenPlatformFactory
{
    public static function create(string $provider, ResolvedAuth $auth, ?HttpClientInterface $httpClient = null): Platform
    {
        $httpClient ??= HttpClient::create();

        return new Platform([
            match ($provider) {
                'openai' => new Provider(
                    'openai',
                    [new OpenAiTokenModelClient($httpClient, $auth->accessToken() ?? '', $auth->accountId() ?? '')],
                    [new OpenAiTokenResultConverter()],
                    new OpenAi\ModelCatalog(),
                    OpenAiContract::create(),
                ),
                'anthropic' => new Provider(
                    'anthropic',
                    [new AnthropicTokenModelClient($httpClient, $auth->accessToken() ?? '')],
                    [new Anthropic\ResultConverter()],
                    new Anthropic\ModelCatalog(),
                    AnthropicContract::create(),
                ),
                'gemini' => new Provider(
                    'gemini',
                    [new GeminiTokenModelClient($httpClient, $auth->accessToken() ?? '')],
                    [new Gemini\Gemini\ResultConverter()],
                    new Gemini\ModelCatalog(),
                    GeminiContract::create(),
                ),
            },
        ], new CatalogBasedModelRouter());
    }
}
