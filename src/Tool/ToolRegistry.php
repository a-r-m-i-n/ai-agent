<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tool;

use Armin\CodexPhp\Exception\ToolNotFound;

final class ToolRegistry
{
    /**
     * @var array<string, ToolInterface>
     */
    private array $tools = [];

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
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
}
