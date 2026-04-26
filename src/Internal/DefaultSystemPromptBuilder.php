<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

use Armin\CodexPhp\CodexConfig;
use Armin\CodexPhp\Tool\ToolRegistry;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

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
            $this->buildRepositoryContextSection(),
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

        $lines[] = '- Prefer read_file for targeted inspection, search for discovery, apply_patch for edits, and shell for local command execution.';

        return implode("\n", $lines);
    }

    private function buildRepositoryContextSection(): ?string
    {
        $workingDirectory = $this->config->workingDirectory();

        if ($workingDirectory === null || !is_dir($workingDirectory)) {
            return null;
        }

        $lines = ['Repository context:'];
        $lines[] = '- Working directory: ' . $workingDirectory;
        $rootFiles = $this->listRootFiles($workingDirectory);
        $directories = $this->listDirectories($workingDirectory);
        $hints = $this->buildEnvironmentHints($workingDirectory);

        if ($rootFiles !== []) {
            $lines[] = '- Root files: ' . implode(', ', $rootFiles);
        }

        if ($directories !== []) {
            $lines[] = '- Directories (depth <= 2): ' . implode(', ', $directories);
        }

        foreach ($hints as $hint) {
            $lines[] = '- ' . $hint;
        }

        return \count($lines) > 1 ? implode("\n", $lines) : null;
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

    /**
     * @return list<string>
     */
    private function listRootFiles(string $workingDirectory): array
    {
        $finder = new Finder();
        $finder->files()->ignoreDotFiles(false)->ignoreVCS(false)->depth('== 0')->in($workingDirectory)->sortByName();
        $ignoredPatterns = $this->loadGitignorePatterns($workingDirectory);

        $files = [];
        foreach ($finder as $file) {
            $relativePath = $file->getRelativePathname();
            if ($this->isIgnoredByGitignore($relativePath, $ignoredPatterns)) {
                continue;
            }

            $files[] = $relativePath;
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function listDirectories(string $workingDirectory): array
    {
        $finder = new Finder();
        $finder->directories()->ignoreDotFiles(false)->ignoreVCS(false)->depth('<= 1')->in($workingDirectory)->sortByName();
        $ignoredPatterns = $this->loadGitignorePatterns($workingDirectory);

        $directories = [];
        foreach ($finder as $directory) {
            $relativePath = $directory->getRelativePathname();
            if (
                $relativePath === '.git'
                || str_starts_with($relativePath, '.git/')
                || $relativePath === '.ddev'
                || str_starts_with($relativePath, '.ddev/')
            ) {
                continue;
            }

            if ($this->isIgnoredByGitignore($relativePath, $ignoredPatterns)) {
                continue;
            }

            $directories[] = $relativePath;
        }

        return $directories;
    }

    /**
     * @return list<string>
     */
    private function buildEnvironmentHints(string $workingDirectory): array
    {
        $hints = [];

        if (is_dir($workingDirectory . '/.ddev')) {
            $hints[] = 'DDEV is configured here. Default to running project commands via `ddev exec`.';
        }

        if (
            is_file($workingDirectory . '/docker-compose.yml')
            || is_file($workingDirectory . '/docker-compose.yaml')
            || is_file($workingDirectory . '/Dockerfile')
        ) {
            $hints[] = 'Docker configuration is present. Expect container-based workflows and services.';
        }

        if (is_file($workingDirectory . '/composer.json')) {
            $summary = $this->summarizeComposerManifest($workingDirectory . '/composer.json');
            if ($summary !== null) {
                $hints[] = $summary;
            }
        }

        if (is_file($workingDirectory . '/package.json')) {
            $summary = $this->summarizePackageManifest($workingDirectory . '/package.json');
            if ($summary !== null) {
                $hints[] = $summary;
            }
        }

        if (is_dir($workingDirectory . '/.github/workflows')) {
            $hints[] = 'GitHub Actions workflows are present. CI expectations likely live in `.github/workflows/`.';
        }

        if (is_file($workingDirectory . '/bitbucket-pipelines.yml') || is_file($workingDirectory . '/bitbucket-pipelines.yaml')) {
            $hints[] = 'Bitbucket Pipelines is configured for CI/CD.';
        }

        if (is_file($workingDirectory . '/Makefile')) {
            $hints[] = 'A `Makefile` is available. Standard project tasks may be exposed as make targets.';
        }

        if (is_file($workingDirectory . '/justfile')) {
            $hints[] = 'A `justfile` is available. Reusable project commands may be exposed through `just`.';
        }

        if (is_file($workingDirectory . '/Taskfile.yml')) {
            $hints[] = 'A `Taskfile.yml` is available. Task runner commands may be standardized there.';
        }

        if (is_file($workingDirectory . '/.env.example') || is_file($workingDirectory . '/.env.dist')) {
            $hints[] = 'Environment example files are present. Check them before assuming required configuration.';
        }

        if (is_file($workingDirectory . '/phpunit.xml') || is_file($workingDirectory . '/phpunit.xml.dist')) {
            $hints[] = 'PHPUnit configuration is present. Prefer the project test entrypoints over ad-hoc PHP execution.';
        }

        if (is_dir($workingDirectory . '/bin')) {
            $hints[] = 'The repository has a root `bin/` directory with project-local executables.';
        }

        if (is_file($workingDirectory . '/README.md')) {
            $hints[] = 'Review `README.md` for project-specific workflows and conventions in addition to direct repo instructions.';
        }

        $gitSummary = $this->summarizeGitRepository($workingDirectory);
        if ($gitSummary !== null) {
            $hints[] = $gitSummary;
        }

        return $hints;
    }

    private function summarizeGitRepository(string $workingDirectory): ?string
    {
        if (!is_dir($workingDirectory . '/.git')) {
            return null;
        }

        $branch = $this->runGitCommand($workingDirectory, ['git', 'branch', '--show-current']);
        if ($branch === null || $branch === '') {
            $branch = $this->runGitCommand($workingDirectory, ['git', 'rev-parse', '--short', 'HEAD']);
        }

        $branchLabel = $branch !== null && $branch !== '' ? sprintf('current branch `%s`', $branch) : 'current branch unknown';
        $hasStagedChanges = $this->hasStagedChanges($workingDirectory);

        return sprintf(
            'Git repository detected; %s; staged uncommitted changes: %s.',
            $branchLabel,
            $hasStagedChanges ? 'yes' : 'no',
        );
    }

    private function hasStagedChanges(string $workingDirectory): bool
    {
        $process = new Process(['git', 'diff', '--cached', '--quiet', '--exit-code'], $workingDirectory);
        $process->run();

        return $process->getExitCode() === 1;
    }

    private function runGitCommand(string $workingDirectory, array $command): ?string
    {
        $process = new Process($command, $workingDirectory);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());

        return $output !== '' ? $output : null;
    }

    private function summarizeComposerManifest(string $path): ?string
    {
        $payload = $this->readJsonFile($path);

        if ($payload === null) {
            return 'A Composer manifest is present. This is a PHP project.';
        }

        $parts = ['Composer manifest detected for a PHP project'];

        if (isset($payload['name']) && is_string($payload['name'])) {
            $parts[] = sprintf('package `%s`', $payload['name']);
        }

        if (isset($payload['require']['php']) && is_string($payload['require']['php'])) {
            $parts[] = sprintf('PHP %s', $payload['require']['php']);
        }

        if (isset($payload['bin']) && is_array($payload['bin']) && $payload['bin'] !== []) {
            $parts[] = 'binaries: ' . implode(', ', array_filter($payload['bin'], 'is_string'));
        }

        if (isset($payload['scripts']) && is_array($payload['scripts']) && $payload['scripts'] !== []) {
            $parts[] = 'scripts: ' . implode(', ', array_keys($payload['scripts']));
        }

        return implode('; ', $parts) . '.';
    }

    private function summarizePackageManifest(string $path): ?string
    {
        $payload = $this->readJsonFile($path);

        if ($payload === null) {
            return 'A package.json manifest is present. This repository includes Node-based tooling.';
        }

        $parts = ['package.json detected for Node tooling'];

        if (isset($payload['packageManager']) && is_string($payload['packageManager'])) {
            $parts[] = sprintf('package manager `%s`', $payload['packageManager']);
        }

        if (isset($payload['scripts']) && is_array($payload['scripts']) && $payload['scripts'] !== []) {
            $parts[] = 'scripts: ' . implode(', ', array_keys($payload['scripts']));
        }

        return implode('; ', $parts) . '.';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonFile(string $path): ?array
    {
        $contents = @file_get_contents($path);

        if (!is_string($contents) || $contents === '') {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<string>
     */
    private function loadGitignorePatterns(string $workingDirectory): array
    {
        $contents = @file_get_contents($workingDirectory . '/.gitignore');

        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $patterns = [];
        foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $patterns[] = ltrim($line, '/');
        }

        return $patterns;
    }

    /**
     * @param list<string> $patterns
     */
    private function isIgnoredByGitignore(string $relativePath, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '/')) {
                $directory = rtrim($pattern, '/');
                if ($relativePath === $directory || str_starts_with($relativePath, $directory . '/')) {
                    return true;
                }

                continue;
            }

            if (
                !str_contains($pattern, '*')
                && !str_contains($pattern, '?')
                && !str_contains($pattern, '[')
                && str_starts_with($relativePath, $pattern . '/')
            ) {
                return true;
            }

            if ($relativePath === $pattern || fnmatch($pattern, $relativePath)) {
                return true;
            }
        }

        return false;
    }
}
