<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Internal\Provider;

use Armin\CodexPhp\Internal\Provider\AnthropicTokenModelClient;
use Armin\CodexPhp\Internal\Provider\GeminiTokenModelClient;
use Armin\CodexPhp\Internal\Provider\OpenAiTokenModelClient;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\Gemini\Gemini;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TokenModelClientTest extends TestCase
{
    public function testOpenAiTokenClientUsesCodexEndpointAndHeaders(): void
    {
        $capturedResponse = null;
        $capturedOptions = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedResponse, &$capturedOptions) {
            $capturedOptions = $options;
            $capturedResponse = new MockResponse(json_encode(['output' => []], JSON_THROW_ON_ERROR), ['http_code' => 200]);

            return $capturedResponse;
        });

        $client = new OpenAiTokenModelClient($httpClient, 'token-123', 'account-456');
        $client->request(new ResponsesModel('gpt-5', []), ['input' => []]);

        self::assertInstanceOf(MockResponse::class, $capturedResponse);
        self::assertSame('https://chatgpt.com/backend-api/codex/responses', $capturedResponse->getRequestUrl());
        self::assertSame('Authorization: Bearer token-123', $capturedResponse->getRequestOptions()['normalized_headers']['authorization'][0]);
        self::assertSame('ChatGPT-Account-ID: account-456', $capturedResponse->getRequestOptions()['normalized_headers']['chatgpt-account-id'][0]);
        self::assertSame('User-Agent: codex-cli/0.124.0', $capturedResponse->getRequestOptions()['normalized_headers']['user-agent'][0]);
        self::assertIsArray($capturedOptions);
        self::assertStringContainsString('"store":false', $capturedOptions['body']);
        self::assertStringContainsString('"stream":true', $capturedOptions['body']);
    }

    public function testOpenAiTokenClientMapsStructuredResponseFormat(): void
    {
        $capturedOptions = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions) {
            $capturedOptions = $options;

            return new MockResponse(json_encode(['output' => []], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $client = new OpenAiTokenModelClient($httpClient, 'token-123', 'account-456');
        $client->request(new ResponsesModel('gpt-5', []), ['input' => []], [
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'TestDto',
                    'schema' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]],
                ],
            ],
            'stream' => false,
        ]);

        self::assertIsArray($capturedOptions);
        self::assertStringContainsString('"text":{"format":{"name":"TestDto","schema":{"type":"object","properties":{"message":{"type":"string"}}},"type":"json_schema"}}', $capturedOptions['body']);
    }

    public function testAnthropicTokenClientUsesBearerHeader(): void
    {
        $capturedResponse = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedResponse) {
            $capturedResponse = new MockResponse('{}', ['http_code' => 200]);

            return $capturedResponse;
        });

        $client = new AnthropicTokenModelClient($httpClient, 'token-123');
        $client->request(new Claude('claude-3-5-haiku-20241022'), ['messages' => []]);

        self::assertInstanceOf(MockResponse::class, $capturedResponse);
        self::assertSame('https://api.anthropic.com/v1/messages', $capturedResponse->getRequestUrl());
        self::assertSame('Authorization: Bearer token-123', $capturedResponse->getRequestOptions()['normalized_headers']['authorization'][0]);
        self::assertArrayNotHasKey('x-api-key', $capturedResponse->getRequestOptions()['normalized_headers']);
    }

    public function testAnthropicTokenClientMapsStructuredResponseFormat(): void
    {
        $capturedOptions = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions) {
            $capturedOptions = $options;

            return new MockResponse('{}', ['http_code' => 200]);
        });

        $client = new AnthropicTokenModelClient($httpClient, 'token-123');
        $client->request(new Claude('claude-3-5-haiku-20241022'), ['messages' => []], [
            'response_format' => [
                'json_schema' => [
                    'schema' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]],
                ],
            ],
        ]);

        self::assertIsArray($capturedOptions);
        self::assertStringContainsString('"output_config":{"format":{"type":"json_schema","schema":{"type":"object","properties":{"message":{"type":"string"}}}}}', $capturedOptions['body']);
        self::assertStringNotContainsString('"response_format"', $capturedOptions['body']);
    }

    public function testGeminiTokenClientUsesBearerHeader(): void
    {
        $capturedResponse = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedResponse) {
            $capturedResponse = new MockResponse('{}', ['http_code' => 200]);

            return $capturedResponse;
        });

        $client = new GeminiTokenModelClient($httpClient, 'token-123');
        $client->request(new Gemini('gemini-2.5-flash-lite'), ['contents' => []]);

        self::assertInstanceOf(MockResponse::class, $capturedResponse);
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent', $capturedResponse->getRequestUrl());
        self::assertSame('Authorization: Bearer token-123', $capturedResponse->getRequestOptions()['normalized_headers']['authorization'][0]);
        self::assertArrayNotHasKey('x-goog-api-key', $capturedResponse->getRequestOptions()['normalized_headers']);
    }

    public function testGeminiTokenClientMapsStructuredResponseFormat(): void
    {
        $capturedOptions = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions) {
            $capturedOptions = $options;

            return new MockResponse('{}', ['http_code' => 200]);
        });

        $client = new GeminiTokenModelClient($httpClient, 'token-123');
        $client->request(new Gemini('gemini-2.5-flash-lite'), ['contents' => []], [
            'response_format' => [
                'json_schema' => [
                    'schema' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]],
                ],
            ],
        ]);

        self::assertIsArray($capturedOptions);
        self::assertStringContainsString('"generationConfig":{"responseMimeType":"application\\/json","responseJsonSchema":{"type":"object","properties":{"message":{"type":"string"}}}}', $capturedOptions['body']);
        self::assertStringNotContainsString('"response_format"', $capturedOptions['body']);
    }
}
