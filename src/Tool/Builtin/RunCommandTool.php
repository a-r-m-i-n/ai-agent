<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tool\Builtin;

use Armin\CodexPhp\Exception\InvalidToolInput;
use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolDescriptionInterface;
use Armin\CodexPhp\Tool\SchemaAwareToolInterface;
use Armin\CodexPhp\Tool\ToolResult;

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
            ],
            'required' => ['command'],
        ];
    }

    public function description(): string
    {
        return 'Runs a local command with arguments and can use a configured working directory when no explicit cwd is provided.';
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
