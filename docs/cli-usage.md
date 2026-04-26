# CLI Usage

## Running the CLI

The package ships with a Composer binary:

```bash
vendor/bin/ai-agent "Explain this repository"
```

Inside DDEV you can use:

```bash
ddev exec php vendor/bin/ai-agent "Explain this repository"
```

## Configuration

The CLI reads the API key from `AI_AGENT_API_KEY` and its default model from `AI_AGENT_DEFAULT_MODEL`.

Models must be configured as `provider:model`, for example `openai:gpt-5`.

CLI options:

- `--model` overrides `AI_AGENT_DEFAULT_MODEL`
- `--key` overrides `AI_AGENT_API_KEY`
- `--auth-file` loads credentials from an `auth.json` file
- `--session` accepts either a reusable session JSON file path or inline serialized session JSON

Internally, the CLI applies `--model` and `--key` by updating the `AiAgentConfig` instance before the request is executed.

If the `prompt` argument consists only of a file path or file name and that file exists, the CLI loads the prompt text from that file. If the file does not exist, or the argument contains additional text, the value is sent as a normal literal prompt.

## Output Behavior

By default, the CLI prints the final response content as plain text.

The CLI sends a real request through Symfony AI.

Use Symfony verbosity flags for diagnostics:

- default: prints only the response content
- `-v`, `-vv`, and `-vvv`: print the final built system prompt, the resolved user prompt, the model output, and a statistics table
- in verbose mode, the sections are labeled as `System prompt:`, `User prompt:`, `Output:`, and `Statistics:`
- the `total` row in the statistics table is highlighted

The CLI mode is non-interactive. Use `--session` if you want multi-turn history between invocations.

## Examples

Example with `auth.json`:

```bash
vendor/bin/ai-agent "Summarize this repository" --model=openai:gpt-5.4-nano --auth-file=./auth.json
```

Example with a reusable session file:

```bash
vendor/bin/ai-agent "Summarize this repository" --model=openai:gpt-5.4-mini --auth-file=./auth.json --session=.ai-agent-session.json
vendor/bin/ai-agent "Now answer in German" --model=openai:gpt-5.4-mini --auth-file=./auth.json --session=.ai-agent-session.json
```

Example with a prompt file:

```bash
vendor/bin/ai-agent prompts/review.txt --model=openai:gpt-5.4-mini --auth-file=./auth.json
```

Examples with supported providers:

```bash
vendor/bin/ai-agent "Summarize this repository" --model=openai:gpt-5.4-nano --key=your-openai-key
vendor/bin/ai-agent "Summarize this repository" --model=anthropic:claude-3-5-haiku-20241022 --key=your-anthropic-key
vendor/bin/ai-agent "Summarize this repository" --model=gemini:gemini-2.5-flash-lite --key=your-gemini-key
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
