# AGENTS

## Purpose

This repository contains the `armin/codex-php` Composer package. It provides a small extensible Codex client for PHP with built-in tools for file IO and local command execution.

## Setup

- PHP target: `^8.4`
- Main package name: `armin/codex-php`
- Default API key env var: `CODEX_API_KEY`
- Default CLI model env var: `CODEX_DEFAULT_MODEL`
- Run Composer commands only through DDEV, for example `ddev exec composer install`

## Main entry points

- `src/CodexClient.php` is the public entry point
- `src/Console/` contains the Composer binary command setup
- `src/Tool/ToolInterface.php` defines custom tool integration
- `src/Tool/Builtin/` contains built-in tools

## Working conventions

- Authentication is not implemented in this repository
- New actions should be added through the tool registry API
- Keep tool inputs and outputs simple and consistent
- The CLI currently validates config and prints JSON, but does not call OpenAI yet
- Commands like `composer` and `php` must always be executed through `ddev exec`
- Do not run Git commands in parallel, to avoid conflicts with the `.git/index.lock` file
- If a Git command fails because of `.git/index.lock`, wait one second and try again
- Commit messages must be in English and start with one of: `[FEATURE]`, `[TASK]`, `[BUGFIX]`
- Keep the commit title short and meaningful
- AI-generated commit messages must end with `(commit message made with AI)`, in a separate line

## Verification

- Run `ddev exec composer install`
- Run `ddev exec composer test`
- Use `ddev exec php` for all PHP commands without exception
- Run `ddev exec php vendor/bin/phpunit` for the test suite
- Run `ddev exec bin/codex "Prompt" --model=openai:gpt-5.4-mini --auth-file=./auth.json` to smoke-test the CLI with a real API request
- The repository already contains an `auth.json` file that can be used for real API requests during local smoke tests
