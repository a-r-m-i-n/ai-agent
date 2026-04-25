<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tool;

use Armin\CodexPhp\Tool\Builtin\ApplyPatchTool;
use Armin\CodexPhp\Tool\Builtin\ReadFileTool;
use Armin\CodexPhp\Tool\Builtin\SearchTool;
use Armin\CodexPhp\Tool\Builtin\ShellTool;
use Armin\CodexPhp\Exception\ToolNotFound;

final class ToolRegistry
{
    /**
     * @var array<string, ToolInterface>
     */
    private array $tools = [];

    public static function withBuiltins(?string $workingDirectory = null): self
    {
        $registry = new self();
        $registry->registerBuiltins($workingDirectory);

        return $registry;
    }

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function unregister(string $name): void
    {
        unset($this->tools[$name]);
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * @throws ToolNotFound
     */
    public function get(string $name): ToolInterface
    {
        return $this->tools[$name] ?? throw ToolNotFound::forName($name);
    }

    /**
     * @return array<string, ToolInterface>
     */
    public function all(): array
    {
        return $this->tools;
    }

    public function registerBuiltins(?string $workingDirectory = null): void
    {
        $this->register(new ApplyPatchTool($workingDirectory));
        $this->register(new ReadFileTool($workingDirectory));
        $this->register(new SearchTool($workingDirectory));
        $this->register(new ShellTool($workingDirectory));
    }
}
