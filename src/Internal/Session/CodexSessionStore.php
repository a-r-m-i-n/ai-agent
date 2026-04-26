<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal\Session;

use Armin\CodexPhp\Exception\InvalidSession;

final class CodexSessionStore
{
    private const VERSION = 1;

    public function __construct(
        private readonly string $path,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function load(): CodexSession
    {
        if (!$this->exists()) {
            return new CodexSession();
        }

        $json = @file_get_contents($this->path);

        if (!is_string($json)) {
            throw InvalidSession::unreadableFile($this->path);
        }

        try {
            $payload = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw InvalidSession::invalidJson($this->path);
        }

        if (!is_array($payload)) {
            throw InvalidSession::invalidFileStructure('The session payload must be a JSON object.');
        }

        $version = $payload['version'] ?? null;
        if (!is_int($version)) {
            throw InvalidSession::invalidFileStructure('Field "version" must be an integer.');
        }

        if (self::VERSION !== $version) {
            throw InvalidSession::unsupportedVersion($version);
        }

        $messages = $payload['messages'] ?? null;
        if (!is_array($messages)) {
            throw InvalidSession::invalidFileStructure('Field "messages" must be an array.');
        }

        $normalizedMessages = [];

        foreach ($messages as $index => $message) {
            if (!is_array($message)) {
                throw InvalidSession::invalidFileStructure(sprintf('Message at index %d must be an object.', $index));
            }

            $role = $message['role'] ?? null;
            if (!is_string($role) || !in_array($role, ['user', 'assistant', 'tool'], true)) {
                throw InvalidSession::invalidFileStructure(sprintf('Message at index %d has an unsupported "role".', $index));
            }

            $content = $message['content'] ?? null;
            if (!is_string($content)) {
                throw InvalidSession::invalidFileStructure(sprintf('Message at index %d must contain string field "content".', $index));
            }

            $normalizedMessage = [
                'role' => $role,
                'content' => $content,
            ];

            if (array_key_exists('tool_calls', $message)) {
                if ('assistant' !== $role) {
                    throw InvalidSession::invalidFileStructure(sprintf('Only assistant messages may define "tool_calls" (index %d).', $index));
                }

                if (!is_array($message['tool_calls'])) {
                    throw InvalidSession::invalidFileStructure(sprintf('Field "tool_calls" at index %d must be an array.', $index));
                }

                $normalizedMessage['tool_calls'] = $this->normalizeToolCalls($message['tool_calls'], $index);
            }

            if (array_key_exists('tool_call_id', $message)) {
                if ('tool' !== $role) {
                    throw InvalidSession::invalidFileStructure(sprintf('Only tool messages may define "tool_call_id" (index %d).', $index));
                }

                if (!is_string($message['tool_call_id']) || $message['tool_call_id'] === '') {
                    throw InvalidSession::invalidFileStructure(sprintf('Field "tool_call_id" at index %d must be a non-empty string.', $index));
                }

                $normalizedMessage['tool_call_id'] = $message['tool_call_id'];
            } elseif ('tool' === $role) {
                throw InvalidSession::invalidFileStructure(sprintf('Tool message at index %d must define "tool_call_id".', $index));
            }

            if (array_key_exists('metadata', $message)) {
                if ('assistant' !== $role) {
                    throw InvalidSession::invalidFileStructure(sprintf('Only assistant messages may define "metadata" (index %d).', $index));
                }

                if (!is_array($message['metadata'])) {
                    throw InvalidSession::invalidFileStructure(sprintf('Field "metadata" at index %d must be an object.', $index));
                }

                $normalizedMessage['metadata'] = $message['metadata'];
            }

            $normalizedMessages[] = $normalizedMessage;
        }

        return new CodexSession($normalizedMessages);
    }

    public function save(CodexSession $session): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw InvalidSession::unwritableFile($this->path);
        }

        $json = json_encode([
            'version' => self::VERSION,
            'messages' => $this->sanitizeMessagesForPersistence($session->messages()),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        if (@file_put_contents($this->path, $json . PHP_EOL) === false) {
            throw InvalidSession::unwritableFile($this->path);
        }
    }

    /**
     * @param list<array{
     *     role: 'user'|'assistant'|'tool',
     *     content: string,
     *     tool_calls?: list<array{id?: string, name: string, arguments: array<string, mixed>}>,
     *     tool_call_id?: string,
     *     metadata?: array<string, mixed>
     * }> $messages
     * @return list<array<string, mixed>>
     */
    private function sanitizeMessagesForPersistence(array $messages): array
    {
        $sanitizedMessages = [];

        foreach ($messages as $message) {
            $sanitizedMessage = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];

            if (isset($message['tool_calls']) && is_array($message['tool_calls']) && $message['tool_calls'] !== []) {
                $sanitizedMessage['tool_calls'] = $message['tool_calls'];
            }

            if (isset($message['tool_call_id']) && is_string($message['tool_call_id'])) {
                $sanitizedMessage['tool_call_id'] = $message['tool_call_id'];
            }

            if (($message['role'] ?? null) === 'assistant' && is_array($message['metadata'] ?? null)) {
                $metadata = $this->sanitizeAssistantMetadata($message['metadata']);

                if ($metadata !== []) {
                    $sanitizedMessage['metadata'] = $metadata;
                }
            }

            $sanitizedMessages[] = $sanitizedMessage;
        }

        return $sanitizedMessages;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function sanitizeAssistantMetadata(array $metadata): array
    {
        $sanitized = [];

        if (is_array($metadata['final_response'] ?? null)) {
            $usage = $this->sanitizeFinalResponse($metadata['final_response']);

            if ($usage !== []) {
                $sanitized['final_response'] = $usage;
            }
        }

        $generatedImages = $this->sanitizeGeneratedImages($metadata['generated_images'] ?? null);
        if ($generatedImages !== []) {
            $sanitized['generated_images'] = $generatedImages;
        }

        $requestAssistantMessages = $this->sanitizeRequestAssistantMessages($metadata['request_assistant_messages'] ?? null);
        if ($requestAssistantMessages !== []) {
            $sanitized['request_assistant_messages'] = $requestAssistantMessages;
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $finalResponse
     * @return array<string, mixed>
     */
    private function sanitizeFinalResponse(array $finalResponse): array
    {
        $sanitized = [];
        $usage = $finalResponse['usage'] ?? null;

        if (is_array($usage)) {
            $sanitized['usage'] = $usage;
        }

        $output = $finalResponse['output'] ?? null;
        if (is_array($output)) {
            $sanitized['output'] = $output;
        }

        return $sanitized;
    }

    /**
     * @param mixed $generatedImages
     * @return list<array{provider_response: array{tool_usage: array{image_gen: array<string, mixed>}}}>
     */
    private function sanitizeGeneratedImages(mixed $generatedImages): array
    {
        if (!is_array($generatedImages)) {
            return [];
        }

        $sanitizedImages = [];

        foreach ($generatedImages as $generatedImage) {
            if (!is_array($generatedImage)) {
                continue;
            }

            $imageUsage = $generatedImage['provider_response']['tool_usage']['image_gen'] ?? null;
            if (!is_array($imageUsage)) {
                continue;
            }

            $sanitizedImages[] = [
                'provider_response' => [
                    'tool_usage' => [
                        'image_gen' => $imageUsage,
                    ],
                ],
            ];
        }

        return $sanitizedImages;
    }

    /**
     * @param mixed $assistantMessages
     * @return list<array{metadata: array<string, mixed>}>
     */
    private function sanitizeRequestAssistantMessages(mixed $assistantMessages): array
    {
        if (!is_array($assistantMessages)) {
            return [];
        }

        $sanitizedMessages = [];

        foreach ($assistantMessages as $assistantMessage) {
            if (!is_array($assistantMessage) || !is_array($assistantMessage['metadata'] ?? null)) {
                continue;
            }

            $metadata = $this->sanitizeAssistantMetadata($assistantMessage['metadata']);
            if ($metadata === []) {
                continue;
            }

            $sanitizedMessages[] = ['metadata' => $metadata];
        }

        return $sanitizedMessages;
    }

    /**
     * @param list<mixed> $toolCalls
     * @return list<array{id?: string, name: string, arguments: array<string, mixed>}>
     */
    private function normalizeToolCalls(array $toolCalls, int $messageIndex): array
    {
        $normalizedToolCalls = [];

        foreach ($toolCalls as $toolIndex => $toolCall) {
            if (!is_array($toolCall)) {
                throw InvalidSession::invalidFileStructure(sprintf('Tool call %d on message %d must be an object.', $toolIndex, $messageIndex));
            }

            $id = $toolCall['id'] ?? null;
            $name = $toolCall['name'] ?? null;
            $arguments = $toolCall['arguments'] ?? null;

            if (($id !== null && !is_string($id)) || !is_string($name) || !is_array($arguments)) {
                throw InvalidSession::invalidFileStructure(sprintf('Tool call %d on message %d must define string "name" and object "arguments".', $toolIndex, $messageIndex));
            }

            $normalizedToolCall = [
                'name' => $name,
                'arguments' => $arguments,
            ];

            if (is_string($id) && $id !== '') {
                $normalizedToolCall['id'] = $id;
            }

            $normalizedToolCalls[] = $normalizedToolCall;
        }

        return $normalizedToolCalls;
    }
}
