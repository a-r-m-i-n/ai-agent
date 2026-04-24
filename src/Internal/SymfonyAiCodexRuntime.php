<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

use Armin\CodexPhp\Auth\CodexAuth;
use Armin\CodexPhp\CodexConfig;
use Armin\CodexPhp\CodexResponse;
use Armin\CodexPhp\Exception\MissingModel;
use Armin\CodexPhp\Internal\Provider\TokenPlatformFactory;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Gemini\Factory as GeminiFactory;
use Armin\CodexPhp\Tool\ToolRegistry;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\InputProcessor\SystemPromptInputProcessor;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SymfonyAiCodexRuntime implements CodexRuntimeInterface
{
    public function __construct(
        private readonly CodexConfig $config,
        private readonly ToolRegistry $toolRegistry,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly CodexResponseMapper $responseMapper = new CodexResponseMapper(),
        private readonly ModelNameParser $modelNameParser = new ModelNameParser(),
        private readonly AuthResolver $authResolver = new AuthResolver(),
        private readonly ?SystemPromptBuilderInterface $systemPromptBuilder = null,
    ) {
    }

    public function request(string $prompt, ?string $modelOverride = null, ?string $apiKeyOverride = null): CodexResponse
    {
        $model = $this->config->resolveModel($modelOverride);

        if ($model === null) {
            throw MissingModel::forEnvVar($this->config->modelEnvVar());
        }

        $resolvedModel = $this->modelNameParser->parse($model);
        $resolvedAuth = $this->authResolver->resolve($this->config, $apiKeyOverride);
        $toolbox = new SymfonyAiToolbox($this->toolRegistry);
        $agentProcessor = new AgentProcessor($toolbox);
        $agent = new Agent(
            $this->createPlatform($resolvedModel->provider(), $resolvedAuth),
            $resolvedModel->model(),
            [
                new SystemPromptInputProcessor(($this->systemPromptBuilder ?? new DefaultSystemPromptBuilder($this->config, $this->toolRegistry))->build(), $toolbox),
                $agentProcessor,
            ],
            [$agentProcessor],
        );

        $result = $agent->call(
            new MessageBag(Message::ofUser($prompt)),
            $this->buildRequestOptions($resolvedModel->provider(), $resolvedAuth),
        );

        return $this->responseMapper->map($resolvedModel->qualifiedName(), $result);
    }

    private function createPlatform(string $provider, ResolvedAuth $auth): PlatformInterface
    {
        if ($auth->mode() === CodexAuth::MODE_TOKENS) {
            return TokenPlatformFactory::create($provider, $auth, $this->httpClient);
        }

        return match ($provider) {
            'openai' => OpenAiFactory::createPlatform($auth->apiKey() ?? '', $this->httpClient),
            'anthropic' => AnthropicFactory::createPlatform($auth->apiKey() ?? '', $this->httpClient),
            'gemini' => GeminiFactory::createPlatform($auth->apiKey() ?? '', $this->httpClient),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestOptions(string $provider, ResolvedAuth $auth): array
    {
        if ($provider === 'openai' && $auth->mode() === CodexAuth::MODE_TOKENS) {
            return ['stream' => true];
        }

        return [];
    }
}
