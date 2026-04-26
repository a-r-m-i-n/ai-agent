<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal;

use Armin\AiAgent\AiAgentResponse;
use Symfony\AI\Platform\Result\ChoiceResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCallResult;

final class AiAgentResponseMapper
{
    public function map(string $model, ResultInterface $result): AiAgentResponse
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
                        'id' => $toolCall->getId(),
                        'name' => $toolCall->getName(),
                        'arguments' => $toolCall->getArguments(),
                    ];
                }
            }
        }

        return new AiAgentResponse($content, $model, $toolCalls, $result->getMetadata()->all());
    }

    /**
     * @return list<ResultInterface>
     */
    private function flatten(ResultInterface $result): array
    {
        if ($result instanceof StreamResult) {
            $content = '';
            $toolCalls = [];

            foreach ($result->getContent() as $delta) {
                if ($delta instanceof TextDelta) {
                    $content .= $delta->getText();
                    continue;
                }

                if ($delta instanceof ToolCallComplete) {
                    $toolCalls = $delta->getToolCalls();
                }
            }

            $results = [new TextResult($content)];

            if ($toolCalls !== []) {
                $results[] = new ToolCallResult($toolCalls);
            }

            return $results;
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
