# AGENTS

## Purpose

This repository contains the `armin/codex-php` Composer package. It provides a small extensible Codex client for PHP with built-in tools for file IO and local command execution.

## Setup

- PHP target: `^8.4`
- Main package name: `armin/codex-php`
- Default API key env var: `CODEX_API_KEY`
- Default CLI model env var: `CODEX_DEFAULT_MODEL`
- Install dependencies with Composer before running tests

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
- Commit messages must be in English and start with one of: `[FEATURE]`, `[TASK]`, `[BUGFIX]`
- Keep the commit title short and meaningful
- AI-generated commit messages must end with `(commit message made with AI)`

## Verification

- Run `composer install`
- Run `composer test`
- Use `ddev exec php` when PHP commands need to run inside the DDEV environment
- Run `ddev exec php vendor/bin/phpunit` for the test suite
- Run `ddev exec php vendor/bin/codex "Prompt" --model=... --key=...` to smoke-test the CLI
