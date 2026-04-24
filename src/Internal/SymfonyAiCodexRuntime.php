<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

use Armin\CodexPhp\Auth\CodexAuth;
use Armin\CodexPhp\CodexConfig;
use Armin\CodexPhp\CodexResponse;
use Armin\CodexPhp\Exception\ModelDoesNotSupportImageInput;
use Armin\CodexPhp\Exception\MissingModel;
use Armin\CodexPhp\Internal\Provider\TokenPlatformFactory;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Gemini\Factory as GeminiFactory;
use Armin\CodexPhp\Tool\ToolRegistry;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\InputProcessor\SystemPromptInputProcessor;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\Content\Image;
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
        private readonly ?PlatformInterface $platform = null,
        private readonly ?LocalImageAttachmentResolver $imageAttachmentResolver = null,
    ) {
    }

    public function request(string $prompt): CodexResponse
    {
        $model = $this->config->model();

        if ($model === null) {
            throw MissingModel::forEnvVar($this->config->modelEnvVar());
        }

        $resolvedModel = $this->modelNameParser->parse($model);
        $resolvedAuth = $this->authResolver->resolve($this->config);
        $platform = $this->platform ?? $this->createPlatform($resolvedModel->provider(), $resolvedAuth);
        $attachments = ($this->imageAttachmentResolver ?? new LocalImageAttachmentResolver($this->config->workingDirectory()))
            ->detectFromPrompt($prompt);

        if ($attachments !== [] && !$platform->getModelCatalog()->getModel($resolvedModel->model())->supports(Capability::INPUT_IMAGE)) {
            throw ModelDoesNotSupportImageInput::forModel($resolvedModel->qualifiedName());
        }

        $toolbox = new SymfonyAiToolbox($this->toolRegistry);
        $agentProcessor = new AgentProcessor($toolbox);
        $agent = new Agent(
            $platform,
            $resolvedModel->model(),
            [
                new SystemPromptInputProcessor(($this->systemPromptBuilder ?? new DefaultSystemPromptBuilder($this->config, $this->toolRegistry))->build(), $toolbox),
                $agentProcessor,
            ],
            [$agentProcessor],
        );

        $messageContents = [$prompt];
        array_push(
            $messageContents,
            ...array_map(
                static fn (LocalImageAttachment $attachment): Image => $attachment->asImageContent(),
                $attachments,
            ),
        );

        $result = $agent->call(
            new MessageBag(Message::ofUser(...$messageContents)),
            $this->buildRequestOptions($resolvedModel->provider(), $resolvedAuth),
        );

        $response = $this->responseMapper->map($resolvedModel->qualifiedName(), $result);
        $metadata = $response->metadata();

        if ($attachments !== []) {
            $metadata['attached_images'] = array_map(
                static fn (LocalImageAttachment $attachment): array => $attachment->toMetadata(),
                $attachments,
            );
        }

        return new CodexResponse(
            $response->content(),
            $response->model(),
            $response->toolCalls(),
            $metadata,
        );
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
