# CLI Usage

## Running the CLI

The package ships with a Composer binary:

```bash
vendor/bin/codex "Explain this repository"
```

Inside DDEV you can use:

```bash
ddev exec php vendor/bin/codex "Explain this repository"
```

## Configuration

The CLI reads the API key from `CODEX_API_KEY` and its default model from `CODEX_DEFAULT_MODEL`.

Models must be configured as `provider:model`, for example `openai:gpt-5`.

CLI options:

- `--model` overrides `CODEX_DEFAULT_MODEL`
- `--key` overrides `CODEX_API_KEY`
- `--auth-file` loads credentials from an `auth.json` file
- `--session-file` persists and reloads conversation history from a JSON file
- `--debug` outputs JSON with prompt, content, metadata, and tool calls
- `--debug-all` outputs the final provider response together with parsed stream events when available

Internally, the CLI applies `--model` and `--key` by updating the `CodexConfig` instance before the request is executed.

## Output Behavior

By default, the CLI prints the final response content as plain text.

The CLI sends a real request through Symfony AI. When you use `--debug` or `--debug-all`, it prints JSON payloads instead of plain-text content.

The CLI mode is non-interactive. Use `--session-file` if you want multi-turn history between invocations.

## Examples

Example with `auth.json`:

```bash
vendor/bin/codex "Summarize this repository" --model=openai:gpt-5.4-nano --auth-file=./auth.json
```

Example with a reusable session file:

```bash
vendor/bin/codex "Summarize this repository" --model=openai:gpt-5.4-mini --auth-file=./auth.json --session-file=.codex-session.json
vendor/bin/codex "Now answer in German" --model=openai:gpt-5.4-mini --auth-file=./auth.json --session-file=.codex-session.json
```

Examples with supported providers:

```bash
vendor/bin/codex "Summarize this repository" --model=openai:gpt-5.4-nano --key=your-openai-key
vendor/bin/codex "Summarize this repository" --model=anthropic:claude-3-5-haiku-20241022 --key=your-anthropic-key
vendor/bin/codex "Summarize this repository" --model=gemini:gemini-2.5-flash-lite --key=your-gemini-key
```

## auth.json Example

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

## Supported Providers

- `openai`
- `anthropic`
- `gemini`
