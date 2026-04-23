<?php

declare(strict_types=1);

namespace Armin\CodexPhp;

use Armin\CodexPhp\Exception\ToolNotFound;
use Armin\CodexPhp\Tool\Builtin\ReadFileTool;
use Armin\CodexPhp\Tool\Builtin\RunCommandTool;
use Armin\CodexPhp\Tool\Builtin\WriteFileTool;
use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolRegistry;
use Armin\CodexPhp\Tool\ToolResult;

final class CodexClient
{
    private readonly ToolRegistry $toolRegistry;

    public function __construct(
        private readonly CodexConfig $config = new CodexConfig(),
        ?ToolRegistry $toolRegistry = null,
        bool $registerBuiltins = true,
    ) {
        $this->toolRegistry = $toolRegistry ?? new ToolRegistry();

        if ($registerBuiltins) {
            $this->registerBuiltins();
        }
    }

    public function config(): CodexConfig
    {
        return $this->config;
    }

    public function apiKey(): ?string
    {
        return $this->config->apiKey();
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
        $this->registerTool(new ReadFileTool());
        $this->registerTool(new WriteFileTool());
        $this->registerTool(new RunCommandTool());
    }
}
