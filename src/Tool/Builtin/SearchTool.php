<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tool\Builtin;

use Armin\CodexPhp\Exception\InvalidToolInput;
use Armin\CodexPhp\Tool\SchemaAwareToolInterface;
use Armin\CodexPhp\Tool\ToolDescriptionInterface;
use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolResult;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

final class SearchTool extends AbstractTool implements ToolInterface, SchemaAwareToolInterface, ToolDescriptionInterface
{
    private const DEFAULT_MAX_RESULTS = 50;
    private const CONTENTS_MODE_FULL = 'full';
    private const CONTENTS_MODE_HEAD = 'head';
    private const CONTENTS_MODE_TAIL = 'tail';

    public function name(): string
    {
        return 'search';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Absolute or project-relative directory path to search in.',
                ],
                'name_pattern' => [
                    'type' => 'string',
                    'description' => 'Optional Symfony Finder filename pattern such as "*.php".',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional text query to search for inside matching files.',
                ],
                'case_sensitive' => [
                    'type' => 'boolean',
                    'description' => 'Whether text matching should be case-sensitive. Defaults to false.',
                ],
                'max_results' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of result entries to return. Defaults to 50.',
                    'minimum' => 1,
                ],
                'depth' => [
                    'type' => 'integer',
                    'description' => 'Optional maximum search depth relative to the start path, following Symfony Finder depth semantics where 0 means only the start directory itself.',
                    'minimum' => 0,
                ],
                'contents' => [
                    'type' => 'string',
                    'description' => 'Optional file content payload to include for each result: "full", "head:N", or "tail:N".',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function description(): string
    {
        return 'Searches files and file contents with Symfony Finder, supports filename filtering, optional depth limits, optional per-result file contents ("full", "head:N", "tail:N"), and respects .gitignore rules.';
    }

    public function execute(array $input): ToolResult
    {
        $path = $this->resolvePath($this->requireString($input, 'path'));
        $namePattern = $input['name_pattern'] ?? null;
        $query = $input['query'] ?? null;
        $caseSensitive = $input['case_sensitive'] ?? false;
        $maxResults = $input['max_results'] ?? self::DEFAULT_MAX_RESULTS;
        $depth = $input['depth'] ?? null;
        $contents = $input['contents'] ?? null;

        if ($namePattern !== null && (!is_string($namePattern) || $namePattern === '')) {
            throw new InvalidToolInput('The "name_pattern" input must be a non-empty string when provided.');
        }

        if ($query === '') {
            $query = null;
        }

        if ($query !== null && !is_string($query)) {
            throw new InvalidToolInput('The "query" input must be a non-empty string when provided.');
        }

        if (!is_bool($caseSensitive)) {
            throw new InvalidToolInput('The "case_sensitive" input must be a boolean when provided.');
        }

        if (!is_int($maxResults) || $maxResults <= 0) {
            throw new InvalidToolInput('The "max_results" input must be a positive integer when provided.');
        }

        if ($depth !== null && (!is_int($depth) || $depth < 0)) {
            throw new InvalidToolInput('The "depth" input must be a non-negative integer when provided.');
        }

        $contentsMode = $this->parseContentsMode($contents);

        if (!is_dir($path)) {
            return ToolResult::failure([
                'path' => $path,
                'name_pattern' => $namePattern,
                'query' => $query,
                'depth' => $depth,
                'contents' => $contents,
                'results' => [],
                'count' => 0,
                'truncated' => false,
                'error' => 'Directory not found.',
            ]);
        }

        $finder = new Finder();
        $finder->files()->ignoreDotFiles(false)->ignoreVCS(false)->in($path)->sortByName();

        if (is_string($namePattern)) {
            $finder->name($namePattern);
        }

        if (is_int($depth)) {
            $finder->depth('<= ' . $depth);
        }

        $ignoredPatterns = $this->loadGitignorePatterns($path);
        $results = [];
        $totalCount = 0;
        $truncated = false;

        foreach ($finder as $file) {
            $relativePath = $file->getRelativePathname();

            if ($this->isIgnoredByGitignore($relativePath, $ignoredPatterns)) {
                continue;
            }

            if ($query === null) {
                ++$totalCount;
                if (\count($results) < $maxResults) {
                    $result = [
                        'type' => 'file',
                        'path' => $file->getRealPath() ?: $file->getPathname(),
                    ];

                    if ($contentsMode !== null) {
                        $result['contents'] = $this->extractContents($file, $contentsMode);
                    }

                    $results[] = $result;
                } else {
                    $truncated = true;
                }

                continue;
            }

            foreach ($this->findContentMatches($file, $query, $caseSensitive, $contentsMode) as $match) {
                ++$totalCount;
                if (\count($results) < $maxResults) {
                    $results[] = $match;
                } else {
                    $truncated = true;
                }
            }
        }

        return ToolResult::success([
            'path' => $path,
            'name_pattern' => $namePattern,
            'query' => $query,
            'case_sensitive' => $caseSensitive,
            'depth' => $depth,
            'contents' => $contents,
            'results' => $results,
            'count' => $totalCount,
            'truncated' => $truncated,
        ]);
    }

    /**
     * @return list<string>
     */
    private function loadGitignorePatterns(string $path): array
    {
        $gitignorePath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.gitignore';
        $contents = @file_get_contents($gitignorePath);

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

    /**
     * @param array{mode: string, lines?: int}|null $contentsMode
     *
     * @return list<array{type: string, path: string, line: int, snippet: string, contents?: string}>
     */
    private function findContentMatches(SplFileInfo $file, string $query, bool $caseSensitive, ?array $contentsMode): array
    {
        $contents = @file_get_contents($file->getPathname());

        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $lines = $this->splitLines($contents);
        $matches = [];
        $path = $file->getRealPath() ?: $file->getPathname();

        foreach ($lines as $index => $line) {
            $found = $caseSensitive
                ? str_contains($line, $query)
                : stripos($line, $query) !== false;

            if (!$found) {
                continue;
            }

            $match = [
                'type' => 'content',
                'path' => $path,
                'line' => $index + 1,
                'snippet' => $this->buildSnippet($line, $query, $caseSensitive),
            ];

            if ($contentsMode !== null) {
                $match['contents'] = $this->extractContentsFromString($contents, $contentsMode);
            }

            $matches[] = $match;
        }

        return $matches;
    }

    /**
     * @return array{mode: string, lines?: int}|null
     */
    private function parseContentsMode(mixed $contents): ?array
    {
        if ($contents === null) {
            return null;
        }

        if (!is_string($contents) || $contents === '') {
            throw new InvalidToolInput('The "contents" input must be one of "full", "head:N", or "tail:N" when provided.');
        }

        if ($contents === self::CONTENTS_MODE_FULL) {
            return ['mode' => self::CONTENTS_MODE_FULL];
        }

        if (preg_match('/^(head|tail):([1-9]\d*)$/', $contents, $matches) !== 1) {
            throw new InvalidToolInput('The "contents" input must be one of "full", "head:N", or "tail:N" when provided.');
        }

        return [
            'mode' => $matches[1],
            'lines' => (int) $matches[2],
        ];
    }

    /**
     * @param array{mode: string, lines?: int} $contentsMode
     */
    private function extractContents(SplFileInfo $file, array $contentsMode): string
    {
        $contents = @file_get_contents($file->getPathname());

        if (!is_string($contents)) {
            return '';
        }

        return $this->extractContentsFromString($contents, $contentsMode);
    }

    /**
     * @param array{mode: string, lines?: int} $contentsMode
     */
    private function extractContentsFromString(string $contents, array $contentsMode): string
    {
        if ($contentsMode['mode'] === self::CONTENTS_MODE_FULL) {
            return $contents;
        }

        $lines = $this->splitLines($contents);
        $lineCount = $contentsMode['lines'] ?? 0;

        if ($contentsMode['mode'] === self::CONTENTS_MODE_HEAD) {
            return implode("\n", array_slice($lines, 0, $lineCount));
        }

        return implode("\n", array_slice($lines, -$lineCount));
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $contents): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];

        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return array_values($lines);
    }

    private function buildSnippet(string $line, string $query, bool $caseSensitive): string
    {
        $position = $caseSensitive
            ? strpos($line, $query)
            : stripos($line, $query);

        if (!is_int($position)) {
            return trim($line);
        }

        $start = max($position - 40, 0);
        $snippet = substr($line, $start, strlen($query) + 80);
        $snippet = trim($snippet);

        if ($start > 0) {
            $snippet = '...' . $snippet;
        }

        if ($start + strlen(trim($snippet, '.')) < strlen($line)) {
            $snippet .= '...';
        }

        return $snippet;
    }
}
