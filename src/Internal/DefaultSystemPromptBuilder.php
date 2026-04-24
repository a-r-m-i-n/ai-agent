<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

use Armin\CodexPhp\CodexConfig;
use Armin\CodexPhp\Tool\ToolRegistry;

final class DefaultSystemPromptBuilder implements SystemPromptBuilderInterface
{
    public function __construct(
        private readonly CodexConfig $config,
        private readonly ToolRegistry $toolRegistry,
    ) {
    }

    public function build(): string
    {
        $customPrompt = $this->config->systemPrompt();

        if ($this->config->systemPromptMode() === 'replace' && $customPrompt !== null) {
            return $customPrompt;
        }

        $sections = array_filter([
            CodexSystemPrompt::base(),
            $this->buildToolsSection(),
            $this->buildAgentsSection(),
            $customPrompt,
        ], static fn (?string $section): bool => $section !== null && $section !== '');

        return implode("\n\n", $sections);
    }

    private function buildToolsSection(): ?string
    {
        $tools = $this->toolRegistry->all();

        if ($tools === []) {
            return null;
        }

        $lines = ['Available tools:'];

        foreach ($tools as $tool) {
            $lines[] = sprintf('- %s: %s', $tool->name(), ToolMetadata::description($tool));
        }

        return implode("\n", $lines);
    }

    private function buildAgentsSection(): ?string
    {
        $workingDirectory = $this->config->workingDirectory();

        if ($workingDirectory === null) {
            return null;
        }

        $path = rtrim($workingDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AGENTS.md';

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if (!is_string($contents) || $contents === '') {
            return null;
        }

        return "Repository instructions:\n" . $contents;
    }
}
