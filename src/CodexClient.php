<?php

declare(strict_types=1);

namespace Armin\CodexPhp;

use Armin\CodexPhp\Auth\CodexAuth;
use Armin\CodexPhp\Exception\ToolNotFound;
use Armin\CodexPhp\Internal\CodexRuntimeInterface;
use Armin\CodexPhp\Internal\DefaultSystemPromptBuilder;
use Armin\CodexPhp\Internal\SystemPromptBuilderInterface;
use Armin\CodexPhp\Internal\SymfonyAiCodexRuntime;
use Armin\CodexPhp\Tool\Builtin\ReadFileTool;
use Armin\CodexPhp\Tool\Builtin\RunCommandTool;
use Armin\CodexPhp\Tool\Builtin\WriteFileTool;
use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolRegistry;
use Armin\CodexPhp\Tool\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CodexClient
{
    private readonly ToolRegistry $toolRegistry;
    private readonly CodexRuntimeInterface $runtime;

    public function __construct(
        private readonly CodexConfig $config = new CodexConfig(),
        ?ToolRegistry $toolRegistry = null,
        bool $registerBuiltins = true,
        ?HttpClientInterface $httpClient = null,
        ?CodexRuntimeInterface $runtime = null,
        ?SystemPromptBuilderInterface $systemPromptBuilder = null,
    ) {
        $this->toolRegistry = $toolRegistry ?? new ToolRegistry();

        if ($registerBuiltins) {
            $this->registerBuiltins();
        }

        $this->runtime = $runtime ?? new SymfonyAiCodexRuntime(
            $this->config,
            $this->toolRegistry,
            $httpClient,
            systemPromptBuilder: $systemPromptBuilder ?? new DefaultSystemPromptBuilder($this->config, $this->toolRegistry),
        );
    }

    public function config(): CodexConfig
    {
        return $this->config;
    }

    public function apiKey(): ?string
    {
        return $this->config->apiKey();
    }

    public function auth(): ?CodexAuth
    {
        return $this->config->auth();
    }

    public function registerTool(ToolInterface $tool): self
    {
        $this->toolRegistry->register($tool);

        return $this;
    }

    public function hasTool(string $name): bool
    {
        return $this->toolRegistry->has($name);
    }

    public function request(string $prompt, ?string $modelOverride = null, ?string $apiKeyOverride = null): CodexResponse
    {
        return $this->runtime->request($prompt, $modelOverride, $apiKeyOverride);
    }

    public function requestText(string $prompt, ?string $modelOverride = null, ?string $apiKeyOverride = null): string
    {
        return $this->request($prompt, $modelOverride, $apiKeyOverride)->content();
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

    private function registerBuiltins(): void
    {
        $this->registerTool(new ReadFileTool($this->config->workingDirectory()));
        $this->registerTool(new WriteFileTool($this->config->workingDirectory()));
        $this->registerTool(new RunCommandTool($this->config->workingDirectory()));
    }
}
