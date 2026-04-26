<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal\Provider;

use Symfony\AI\Platform\Result\Stream\HttpStreamInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class OpenAiResponsesStream implements HttpStreamInterface
{
    public function stream(ResponseInterface $response): iterable
    {
        $buffer = trim($response->getContent(false));
        if ($buffer === '') {
            return;
        }

        $frames = preg_split("/\r\n\r\n|\n\n|\r\r/", $buffer);
        if (!is_array($frames)) {
            return;
        }

        foreach ($frames as $frame) {
            $data = $this->extractData($frame);
            if ($data === null || $data === '' || $data === '[DONE]') {
                continue;
            }

            yield json_decode($data, true, flags: \JSON_THROW_ON_ERROR);
        }
    }

    private function extractData(string $frame): ?string
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($frame));
        if (!is_array($lines)) {
            return null;
        }

        $dataLines = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }

        if ($dataLines === []) {
            return null;
        }

        return implode("\n", $dataLines);
    }
}
