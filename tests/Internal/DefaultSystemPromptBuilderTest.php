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
use Symfony\Component\Process\Process;

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
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($this->tempDirectory);
    }

    public function testBuildReturnsBasePromptWhenNoExtrasAreConfigured(): void
    {
        $builder = new DefaultSystemPromptBuilder(new CodexConfig(), new ToolRegistry());

        $prompt = $builder->build();

        self::assertStringContainsString('You are an **AI Code Assistant**, a pragmatic coding agent focused on real work inside the user\'s current environment.', $prompt);
        self::assertStringContainsString('# Working Style', $prompt);
        self::assertStringNotContainsString('You are Codex, a pragmatic coding assistant.', $prompt);
        self::assertStringNotContainsString('Available tools:', $prompt);
        self::assertStringNotContainsString('Repository context:', $prompt);
        self::assertStringNotContainsString('Repository instructions:', $prompt);
    }

    public function testBuildIncludesToolDescriptionsAgentsFileAndRepositoryContext(): void
    {
        file_put_contents($this->tempDirectory . '/AGENTS.md', "# Repo Rules\nUse tests.\n");
        file_put_contents($this->tempDirectory . '/.gitignore', "ignored.txt\nignored-dir/\n");
        file_put_contents($this->tempDirectory . '/README.md', '# Read me');
        file_put_contents($this->tempDirectory . '/composer.json', json_encode([
            'name' => 'acme/example',
            'require' => ['php' => '^8.4'],
            'scripts' => ['test' => 'phpunit'],
            'bin' => ['bin/codex'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->tempDirectory . '/phpunit.xml.dist', '<phpunit/>');
        mkdir($this->tempDirectory . '/.ddev', 0777, true);
        mkdir($this->tempDirectory . '/bin', 0777, true);
        mkdir($this->tempDirectory . '/src', 0777, true);
        mkdir($this->tempDirectory . '/src/Nested', 0777, true);
        file_put_contents($this->tempDirectory . '/visible.txt', 'visible');
        file_put_contents($this->tempDirectory . '/ignored.txt', 'hidden');
        mkdir($this->tempDirectory . '/ignored-dir', 0777, true);

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
        self::assertStringContainsString('Prefer read_file for targeted inspection, search for discovery, apply_patch for edits, and shell for local command execution.', $prompt);
        self::assertStringContainsString('Repository context:', $prompt);
        self::assertStringContainsString('Working directory: ' . $this->tempDirectory, $prompt);
        self::assertStringContainsString('Root files: .gitignore, AGENTS.md, README.md, composer.json, phpunit.xml.dist, visible.txt', $prompt);
        self::assertStringContainsString('Directories (depth <= 2): bin, src, src/Nested', $prompt);
        self::assertStringContainsString('DDEV is configured here. Default to running project commands via `ddev exec`.', $prompt);
        self::assertStringContainsString('Composer manifest detected for a PHP project; package `acme/example`; PHP ^8.4; binaries: bin/codex; scripts: test.', $prompt);
        self::assertStringContainsString('PHPUnit configuration is present.', $prompt);
        self::assertStringNotContainsString('ignored.txt', $prompt);
        self::assertStringNotContainsString('ignored-dir', $prompt);
        self::assertStringContainsString('Repository instructions:', $prompt);
        self::assertStringContainsString('# Repo Rules', $prompt);
    }

    public function testBuildIgnoresDirectoryPatternsWithoutTrailingSlash(): void
    {
        file_put_contents($this->tempDirectory . '/.gitignore', "/vendor\n");
        mkdir($this->tempDirectory . '/src', 0777, true);
        mkdir($this->tempDirectory . '/vendor/bin', 0777, true);

        $builder = new DefaultSystemPromptBuilder(new CodexConfig(workingDirectory: $this->tempDirectory), new ToolRegistry());
        $prompt = $builder->build();

        self::assertStringContainsString('Directories (depth <= 2): src', $prompt);
        self::assertStringNotContainsString('vendor', $prompt);
        self::assertStringNotContainsString('vendor/bin', $prompt);
    }

    public function testBuildOmitsGitDirectoryAndAddsGitSummary(): void
    {
        mkdir($this->tempDirectory . '/src', 0777, true);
        file_put_contents($this->tempDirectory . '/src/example.php', '<?php');

        $this->runGit($this->tempDirectory, ['git', 'init', '-b', 'main']);
        $this->runGit($this->tempDirectory, ['git', 'config', 'user.name', 'Codex Tests']);
        $this->runGit($this->tempDirectory, ['git', 'config', 'user.email', 'codex@example.invalid']);
        $this->runGit($this->tempDirectory, ['git', 'add', 'src/example.php']);

        $builder = new DefaultSystemPromptBuilder(new CodexConfig(workingDirectory: $this->tempDirectory), new ToolRegistry());
        $prompt = $builder->build();

        self::assertStringContainsString('Directories (depth <= 2): src', $prompt);
        self::assertStringNotContainsString('.git,', $prompt);
        self::assertStringNotContainsString('.git/', $prompt);
        self::assertStringContainsString('Git repository detected; current branch `main`; staged uncommitted changes: yes.', $prompt);
    }

    public function testBuildOmitsDdevDirectoryButKeepsDdevHint(): void
    {
        mkdir($this->tempDirectory . '/.ddev/commands', 0777, true);
        mkdir($this->tempDirectory . '/src', 0777, true);

        $builder = new DefaultSystemPromptBuilder(new CodexConfig(workingDirectory: $this->tempDirectory), new ToolRegistry());
        $prompt = $builder->build();

        self::assertStringContainsString('Directories (depth <= 2): src', $prompt);
        self::assertStringNotContainsString('.ddev', $prompt);
        self::assertStringContainsString('DDEV is configured here. Default to running project commands via `ddev exec`.', $prompt);
    }

    public function testBuildAppendsCustomPromptByDefault(): void
    {
        $builder = new DefaultSystemPromptBuilder(new CodexConfig(systemPrompt: 'Always answer in JSON.'), new ToolRegistry());

        $prompt = $builder->build();

        self::assertStringContainsString('You are an **AI Code Assistant**, a pragmatic coding agent focused on real work inside the user\'s current environment.', $prompt);
        self::assertStringContainsString('Always answer in JSON.', $prompt);
    }

    public function testBuildCanReplaceGeneratedPrompt(): void
    {
        $builder = new DefaultSystemPromptBuilder(new CodexConfig(systemPrompt: 'Only this prompt.', systemPromptMode: 'replace'), new ToolRegistry());

        self::assertSame('Only this prompt.', $builder->build());
    }

    /**
     * @param list<string> $command
     */
    private function runGit(string $workingDirectory, array $command): void
    {
        $process = new Process($command, $workingDirectory);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }
}
