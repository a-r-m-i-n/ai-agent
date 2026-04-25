<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

use Armin\CodexPhp\Auth\CodexAuth;
use Armin\CodexPhp\CodexConfig;
use Armin\CodexPhp\CodexResponse;
use Armin\CodexPhp\Exception\ModelDoesNotSupportImageInput;
use Armin\CodexPhp\Exception\MissingModel;
use Armin\CodexPhp\Internal\Provider\TokenPlatformFactory;
use Armin\CodexPhp\Internal\Session\CodexSession;
use Armin\CodexPhp\Internal\Session\CodexSessionStore;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Gemini\Factory as GeminiFactory;
use Armin\CodexPhp\Tool\ToolRegistry;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;
use Symfony\AI\Platform\StructuredOutput\Serializer;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\AI\Platform\Result\ToolCall;

final class SymfonyAiCodexRuntime implements CodexRuntimeInterface
{
    private const MAX_TOOL_ITERATIONS = 100;

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

    public function request(string $prompt, ?string $responseClass = null): CodexResponse
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
        $sessionStore = $this->createSessionStore();
        $session = $sessionStore?->load() ?? new CodexSession();
        $loadedMessageCount = $session->count();

        if ($attachments !== [] && !$platform->getModelCatalog()->getModel($resolvedModel->model())->supports(Capability::INPUT_IMAGE)) {
            throw ModelDoesNotSupportImageInput::forModel($resolvedModel->qualifiedName());
        }

        $toolbox = new SymfonyAiToolbox($this->toolRegistry);
        $imageGenerator = new ImageGenerator($this->config->workingDirectory(), $this->httpClient, $resolvedAuth);
        $systemPrompt = ($this->systemPromptBuilder ?? new DefaultSystemPromptBuilder($this->config, $this->toolRegistry))->build();
        $messageBag = $this->createMessageBagFromSession(
            $session,
            $systemPrompt,
        );
        $replayedMessageCount = \count($messageBag->getMessages()) - 1;
        $messageContents = [$prompt];
        array_push(
            $messageContents,
            ...array_map(
                static fn (LocalImageAttachment $attachment): Image => $attachment->asImageContent(),
                $attachments,
            ),
        );
        $messageBag->add(Message::ofUser(...$messageContents));
        $modelSupportsImageInput = $platform->getModelCatalog()->getModel($resolvedModel->model())->supports(Capability::INPUT_IMAGE);
        $requestOptions = $this->buildRequestOptions($resolvedModel->provider(), $resolvedAuth, $platform, $resolvedModel->model(), $responseClass);
        $requestOptions['tools'] = $toolbox->getTools();
        $attachedImagesMetadata = array_map(
            static fn (LocalImageAttachment $attachment): array => $attachment->toMetadata(),
            $attachments,
        );
        $generatedImagesMetadata = [];
        $requestAssistantMessages = [];
        $response = null;

        if ($sessionStore instanceof CodexSessionStore) {
            $session->appendUserMessage($prompt);
            $this->persistSession($sessionStore, $session);
        }

        for ($iteration = 0; ; ++$iteration) {
            $response = $this->callModel($platform, $resolvedModel->model(), $resolvedModel->qualifiedName(), $messageBag, $requestOptions);

            if ($sessionStore instanceof CodexSessionStore && $response->toolCalls() !== []) {
                $session->appendAssistantMessage($response->content(), $response->toolCalls(), $response->metadata());
                $this->persistSession($sessionStore, $session);
            }

            if ($response->toolCalls() !== []) {
                $requestAssistantMessages[] = [
                    'content' => $response->content(),
                    'tool_calls' => $response->toolCalls(),
                    'metadata' => $response->metadata(),
                ];
            }

            if ($response->toolCalls() === []) {
                break;
            }

            if ($iteration >= self::MAX_TOOL_ITERATIONS) {
                throw new \RuntimeException(sprintf('Maximum tool iterations exceeded (%d).', self::MAX_TOOL_ITERATIONS));
            }

            $assistantToolCalls = array_map(
                static fn (array $toolCall, int $toolIndex): ToolCall => new ToolCall(
                    isset($toolCall['id']) && is_string($toolCall['id']) ? $toolCall['id'] : sprintf('runtime-%d-%d', $iteration, $toolIndex),
                    $toolCall['name'],
                    $toolCall['arguments'],
                ),
                $response->toolCalls(),
                array_keys($response->toolCalls()),
            );
            $messageBag->add(Message::ofAssistant($response->content(), $assistantToolCalls));

            $nextStepImages = [];

            foreach ($assistantToolCalls as $toolCall) {
                $toolResult = $this->executeToolCall($toolCall, $platform, $resolvedModel->provider(), $resolvedModel->model(), $imageGenerator);
                $messageBag->add(Message::ofToolCall($toolCall, $toolResult['message']));
                if ($sessionStore instanceof CodexSessionStore) {
                    $session->appendToolMessage($toolResult['message'], $toolCall->getId());
                    $this->persistSession($sessionStore, $session);
                }

                if ($toolResult['attachment'] instanceof LocalImageAttachment) {
                    $nextStepImages[] = $toolResult['attachment']->asImageContent();
                    $attachedImagesMetadata[] = $toolResult['attachment']->toMetadata();
                }

                if ($toolResult['generated_image'] instanceof GeneratedImage) {
                    $generatedImagesMetadata[] = $toolResult['generated_image']->toMetadata();
                }
            }

            if ($nextStepImages !== []) {
                if (!$modelSupportsImageInput) {
                    throw ModelDoesNotSupportImageInput::forModel($resolvedModel->qualifiedName());
                }

                $messageBag->add(Message::ofUser(
                    $prompt,
                    ...$nextStepImages,
                ));
            }
        }

        if (!$response instanceof CodexResponse) {
            throw new \RuntimeException('The model did not return a response.');
        }

        $metadata = $response->metadata();
        $metadata['system_prompt'] = $systemPrompt;

        if ($attachedImagesMetadata !== []) {
            $metadata['attached_images'] = $attachedImagesMetadata;
        }

        if ($generatedImagesMetadata !== []) {
            $metadata['generated_images'] = $generatedImagesMetadata;
        }

        if ($requestAssistantMessages !== []) {
            $metadata['request_assistant_messages'] = $requestAssistantMessages;
        }

        if ($sessionStore instanceof CodexSessionStore) {
            $session->appendAssistantMessage($response->content(), $response->toolCalls(), $metadata);
            $this->persistSession($sessionStore, $session);

            $metadata['session'] = [
                'file' => $sessionStore->path(),
                'loaded_messages' => $loadedMessageCount,
                'replayed_messages' => $replayedMessageCount,
                'stored_messages' => $session->count(),
            ];
        }

        return new CodexResponse(
            $response->content(),
            $response->model(),
            $response->toolCalls(),
            $metadata,
        );
    }

    public function requestStructured(string $prompt, string $responseClass): object
    {
        $response = $this->request($prompt, $responseClass);

        return (new Serializer())->deserialize($response->content(), $responseClass, 'json');
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
    private function buildRequestOptions(
        string $provider,
        ResolvedAuth $auth,
        PlatformInterface $platform,
        string $model,
        ?string $responseClass = null,
    ): array
    {
        $options = [];

        if ($provider === 'openai' && $auth->mode() === CodexAuth::MODE_TOKENS) {
            $options['stream'] = true;
        }

        if ($responseClass === null) {
            return $options;
        }

        if (!class_exists($responseClass)) {
            throw new \InvalidArgumentException(sprintf('Structured response class "%s" does not exist.', $responseClass));
        }

        if (!$platform->getModelCatalog()->getModel($model)->supports(Capability::OUTPUT_STRUCTURED)) {
            throw new \RuntimeException(sprintf('Model "%s" does not support structured output.', $model));
        }

        $options['response_format'] = (new ResponseFormatFactory())->create($responseClass);
        $options['stream'] = false;

        return $options;
    }

    private function createSessionStore(): ?CodexSessionStore
    {
        $sessionFile = $this->config->sessionFile();

        if ($sessionFile === null) {
            return null;
        }

        return new CodexSessionStore($sessionFile);
    }

    private function persistSession(CodexSessionStore $sessionStore, CodexSession $session): void
    {
        $sessionStore->save($session);
    }

    private function createMessageBagFromSession(CodexSession $session, string $systemPrompt): MessageBag
    {
        $messageBag = new MessageBag(Message::forSystem($systemPrompt));

        foreach ($session->replayMessages() as $messageIndex => $message) {
            if ('user' === $message['role']) {
                $messageBag->add(Message::ofUser($message['content']));
                continue;
            }

            if ('tool' === $message['role']) {
                $messageBag->add(new ToolCallMessage(
                    new ToolCall($message['tool_call_id'], 'session_tool_output', []),
                    $message['content'],
                ));
                continue;
            }

            $messageBag->add(Message::ofAssistant(
                $message['content'],
                array_map(
                    static fn (array $toolCall, int $toolIndex): ToolCall => new ToolCall(
                        isset($toolCall['id']) && is_string($toolCall['id']) ? $toolCall['id'] : sprintf('session-%d-%d', $messageIndex, $toolIndex),
                        $toolCall['name'],
                        $toolCall['arguments'],
                    ),
                    $message['tool_calls'] ?? [],
                    array_keys($message['tool_calls'] ?? []),
                ),
            ));
        }

        return $messageBag;
    }

    /**
     * @param array<string, mixed> $requestOptions
     */
    private function callModel(
        PlatformInterface $platform,
        string $model,
        string $qualifiedModel,
        MessageBag $messageBag,
        array $requestOptions,
    ): CodexResponse {
        return $this->responseMapper->map(
            $qualifiedModel,
            $platform->invoke($model, $messageBag, $requestOptions)->getResult(),
        );
    }

    /**
     * @return array{message: string, attachment: ?LocalImageAttachment, generated_image: ?GeneratedImage}
     */
    private function executeToolCall(
        ToolCall $toolCall,
        PlatformInterface $platform,
        string $provider,
        string $model,
        ImageGenerator $imageGenerator,
    ): array
    {
        try {
            $attachment = null;
            $generatedImage = null;

            if ($toolCall->getName() === 'generate_image') {
                $generatedImage = $imageGenerator->generate($platform, $provider, $model, $toolCall->getArguments());
                $payload = [
                    'success' => true,
                    'payload' => $generatedImage->toMetadata(),
                ];
            } else {
                $result = $this->toolRegistry->get($toolCall->getName())->execute($toolCall->getArguments());
                $payload = [
                    'success' => $result->isSuccess(),
                    'payload' => $result->payload(),
                ];
            }

            if (
                $toolCall->getName() === 'view_image'
                && isset($result)
                && $result->isSuccess()
                && isset($result->payload()['path'])
                && is_string($result->payload()['path'])
            ) {
                $attachment = ($this->imageAttachmentResolver ?? new LocalImageAttachmentResolver($this->config->workingDirectory()))
                    ->resolve($result->payload()['path']);
            }
        } catch (\Throwable $e) {
            if ($toolCall->getName() === 'generate_image') {
                throw $e;
            }

            $payload = [
                'success' => false,
                'payload' => [
                    'error' => $e->getMessage(),
                ],
            ];
            $attachment = null;
            $generatedImage = null;
        }

        try {
            $message = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $message = '{"success":false,"payload":{"error":"Tool result could not be encoded as JSON."}}';
            $attachment = null;
        }

        return [
            'message' => $message,
            'attachment' => $attachment,
            'generated_image' => $generatedImage,
        ];
    }
}
