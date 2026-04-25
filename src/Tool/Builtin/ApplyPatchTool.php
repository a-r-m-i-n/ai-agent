<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tool\Builtin;

use Armin\CodexPhp\Exception\InvalidToolInput;
use Armin\CodexPhp\Tool\SchemaAwareToolInterface;
use Armin\CodexPhp\Tool\ToolDescriptionInterface;
use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolResult;
use Symfony\Component\Process\Process;

final class ApplyPatchTool extends AbstractTool implements ToolInterface, SchemaAwareToolInterface, ToolDescriptionInterface
{
    public function name(): string
    {
        return 'apply_patch';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'patch' => [
                    'type' => 'string',
                    'description' => 'Non-empty unified patch text to validate and apply atomically.',
                ],
            ],
            'required' => ['patch'],
        ];
    }

    public function description(): string
    {
        return 'Validates and applies a unified patch atomically relative to the configured working directory.';
    }

    public function execute(array $input): ToolResult
    {
        $patch = $this->requireString($input, 'patch');
        $workingDirectory = $this->defaultWorkingDirectory() ?? getcwd();

        if (!is_string($workingDirectory) || $workingDirectory === '') {
            throw new InvalidToolInput('The apply_patch tool requires a working directory.');
        }

        [$stripLevel, $touchedFiles, $hunkCount] = $this->inspectPatch($patch);
        $snapshot = $this->snapshotFiles($workingDirectory, $touchedFiles);
        $patchFile = tempnam(sys_get_temp_dir(), 'codex-patch-');

        if ($patchFile === false) {
            return ToolResult::failure([
                'error' => 'Unable to create a temporary patch file.',
            ]);
        }

        file_put_contents($patchFile, $patch);

        try {
            $check = $this->runPatchCommand($workingDirectory, $patchFile, $stripLevel, true);
            if (!$check->isSuccessful()) {
                return ToolResult::failure([
                    'error' => 'Patch validation failed.',
                    'details' => trim($check->getErrorOutput() . "\n" . $check->getOutput()),
                    'files' => $touchedFiles,
                    'file_count' => \count($touchedFiles),
                    'hunk_count' => $hunkCount,
                ]);
            }

            $apply = $this->runPatchCommand($workingDirectory, $patchFile, $stripLevel, false);
            if (!$apply->isSuccessful()) {
                $this->restoreSnapshot($workingDirectory, $snapshot);

                return ToolResult::failure([
                    'error' => 'Patch apply failed and changes were rolled back.',
                    'details' => trim($apply->getErrorOutput() . "\n" . $apply->getOutput()),
                    'files' => $touchedFiles,
                    'file_count' => \count($touchedFiles),
                    'hunk_count' => $hunkCount,
                ]);
            }
        } finally {
            @unlink($patchFile);
        }

        return ToolResult::success([
            'files' => $touchedFiles,
            'file_count' => \count($touchedFiles),
            'hunk_count' => $hunkCount,
        ]);
    }

    /**
     * @return array{0:int, 1:list<string>, 2:int}
     */
    private function inspectPatch(string $patch): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $patch) ?: [];
        $paths = [];
        $hunkCount = 0;
        $stripLevel = 0;

        for ($index = 0; $index < \count($lines); ++$index) {
            $line = $lines[$index];

            if (str_starts_with($line, '@@')) {
                ++$hunkCount;
                continue;
            }

            if (!str_starts_with($line, '--- ')) {
                continue;
            }

            $oldPath = $this->normalizePatchPath(substr($line, 4));
            $nextLine = $lines[$index + 1] ?? null;

            if (!is_string($nextLine) || !str_starts_with($nextLine, '+++ ')) {
                throw new InvalidToolInput('The patch is not a valid unified diff: missing "+++" header after "---".');
            }

            $newPath = $this->normalizePatchPath(substr($nextLine, 4));
            $selectedPath = $newPath !== '/dev/null' ? $newPath : $oldPath;

            if ($selectedPath === '/dev/null' || $selectedPath === '') {
                throw new InvalidToolInput('The patch does not reference a valid file path.');
            }

            if ((str_starts_with($oldPath, 'a/') || str_starts_with($newPath, 'b/')) && $stripLevel < 1) {
                $stripLevel = 1;
            }

            $paths[] = $this->stripPatchPrefixes($selectedPath, $stripLevel);
        }

        $paths = array_values(array_unique($paths));

        if ($paths === [] || $hunkCount === 0) {
            throw new InvalidToolInput('The "patch" input must contain a non-empty unified diff.');
        }

        return [$stripLevel, $paths, $hunkCount];
    }

    private function normalizePatchPath(string $value): string
    {
        $value = trim($value);
        $value = preg_split('/\s+/', $value)[0] ?? '';

        return $value;
    }

    private function stripPatchPrefixes(string $path, int $stripLevel): string
    {
        if ($stripLevel === 1 && (str_starts_with($path, 'a/') || str_starts_with($path, 'b/'))) {
            return substr($path, 2);
        }

        return $path;
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, array{exists: bool, contents: ?string}>
     */
    private function snapshotFiles(string $workingDirectory, array $paths): array
    {
        $snapshot = [];

        foreach ($paths as $path) {
            $absolutePath = rtrim($workingDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
            $exists = is_file($absolutePath);
            $snapshot[$path] = [
                'exists' => $exists,
                'contents' => $exists ? file_get_contents($absolutePath) : null,
            ];
        }

        return $snapshot;
    }

    /**
     * @param array<string, array{exists: bool, contents: ?string}> $snapshot
     */
    private function restoreSnapshot(string $workingDirectory, array $snapshot): void
    {
        foreach ($snapshot as $path => $state) {
            $absolutePath = rtrim($workingDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;

            if ($state['exists']) {
                $directory = dirname($absolutePath);
                if (!is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }
                file_put_contents($absolutePath, $state['contents'] ?? '');
                continue;
            }

            if (file_exists($absolutePath)) {
                @unlink($absolutePath);
            }
        }
    }

    private function runPatchCommand(string $workingDirectory, string $patchFile, int $stripLevel, bool $dryRun): Process
    {
        $command = ['patch', '-p' . $stripLevel, '--directory', $workingDirectory, '--input', $patchFile, '--batch', '--forward'];

        if ($dryRun) {
            $command[] = '--dry-run';
        }

        $process = new Process($command);
        $process->run();

        return $process;
    }
}
