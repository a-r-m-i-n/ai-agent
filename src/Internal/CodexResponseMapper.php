<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

use Armin\CodexPhp\CodexResponse;
use Symfony\AI\Platform\Result\ChoiceResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCallResult;

final class CodexResponseMapper
{
    public function map(string $model, ResultInterface $result): CodexResponse
    {
        $toolCalls = [];
        $content = '';

        foreach ($this->flatten($result) as $part) {
            if ($part instanceof TextResult) {
                $content .= $part->getContent();
                continue;
            }

            if ($part instanceof ToolCallResult) {
                foreach ($part->getContent() as $toolCall) {
                    $toolCalls[] = [
                        'name' => $toolCall->getName(),
                        'arguments' => $toolCall->getArguments(),
                    ];
                }
            }
        }

        return new CodexResponse($content, $model, $toolCalls, $result->getMetadata()->all());
    }

    /**
     * @return list<ResultInterface>
     */
    private function flatten(ResultInterface $result): array
    {
        if ($result instanceof StreamResult) {
            $content = '';

            foreach ($result->getContent() as $delta) {
                if ($delta instanceof TextDelta) {
                    $content .= $delta->getText();
                }
            }

            return [new TextResult($content)];
        }

        if ($result instanceof MultiPartResult || $result instanceof ChoiceResult) {
            $results = [];

            foreach ($result->getContent() as $part) {
                array_push($results, ...$this->flatten($part));
            }

            return $results;
        }

        return [$result];
    }
}
