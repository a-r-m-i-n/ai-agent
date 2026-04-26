# PHP Usage

## Basic Usage

```php
<?php

use Armin\AiAgent\AiAgentClient;
use Armin\AiAgent\AiAgentConfig;

$client = new AiAgentClient(new AiAgentConfig());

$response = $client->request('Summarize this package and mention the built-in tools.');

echo $response->content();
```

## Configuration

The package reads the API key from `AI_AGENT_API_KEY` by default. Models must be configured as `provider:model`, for example `openai:gpt-5`.

You can also provide auth data as a PHP object or load it from an `auth.json` file:

```php
<?php

use Armin\AiAgent\Auth\AgentAuth;
use Armin\AiAgent\AiAgentClient;
use Armin\AiAgent\AiAgentConfig;

$client = new AiAgentClient(new AiAgentConfig(
    auth: AgentAuth::fromFile(__DIR__ . '/auth.json'),
));
```

You can configure the runtime context directly in PHP:

```php
<?php

use Armin\AiAgent\AiAgentClient;
use Armin\AiAgent\AiAgentConfig;

$client = new AiAgentClient(new AiAgentConfig(
    session: __DIR__ . '/var/ai-agent-session.json',
    workingDirectory: __DIR__,
    systemPrompt: 'Prefer short explanations and always mention potential risks.',
    systemPromptMode: 'append', // or "replace"
));
```

You can override model and API key directly on the `AiAgentConfig` object from PHP:

```php
<?php

use Armin\AiAgent\AiAgentClient;
use Armin\AiAgent\AiAgentConfig;

$config = new AiAgentConfig();
$config
    ->setModel('openai:gpt-5.4-mini')
    ->setApiKey('your-key')
    ->setSession(__DIR__ . '/var/ai-agent-session.json');

$client = new AiAgentClient($config);
```

When `workingDirectory` is set, the built-in file tools resolve relative paths against it, `run_command` uses it as the default `cwd`, and `AGENTS.md` from that directory is automatically appended to the generated system prompt when present.

## Auth Payloads

The auth payload must contain either `api_key` or `tokens`. If both are missing, the package throws an error. `auth_mode` must be either `api_key` or `tokens`.

With `auth_mode=api_key`, requests behave exactly like the existing API-key flow.

With `auth_mode=tokens`, the package sends `Authorization: Bearer <access_token>` instead of provider API-key headers. For `openai:*`, token mode additionally sends `ChatGPT-Account-ID: <account_id>` and `User-Agent: codex-cli/0.124.0`, and uses `https://chatgpt.com/backend-api/codex/responses`.

## Structured Output

You can constrain the model output to a strict JSON Schema derived from a DTO class. When you pass a response class to `request()` or `requestText()`, the final content still stays a JSON string:

```php
<?php

final readonly class PackageSummary
{
    /**
     * @param list<string> $tools
     */
    public function __construct(
        public string $summary,
        public array $tools,
    ) {
    }
}

$jsonResponse = $client->request(
    'Summarize this package as JSON with summary and tools.',
    PackageSummary::class,
);

echo $jsonResponse->content();
```

If you want a hydrated DTO object instead of raw JSON text, use `requestStructured()`:

```php
<?php

final readonly class PackageSummary
{
    /**
     * @param list<string> $tools
     */
    public function __construct(
        public string $summary,
        public array $tools,
    ) {
    }
}

$summary = $client->requestStructured(
    'Summarize this package as JSON with summary and tools.',
    PackageSummary::class,
);

echo $summary->summary;
print_r($summary->tools);
```

Structured output currently supports DTO classes only. If the class does not exist, the request fails fast. If the selected model does not support Symfony AI `Capability::OUTPUT_STRUCTURED`, the runtime throws a clear exception instead of silently falling back to plain text.

## Image Generation

Image generation also goes through the same `request()` API. If hosted image generation is enabled and the provider supports it, the runtime can ask the provider to create or transform an image, stores the file locally, and still returns a normal text response:

```php
<?php

$response = $client->request('Create a product mockup image for a citrus soda can and save it as can-mockup.');

echo $response->content();
print_r($response->generatedImages());
```

Generated images are stored in the configured `workingDirectory`. If no `workingDirectory` is configured, the current `getcwd()` is used.

When no explicit filename is provided, the runtime creates one like `generated_image_<hash>.<ext>`. If the target filename has no extension, the provider output format is kept.

For image transformations, the runtime can attach local image files as real multimodal inputs when the selected model supports image input. If the prompt names a target filename and an input image is attached, the generated file is stored next to that input image by default.

## Sessions and Token Usage

To continue a multi-turn exchange, reuse the same session value:

```php
<?php

$client = new AiAgentClient(new AiAgentConfig(
    session: __DIR__ . '/var/ai-agent-session.json',
));

$client->request('Summarize this package.');
$followUp = $client->request('Now give me the answer as three bullet points.');
```

If `session` points to an existing readable file, the runtime loads and persists that file. Otherwise, the string is treated as inline serialized session JSON. The same versioned JSON format with a `messages` list is used in both modes.

For follow-up requests, only the model-relevant conversation parts are replayed from that archive: message roles, text content, and assistant tool calls.

Existing session history is loaded before each request, and the current user prompt plus the final assistant response are appended only after a successful request.

This makes it possible to store session state outside the package, for example in a database, and pass the serialized JSON back through `session`.

You can inspect token usage for the last successful request directly on the client. The returned `Armin\AiAgent\AiAgentTokenUsage` object keeps normal response usage and image-generation usage separate:

```php
<?php

$client = new AiAgentClient(new AiAgentConfig(
    session: __DIR__ . '/var/ai-agent-session.json',
));

$response = $client->request('Summarize this package.');
$requestTokens = $client->getRequestTokens();
$sessionTokens = $client->getSessionTokens();

print_r($requestTokens->toArray());
print_r($sessionTokens->toArray());
```

`getRequestTokens()` reports the aggregated usage of the last successful `request()` call on the current `AiAgentClient` instance, including intermediate model steps caused by tool calls.

`getSessionTokens()` loads the configured `session` source and aggregates all archived assistant responses from it.

If no request has been executed yet, or no session is configured, both methods return an empty usage object with all counters set to `0`.

The `AiAgentTokenUsage` payload contains these stable keys:

- `input`
- `cached_input`
- `output`
- `reasoning`
- `total`
- `image_generation_input`
- `image_generation_output`
- `image_generation_total`

## Register and Replace Tools

```php
<?php

use Armin\AiAgent\Tool\ToolInterface;
use Armin\AiAgent\Tool\ToolResult;

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

If a custom tool should be explained clearly to the model, implement `Armin\AiAgent\Tool\ToolDescriptionInterface` as well. That description is used both for the generated system prompt and for the provider tool definition.

Built-in tools are registered by default through the `ToolRegistry`. You can remove them and replace them with your own implementations:

```php
<?php

$client->unregisterTool('read_file');
$client->registerTool(new CustomReadFileTool());
```

If you want to start without built-in tools at all, disable them in the client constructor:

```php
<?php

$client = new AiAgentClient(registerBuiltins: false);
```

## Built-in Tools

- `read_file`
- `find_files`
- `write_file`
- `view_image`
- `run_command`

These tools are available both through `runTool()` and as callable tools during model execution. Their descriptions are also included automatically in the generated system prompt.

They are part of the default `ToolRegistry`, so applications can unregister individual built-ins or override them with tools using the same name.
