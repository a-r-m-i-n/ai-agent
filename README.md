# armin/codex-php

`armin/codex-php` is a small extensible Codex client for PHP. It ships with built-in tools for reading files, writing files, and running local commands, while keeping the tool system open for consuming applications to register additional actions at runtime.


## Requirements

- PHP `^8.4`
- Composer

## Installation

```bash
composer require armin/codex-php
```

## Configuration

The package reads the API key from `CODEX_API_KEY` by default. The CLI reads its default model from `CODEX_DEFAULT_MODEL`.
Models must be configured as `provider:model`, for example `openai:gpt-5`.

```bash
export CODEX_API_KEY=your-key
export CODEX_DEFAULT_MODEL=openai:gpt-5
```

If you need a different environment variable name, provide it through `CodexConfig`.
You can also set a `workingDirectory` for relative file/command operations and customize how the system prompt is built.

You can also provide auth data as a PHP object or load it from an `auth.json` file:

```php
<?php

use Armin\CodexPhp\Auth\CodexAuth;
use Armin\CodexPhp\CodexClient;
use Armin\CodexPhp\CodexConfig;

$client = new CodexClient(new CodexConfig(
    auth: CodexAuth::fromFile(__DIR__ . '/auth.json'),
));
```

You can configure the runtime context directly in PHP:

```php
<?php

use Armin\CodexPhp\CodexClient;
use Armin\CodexPhp\CodexConfig;

$client = new CodexClient(new CodexConfig(
    workingDirectory: __DIR__,
    systemPrompt: 'Prefer short explanations and always mention potential risks.',
    systemPromptMode: 'append', // or "replace"
));
```

When `workingDirectory` is set, the built-in file tools resolve relative paths against it, `run_command` uses it as the default `cwd`, and `AGENTS.md` from that directory is automatically appended to the generated system prompt when present.

The auth payload must contain either `api_key` or `tokens`. If both are missing, the package throws an error.
`auth_mode` must be either `api_key` or `tokens`.

With `auth_mode=api_key`, requests behave exactly like the existing API-key flow.
With `auth_mode=tokens`, the package sends `Authorization: Bearer <access_token>` instead of provider API-key headers.
For `openai:*`, token mode additionally sends `ChatGPT-Account-ID: <account_id>` and `User-Agent: codex-cli/0.124.0`, and uses `https://chatgpt.com/backend-api/codex/responses`.

## Usage

```php
<?php

use Armin\CodexPhp\CodexClient;
use Armin\CodexPhp\CodexConfig;

$client = new CodexClient(new CodexConfig());

$response = $client->request('Summarize this package and mention the built-in tools.');

echo $response->content();
```

## Register and replace tools

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

If a custom tool should be explained clearly to the model, implement `Armin\CodexPhp\Tool\ToolDescriptionInterface` as well. That description is used both for the generated system prompt and for the provider tool definition.

Built-in tools are registered by default through the `ToolRegistry`. You can remove them and replace them with your own implementations:

```php
<?php

$client->unregisterTool('read_file');
$client->registerTool(new CustomReadFileTool());
```

If you want to start without built-in tools at all, disable them in the client constructor:

```php
<?php

$client = new CodexClient(registerBuiltins: false);
```

## Built-in tools

- `read_file`
- `write_file`
- `run_command`

These tools are available both through `runTool()` and as callable tools during model execution.
Their descriptions are also included automatically in the generated system prompt.
They are part of the default `ToolRegistry`, so applications can unregister individual built-ins or override them with tools using the same name.

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
- `--auth-file` loads credentials from an `auth.json` file

The CLI sends a real request through Symfony AI and prints a JSON payload with the final content, tool calls, and response metadata.

Example with `auth.json`:

```bash
vendor/bin/codex "Summarize this repository" --model=openai:gpt-5.4-nano --auth-file=./auth.json
```

Example `auth.json`:

```json
{
  "auth_mode": "tokens",
  "api_key": null,
  "tokens": {
    "id_token": "abc",
    "access_token": "def",
    "refresh_token": "ghi",
    "account_id": "zzz"
  },
  "last_refresh": "2026-04-08T13:44:58.467138412Z"
}
```

Supported providers:

- `openai`
- `anthropic`
- `gemini`

Examples with low-cost current models:

```bash
vendor/bin/codex "Summarize this repository" --model=openai:gpt-5.4-nano --key=your-openai-key
vendor/bin/codex "Summarize this repository" --model=anthropic:claude-3-5-haiku-20241022 --key=your-anthropic-key
vendor/bin/codex "Summarize this repository" --model=gemini:gemini-2.5-flash-lite --key=your-gemini-key
```

## Notes

- Authentication is intentionally out of scope for this package.
- Requests are executed through Symfony AI provider bridges for OpenAI, Anthropic, and Gemini.
- The current CLI mode is non-interactive and single-turn.
