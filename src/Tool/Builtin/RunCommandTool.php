<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tool\Builtin;

use Armin\CodexPhp\Exception\InvalidToolInput;
use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolDescriptionInterface;
use Armin\CodexPhp\Tool\SchemaAwareToolInterface;
use Armin\CodexPhp\Tool\ToolResult;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class RunCommandTool extends AbstractTool implements ToolInterface, SchemaAwareToolInterface, ToolDescriptionInterface
{
    public function name(): string
    {
        return 'run_command';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'command' => [
                    'type' => 'array',
                    'description' => 'Command and arguments as separate array items.',
                    'items' => ['type' => 'string'],
                    'minItems' => 1,
                ],
                'cwd' => [
                    'type' => 'string',
                    'description' => 'Working directory for the command. Defaults to the current working directory.',
                ],
                'timeout' => [
                    'type' => 'number',
                    'description' => 'Maximum runtime in seconds. When omitted, the process timeout is disabled.',
                    'exclusiveMinimum' => 0,
                ],
            ],
            'required' => ['command'],
        ];
    }

    public function description(): string
    {
        return 'Runs a local command with arguments, can use a configured working directory when no explicit cwd is provided, and accepts an optional timeout in seconds.';
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

        $workingDirectory = $input['cwd'] ?? $this->defaultWorkingDirectory() ?? getcwd();

        if (!is_string($workingDirectory) || $workingDirectory === '') {
            throw new InvalidToolInput('The "cwd" input must be a non-empty string when provided.');
        }

        if (!isset($input['cwd']) && $this->defaultWorkingDirectory() !== null) {
            $workingDirectory = $this->defaultWorkingDirectory();
        }

        $timeout = $input['timeout'] ?? null;

        if ($timeout !== null && !is_int($timeout) && !is_float($timeout)) {
            throw new InvalidToolInput('The "timeout" input must be a positive number when provided.');
        }

        if (is_int($timeout) || is_float($timeout)) {
            if ($timeout <= 0) {
                throw new InvalidToolInput('The "timeout" input must be a positive number when provided.');
            }
        }

        $process = new Process($command, $workingDirectory);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return ToolResult::failure([
                'command' => $command,
                'cwd' => $workingDirectory,
                'stdout' => $process->getOutput(),
                'stderr' => $process->getErrorOutput(),
                'exit_code' => $process->getExitCode(),
                'timed_out' => true,
                'timeout' => $timeout,
                'error' => 'Process timed out.',
            ]);
        }

        return ToolResult::success([
            'command' => $command,
            'cwd' => $workingDirectory,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exit_code' => $process->getExitCode(),
            'timed_out' => false,
            'timeout' => $timeout,
        ]);
    }
}
