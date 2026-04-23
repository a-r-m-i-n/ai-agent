<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Internal\Provider;

use Armin\CodexPhp\Internal\Provider\OpenAiCodexStream;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiCodexStreamTest extends TestCase
{
    public function testStreamParsesEventAndDataFramesWithoutEventStreamContentType(): void
    {
        $body = (function (): \Generator {
            yield "event: response.created\n";
            yield "data: {\"type\":\"response.created\"}\n\n";
            yield "event: response.output_text.delta\n";
            yield "data: {\"type\":\"response.output_text.delta\",\"delta\":\"Hallo\"}\n\n";
        })();

        $httpClient = new MockHttpClient([
            new MockResponse($body, ['http_code' => 200]),
        ]);

        $response = $httpClient->request('POST', 'https://chatgpt.com/backend-api/codex/responses');
        $events = iterator_to_array((new OpenAiCodexStream())->stream($response));

        self::assertCount(2, $events);
        self::assertSame('response.created', $events[0]['type']);
        self::assertSame('response.output_text.delta', $events[1]['type']);
        self::assertSame('Hallo', $events[1]['delta']);
    }
}
