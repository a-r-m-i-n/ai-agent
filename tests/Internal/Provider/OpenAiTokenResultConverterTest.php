<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Internal\Provider;

use Armin\CodexPhp\Internal\Provider\OpenAiTokenResultConverter;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiTokenResultConverterTest extends TestCase
{
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
