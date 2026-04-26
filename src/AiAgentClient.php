<?php

declare(strict_types=1);

namespace Armin\AiAgent;

use Armin\AiAgent\Auth\AgentAuth;
use Armin\AiAgent\Exception\ToolNotFound;
use Armin\AiAgent\Internal\AiAgentRuntimeInterface;
use Armin\AiAgent\Internal\AiAgentTokenUsageExtractor;
use Armin\AiAgent\Internal\DefaultSystemPromptBuilder;
use Armin\AiAgent\Internal\Session\AgentSessionStore;
use Armin\AiAgent\Internal\SystemPromptBuilderInterface;
use Armin\AiAgent\Internal\SymfonyAiAgentRuntime;
use Armin\AiAgent\Tool\ToolInterface;
use Armin\AiAgent\Tool\ToolRegistry;
use Armin\AiAgent\Tool\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AiAgentClient
{
    private readonly ToolRegistry $toolRegistry;
    private readonly AiAgentRuntimeInterface $runtime;
    private readonly AiAgentTokenUsageExtractor $tokenUsageExtractor;
    private ?AiAgentResponse $lastResponse = null;

    public function __construct(
        private readonly AiAgentConfig $config = new AiAgentConfig(),
        ?ToolRegistry $toolRegistry = null,
        bool $registerBuiltins = true,
        ?HttpClientInterface $httpClient = null,
        ?AiAgentRuntimeInterface $runtime = null,
        ?SystemPromptBuilderInterface $systemPromptBuilder = null,
    ) {
        $this->toolRegistry = $toolRegistry ?? ($registerBuiltins
            ? ToolRegistry::withBuiltins($this->config->workingDirectory())
            : new ToolRegistry());

        if ($toolRegistry instanceof ToolRegistry && $registerBuiltins) {
            $this->toolRegistry->registerBuiltins($this->config->workingDirectory());
        }

        $this->tokenUsageExtractor = new AiAgentTokenUsageExtractor();

        $this->runtime = $runtime ?? new SymfonyAiAgentRuntime(
            $this->config,
            $this->toolRegistry,
            $httpClient,
            systemPromptBuilder: $systemPromptBuilder ?? new DefaultSystemPromptBuilder($this->config, $this->toolRegistry),
        );
    }

    public function config(): AiAgentConfig
    {
        return $this->config;
    }

    public function apiKey(): ?string
    {
        return $this->config->apiKey();
    }

    public function auth(): ?AgentAuth
    {
        return $this->config->auth();
    }

    public function registerTool(ToolInterface $tool): self
    {
        $this->toolRegistry->register($tool);

        return $this;
    }

    public function unregisterTool(string $name): self
    {
        $this->toolRegistry->unregister($name);

        return $this;
    }

    public function hasTool(string $name): bool
    {
        return $this->toolRegistry->has($name);
    }

    public function request(string $prompt, ?string $responseClass = null): AiAgentResponse
    {
        return $this->lastResponse = $this->runtime->request($prompt, $responseClass);
    }

    public function requestText(string $prompt, ?string $responseClass = null): string
    {
        return $this->request($prompt, $responseClass)->content();
    }

    /**
     * @template TObject of object
     *
     * @param class-string<TObject> $responseClass
     *
     * @return TObject
     */
    public function requestStructured(string $prompt, string $responseClass): object
    {
        /** @var TObject */
        return $this->runtime->requestStructured($prompt, $responseClass);
    }

    /**
     * @throws ToolNotFound
     */
    public function runTool(string $name, array $input = []): ToolResult
    {
        return $this->toolRegistry->get($name)->execute($input);
    }

    /**
     * @return array<string, ToolInterface>
     */
    public function tools(): array
    {
        return $this->toolRegistry->all();
    }

    public function getRequestTokens(): AiAgentTokenUsage
    {
        if (!$this->lastResponse instanceof AiAgentResponse) {
            return new AiAgentTokenUsage();
        }

        return $this->tokenUsageExtractor->fromResponse($this->lastResponse);
    }

    public function getSessionTokens(): AiAgentTokenUsage
    {
        $sessionFile = $this->config->sessionFile();

        if ($sessionFile === null) {
            return new AiAgentTokenUsage();
        }

        $store = new AgentSessionStore($sessionFile);

        if (!$store->exists()) {
            return new AiAgentTokenUsage();
        }

        return $this->tokenUsageExtractor->fromSession($store->load());
    }
}
