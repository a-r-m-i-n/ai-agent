<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Internal\Provider;

use Armin\CodexPhp\Internal\Provider\OpenAiCodexStream;
use Armin\CodexPhp\Internal\Provider\OpenAiTokenResultConverter;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiTokenResultConverterTest extends TestCase
{
    public function testConvertedStreamResultContainsFinalResponseMetadata(): void
    {
        $converter = new OpenAiTokenResultConverter();
        $body = <<<TEXT
event: response.created
data: {"type":"response.created"}

event: response.output_text.delta
data: {"type":"response.output_text.delta","delta":"Hallo"}

event: response.completed
data: {"type":"response.completed","response":{"id":"resp_123","output":[],"usage":{"input_tokens":4,"output_tokens":1,"total_tokens":5}}}

TEXT;
        $httpClient = new MockHttpClient([
            new MockResponse($body, ['http_code' => 200]),
        ]);
        $rawResult = new RawHttpResult($httpClient->request('POST', 'https://chatgpt.com/backend-api/codex/responses'), new OpenAiCodexStream());

        $result = $converter->convert($rawResult, ['stream' => true]);

        self::assertSame([
            'id' => 'resp_123',
            'output' => [],
            'usage' => [
                'input_tokens' => 4,
                'output_tokens' => 1,
                'total_tokens' => 5,
            ],
        ], $result->getMetadata()->all()['final_response']);
        self::assertSame([
            ['type' => 'response.created'],
            ['type' => 'response.output_text.delta', 'delta' => 'Hallo'],
            [
                'type' => 'response.completed',
                'response' => [
                    'id' => 'resp_123',
                    'output' => [],
                    'usage' => [
                        'input_tokens' => 4,
                        'output_tokens' => 1,
                        'total_tokens' => 5,
                    ],
                ],
            ],
        ], $result->getMetadata()->all()['stream_events']);
    }

    public function testStreamFunctionCallEventsProduceToolCallCompleteDelta(): void
    {
        $converter = new OpenAiTokenResultConverter();
        $body = <<<TEXT
event: response.created
data: {"type":"response.created"}

event: response.output_item.done
data: {"type":"response.output_item.done","item":{"id":"fc_123","type":"function_call","status":"completed","arguments":"{\"path\":\"composer.json\"}","call_id":"call_123","name":"read_file"}}

event: response.completed
data: {"type":"response.completed","response":{"id":"resp_123","output":[]}}

TEXT;
        $httpClient = new MockHttpClient([
            new MockResponse($body, ['http_code' => 200]),
        ]);
        $rawResult = new RawHttpResult($httpClient->request('POST', 'https://chatgpt.com/backend-api/codex/responses'), new OpenAiCodexStream());

        $result = $converter->convert($rawResult, ['stream' => true]);

        self::assertInstanceOf(StreamResult::class, $result);

        $toolCallDeltas = [];
        foreach ($result->getContent() as $delta) {
            if ($delta instanceof ToolCallComplete) {
                $toolCallDeltas[] = $delta;
            }
        }

        self::assertCount(1, $toolCallDeltas);
        self::assertSame('call_123', $toolCallDeltas[0]->getToolCalls()[0]->getId());
        self::assertSame('read_file', $toolCallDeltas[0]->getToolCalls()[0]->getName());
        self::assertSame(['path' => 'composer.json'], $toolCallDeltas[0]->getToolCalls()[0]->getArguments());
    }

    public function testBadRequestIncludesErrorDetailsFromResponseBody(): void
    {
        $converter = new OpenAiTokenResultConverter();
        $httpClient = new MockHttpClient([
            new MockResponse(
                json_encode([
                    'error' => [
                        'message' => 'Unsupported parameter.',
                        'type' => 'invalid_request_error',
                        'param' => 'tool_choice',
                        'code' => 'unsupported_parameter',
                    ],
                ], JSON_THROW_ON_ERROR),
                ['http_code' => 400],
            ),
        ]);
        $rawResult = new RawHttpResult($httpClient->request('POST', 'https://chatgpt.com/backend-api/codex/responses'));

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Unsupported parameter.');
        $this->expectExceptionMessage('invalid_request_error');
        $this->expectExceptionMessage('tool_choice');
        $this->expectExceptionMessage('unsupported_parameter');

        $converter->convert($rawResult);
    }
}
