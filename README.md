# armin/codex-php

`armin/codex-php` is a small extensible Codex client for PHP. It ships with built-in tools for reading files, writing files, and running local commands, while keeping the tool system open for consuming applications to register additional actions at runtime.

## Requirements

- PHP `^8.4`
- Composer

## Installation

```bash
composer require armin/codex-php symfony/http-client symfony/finder symfony/ai
```

## Configuration

The package reads the API key from `CODEX_API_KEY` by default. The CLI reads its default model from `CODEX_DEFAULT_MODEL`.

```bash
export CODEX_API_KEY=your-key
export CODEX_DEFAULT_MODEL=gpt-5
```

If you need a different environment variable name, provide it through `CodexConfig`.

## Usage

```php
<?php

use Armin\CodexPhp\CodexClient;
use Armin\CodexPhp\CodexConfig;

$client = new CodexClient(new CodexConfig());

$file = $client->runTool('read_file', [
    'path' => __FILE__,
]);

$command = $client->runTool('run_command', [
    'command' => ['pwd'],
]);
```

## Register a custom tool

```php
<?php

use Armin\CodexPhp\Tool\ToolInterface;
use Armin\CodexPhp\Tool\ToolResult;

final class EchoTool implements ToolInterface
{
    public function name(): string
    {
        return 'echo';
    }

    public function execute(array $input): ToolResult
    {
        return ToolResult::success([
            'message' => $input['message'] ?? '',
        ]);
    }
}

$client->registerTool(new EchoTool());
```

## Built-in tools

- `read_file`
- `write_file`
- `run_command`

## CLI

The package ships with a Composer binary:

```bash
vendor/bin/codex "Explain this repository"
```

Inside DDEV you can use:

```bash
ddev exec php vendor/bin/codex "Explain this repository"
```

CLI options:

- `--model` overrides `CODEX_DEFAULT_MODEL`
- `--key` overrides `CODEX_API_KEY`

The current CLI mode is non-interactive and returns a JSON simulation payload. It does not yet send a real request to OpenAI/Codex.

## Notes

- Authentication is intentionally out of scope for this package.
- `symfony/http-client` and `symfony/ai` are included as foundation dependencies for future remote Codex integrations.
