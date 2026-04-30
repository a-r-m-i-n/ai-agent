<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tests\Internal\Provider;

use Armin\AiAgent\Internal\Provider\OpenAiResponsesStream;
use Armin\AiAgent\Internal\Provider\OpenAiTokenUsageExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiTokenUsageExtractorTest extends TestCase
{
    public function testExtractReadsUsageFromNonStreamingJsonBody(): void
    {
        $extractor = new OpenAiTokenUsageExtractor();
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode([
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 5,
                    'total_tokens' => 15,
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);
        $rawResult = new RawHttpResult($httpClient->request('POST', 'https://chatgpt.com/backend-api/codex/responses'));

        $usage = $extractor->extract($rawResult, ['stream' => false]);

        self::assertNotNull($usage);
        self::assertSame(10, $usage->getPromptTokens());
        self::assertSame(5, $usage->getCompletionTokens());
        self::assertSame(15, $usage->getTotalTokens());
    }

    public function testExtractReadsUsageFromStreamingBodyWhenLogicalStreamIsDisabled(): void
    {
        $extractor = new OpenAiTokenUsageExtractor();
        $body = <<<TEXT
event: response.completed
data: {"type":"response.completed","response":{"id":"resp_123","output":[],"usage":{"input_tokens":4,"output_tokens":1,"total_tokens":5}}}

TEXT;
        $httpClient = new MockHttpClient([
            new MockResponse($body, ['http_code' => 200]),
        ]);
        $rawResult = new RawHttpResult($httpClient->request('POST', 'https://chatgpt.com/backend-api/codex/responses'), new OpenAiResponsesStream());

        $usage = $extractor->extract($rawResult, ['stream' => false]);

        self::assertNotNull($usage);
        self::assertSame(4, $usage->getPromptTokens());
        self::assertSame(1, $usage->getCompletionTokens());
        self::assertSame(5, $usage->getTotalTokens());
    }

    public function testExtractReturnsNullForLogicalStreamingRequests(): void
    {
        $extractor = new OpenAiTokenUsageExtractor();
        $httpClient = new MockHttpClient([
            new MockResponse('{}', ['http_code' => 200]),
        ]);
        $rawResult = new RawHttpResult($httpClient->request('POST', 'https://chatgpt.com/backend-api/codex/responses'));

        self::assertNull($extractor->extract($rawResult, ['stream' => true]));
    }
}
