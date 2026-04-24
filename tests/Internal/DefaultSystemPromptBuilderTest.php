<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Internal;

use Armin\CodexPhp\CodexConfig;
use Armin\CodexPhp\Internal\DefaultSystemPromptBuilder;
use Armin\CodexPhp\Tool\ToolDescriptionInterface;
use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolRegistry;
use Armin\CodexPhp\Tool\ToolResult;
use PHPUnit\Framework\TestCase;

final class DefaultSystemPromptBuilderTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . '/codex-php-prompt-' . bin2hex(random_bytes(4));
        mkdir($this->tempDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $agentsFile = $this->tempDirectory . '/AGENTS.md';
        if (is_file($agentsFile)) {
            unlink($agentsFile);
        }

        @rmdir($this->tempDirectory);
    }

    public function testBuildReturnsBasePromptWhenNoExtrasAreConfigured(): void
    {
        $builder = new DefaultSystemPromptBuilder(new CodexConfig(), new ToolRegistry());

        $prompt = $builder->build();

        self::assertStringContainsString('You are Codex, a pragmatic coding assistant.', $prompt);
        self::assertStringNotContainsString('Available tools:', $prompt);
        self::assertStringNotContainsString('Repository instructions:', $prompt);
    }

    public function testBuildIncludesToolDescriptionsAndAgentsFile(): void
    {
        file_put_contents($this->tempDirectory . '/AGENTS.md', "# Repo Rules\nUse tests.\n");

        $registry = new ToolRegistry();
        $registry->register(new class implements ToolInterface, ToolDescriptionInterface {
            public function name(): string
            {
                return 'deploy';
            }

            public function description(): string
            {
                return 'Deploys the application to the configured environment.';
            }

            public function execute(array $input): ToolResult
            {
                return ToolResult::success($input);
            }
        });

        $builder = new DefaultSystemPromptBuilder(new CodexConfig(workingDirectory: $this->tempDirectory), $registry);
        $prompt = $builder->build();

        self::assertStringContainsString('Available tools:', $prompt);
        self::assertStringContainsString('- deploy: Deploys the application to the configured environment.', $prompt);
        self::assertStringContainsString('Repository instructions:', $prompt);
        self::assertStringContainsString('# Repo Rules', $prompt);
    }

    public function testBuildAppendsCustomPromptByDefault(): void
    {
        $builder = new DefaultSystemPromptBuilder(new CodexConfig(systemPrompt: 'Always answer in JSON.'), new ToolRegistry());

        $prompt = $builder->build();

        self::assertStringContainsString('You are Codex, a pragmatic coding assistant.', $prompt);
        self::assertStringContainsString('Always answer in JSON.', $prompt);
    }

    public function testBuildCanReplaceGeneratedPrompt(): void
    {
        $builder = new DefaultSystemPromptBuilder(new CodexConfig(systemPrompt: 'Only this prompt.', systemPromptMode: 'replace'), new ToolRegistry());

        self::assertSame('Only this prompt.', $builder->build());
    }
}
