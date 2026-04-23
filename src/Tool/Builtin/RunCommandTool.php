<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tool\Builtin;

use Armin\CodexPhp\Exception\InvalidToolInput;
use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolResult;

final class RunCommandTool extends AbstractTool implements ToolInterface
{
    public function name(): string
    {
        return 'run_command';
    }

    public function execute(array $input): ToolResult
    {
        $command = $input['command'] ?? null;

        if (!is_array($command) || $command === []) {
            throw new InvalidToolInput('The "command" input must be a non-empty string array.');
        }

        foreach ($command as $part) {
            if (!is_string($part) || $part === '') {
                throw new InvalidToolInput('Each command part must be a non-empty string.');
            }
        }

        $workingDirectory = $input['cwd'] ?? getcwd();

        if (!is_string($workingDirectory) || $workingDirectory === '') {
            throw new InvalidToolInput('The "cwd" input must be a non-empty string when provided.');
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory);

        if (!is_resource($process)) {
            return ToolResult::failure([
                'command' => $command,
                'error' => 'Unable to start command.',
            ]);
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return ToolResult::success([
            'command' => $command,
            'cwd' => $workingDirectory,
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
            'exit_code' => $exitCode,
        ]);
    }
}
