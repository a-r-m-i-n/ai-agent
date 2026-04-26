# armin/ai-agent

`armin/ai-agent` is a generic AI code assistant client for PHP. It ships with built-in tools for reading files, finding files, writing files, viewing images, generating images, and running local commands, while keeping the tool system open for consuming applications to register additional actions at runtime.

## Requirements

- PHP `^8.4`
- Composer

## Installation

```bash
composer require armin/ai-agent
```

## Quick Configuration

The package reads the API key from `AI_AGENT_API_KEY` by default. The CLI reads its default model from `AI_AGENT_DEFAULT_MODEL`. Models must be configured as `provider:model`, for example `openai:gpt-5`.

The environment variable names are exposed as constants on `Armin\AiAgent\AiAgentConfig`:

- `AiAgentConfig::API_KEY_ENV_VAR`
- `AiAgentConfig::MODEL_ENV_VAR`

```bash
export AI_AGENT_API_KEY=your-key
export AI_AGENT_DEFAULT_MODEL=openai:gpt-5
```

## Documentation

- [PHP usage](docs/php-usage.md)
- [CLI usage](docs/cli-usage.md)

## Supported Providers

- `openai`
- `anthropic`
- `gemini`

## Notes

- Authentication is intentionally out of scope for this package.
- Requests are executed through Symfony AI provider bridges.
- The CLI mode is non-interactive. Use a session file if you want multi-turn history between invocations.
