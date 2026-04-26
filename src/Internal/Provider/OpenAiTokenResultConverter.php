<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal\Provider;

use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\ContentFilterException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\ChoiceResult;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;

final class OpenAiTokenResultConverter implements ResultConverterInterface
{
    private const KEY_OUTPUT = 'output';

    public function supports(Model $model): bool
    {
        return $model instanceof ResponsesModel;
    }

    public function convert(RawResultInterface|RawHttpResult $result, array $options = []): ResultInterface
    {
        $response = $result->getObject();
        $rawBody = $response->getContent(false);

        if (401 === $response->getStatusCode()) {
            $errorMessage = json_decode($rawBody, true)['error']['message'];
            throw new AuthenticationException($errorMessage);
        }

        if (400 === $response->getStatusCode()) {
            throw new BadRequestException($this->buildBadRequestMessage($rawBody));
        }

        if (429 === $response->getStatusCode()) {
            $headers = $response->getHeaders(false);
            $resetTime = $headers['x-ratelimit-reset-requests'][0]
                ?? $headers['x-ratelimit-reset-tokens'][0]
                ?? null;

            throw new RateLimitExceededException($resetTime ? self::parseResetTime($resetTime) : null);
        }

        if ($options['stream'] ?? false) {
            $streamEvents = $this->extractStreamEventsFromBody($rawBody);
            $streamResult = new StreamResult($this->convertStreamEvents($streamEvents));
            $streamResult->getMetadata()->add('final_response', $this->extractFinalResponseFromStreamEvents($streamEvents));
            $streamResult->getMetadata()->add('stream_events', $streamEvents);

            return $streamResult;
        }

        $data = $result->getData();

        if (isset($data['error']['code']) && 'content_filter' === $data['error']['code']) {
            throw new ContentFilterException($data['error']['message']);
        }

        if (isset($data['error'])) {
            throw new RuntimeException($this->generateErrorMessage($data['error']));
        }

        if (!isset($data[self::KEY_OUTPUT])) {
            throw new RuntimeException('Response does not contain output.');
        }

        $results = $this->convertOutputArray($data[self::KEY_OUTPUT]);

        $convertedResult = 1 === \count($results) ? array_pop($results) : new ChoiceResult($results);
        $convertedResult->getMetadata()->add('final_response', $data);

        return $convertedResult;
    }

    public function getTokenUsageExtractor(): \Symfony\AI\Platform\Bridge\OpenResponses\TokenUsageExtractor
    {
        return new \Symfony\AI\Platform\Bridge\OpenResponses\TokenUsageExtractor();
    }

    /**
     * @param array<mixed> $output
     *
     * @return ResultInterface[]
     */
    private function convertOutputArray(array $output): array
    {
        [$toolCallResult, $output] = $this->extractFunctionCalls($output);

        $results = array_filter(array_map($this->processOutputItem(...), $output));
        if ($toolCallResult) {
            $results[] = $toolCallResult;
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function processOutputItem(array $item): ?ResultInterface
    {
        $type = $item['type'] ?? null;

        return match ($type) {
            'message' => $this->convertOutputMessage($item),
            'reasoning' => $this->convertReasoning($item),
            default => throw new RuntimeException(\sprintf('Unsupported output type "%s".', $type)),
        };
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function convertStreamEvents(array $events): \Generator
    {
        $currentThinking = null;
        $pendingToolCalls = [];

        foreach ($events as $event) {
            $type = $event['type'] ?? '';

            if ('error' === $type && isset($event['error'])) {
                throw new RuntimeException($this->generateErrorMessage($event['error']));
            }

            if (isset($event['response']['usage'])) {
                yield $this->getTokenUsageExtractor()->fromDataArray($event['response']);
            }

            if (str_contains($type, 'output_text') && isset($event['delta'])) {
                yield new TextDelta($event['delta']);
            }

            if ('response.reasoning_summary_text.delta' === $type && isset($event['delta'])) {
                if (null === $currentThinking) {
                    $currentThinking = '';
                    yield new ThinkingStart();
                }
                $currentThinking .= $event['delta'];
                yield new ThinkingDelta($event['delta']);
            }

            if ('response.reasoning_summary_text.done' === $type) {
                yield new ThinkingComplete($currentThinking ?? '');
                $currentThinking = null;
            }

            if ('response.output_item.done' === $type) {
                $toolCall = $this->convertStreamToolCall($event['item'] ?? null);

                if ($toolCall !== null) {
                    $pendingToolCalls[$toolCall->getId()] = $toolCall;
                    yield new ToolCallComplete(array_values($pendingToolCalls));
                }

                continue;
            }

            if (!str_contains($type, 'completed')) {
                continue;
            }

            [$toolCallResult] = $this->extractFunctionCalls($event['response'][self::KEY_OUTPUT] ?? []);

            if ($toolCallResult && 'response.completed' === $type) {
                yield new ToolCallComplete($toolCallResult->getContent());
            }
        }
    }

    /**
     * @param array<mixed> $output
     *
     * @return list<ToolCallResult|array<mixed>|null>
     */
    private function extractFunctionCalls(array $output): array
    {
        $functionCalls = [];
        foreach ($output as $key => $item) {
            if ('function_call' === ($item['type'] ?? null)) {
                $functionCalls[] = $item;
                unset($output[$key]);
            }
        }

        $toolCallResult = $functionCalls ? new ToolCallResult(
            array_map($this->convertFunctionCall(...), $functionCalls)
        ) : null;

        return [$toolCallResult, $output];
    }

    /**
     * @param array<string, mixed> $output
     */
    private function convertOutputMessage(array $output): ?TextResult
    {
        $content = $output['content'] ?? [];
        if ([] === $content) {
            return null;
        }

        $content = array_pop($content);
        if ('refusal' === $content['type']) {
            return new TextResult(\sprintf('Model refused to generate output: %s', $content['refusal']));
        }

        return new TextResult($content['text']);
    }

    /**
     * @param array<string, mixed> $toolCall
     */
    private function convertFunctionCall(array $toolCall): ToolCall
    {
        $arguments = json_decode($toolCall['arguments'], true, flags: \JSON_THROW_ON_ERROR);

        return new ToolCall($toolCall['call_id'] ?? $toolCall['id'], $toolCall['name'], $arguments);
    }

    /**
     * @param array<string, mixed>|null $item
     */
    private function convertStreamToolCall(?array $item): ?ToolCall
    {
        if (!is_array($item) || ($item['type'] ?? null) !== 'function_call') {
            return null;
        }

        $id = $item['call_id'] ?? $item['id'] ?? null;
        $name = $item['name'] ?? null;
        $arguments = $item['arguments'] ?? null;

        if (!is_string($id) || !is_string($name) || !is_string($arguments)) {
            return null;
        }

        return new ToolCall($id, $name, json_decode($arguments, true, flags: \JSON_THROW_ON_ERROR));
    }

    private static function parseResetTime(string $resetTime): ?int
    {
        if (preg_match('/^(?:(\d+)m)?(?:(\d+)s)?$/', $resetTime, $matches)) {
            $minutes = isset($matches[1]) ? (int) $matches[1] : 0;
            $secs = isset($matches[2]) ? (int) $matches[2] : 0;

            return ($minutes * 60) + $secs;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function convertReasoning(array $item): ?ResultInterface
    {
        $summary = $item['summary']['text'] ?? null;

        return $summary ? new TextResult($summary) : null;
    }

    /**
     * @param array<string, mixed> $error
     */
    private function generateErrorMessage(array $error): string
    {
        return \sprintf('Error "%s"-%s (%s): "%s".', $error['code'] ?? '-', $error['type'] ?? '-', $error['param'] ?? '-', $error['message'] ?? '-');
    }

    private function buildBadRequestMessage(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return 'Bad Request. Raw response: '.$body;
        }

        if (isset($decoded['detail']) && is_string($decoded['detail'])) {
            return 'Bad Request. '.$decoded['detail'];
        }

        $error = $decoded['error'] ?? null;
        if (is_array($error)) {
            $details = [
                'message' => $error['message'] ?? null,
                'type' => $error['type'] ?? null,
                'param' => $error['param'] ?? null,
                'code' => $error['code'] ?? null,
            ];

            $details = array_filter($details, static fn (mixed $value): bool => $value !== null && $value !== '');

            return 'Bad Request. '.json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return 'Bad Request. Response: '.json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractFinalResponseFromStreamEvents(array $events): ?array
    {
        foreach (array_reverse($events) as $decoded) {
            if (($decoded['type'] ?? null) !== 'response.completed') {
                continue;
            }

            $response = $decoded['response'] ?? null;

            return is_array($response) ? $response : $decoded;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractStreamEventsFromBody(string $body): array
    {
        $frames = preg_split("/\r\n\r\n|\n\n|\r\r/", trim($body));
        if (!is_array($frames)) {
            return [];
        }

        $events = [];

        foreach ($frames as $frame) {
            $data = $this->extractDataFromFrame($frame);
            if ($data === null || $data === '' || $data === '[DONE]') {
                continue;
            }

            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }

    private function extractDataFromFrame(string $frame): ?string
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
