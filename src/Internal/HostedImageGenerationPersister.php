<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal;

final class HostedImageGenerationPersister
{
    public function __construct(
        private readonly ?string $workingDirectory = null,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     * @param list<array<string, mixed>> $attachedImages
     * @return list<array<string, mixed>>
     */
    public function persist(string $provider, string $model, string $prompt, array $metadata, array $attachedImages = []): array
    {
        if ($provider !== 'openai') {
            return [];
        }

        $streamEvents = $metadata['stream_events'] ?? null;

        if (!is_array($streamEvents)) {
            return [];
        }

        $images = $this->extractImagesFromStreamEvents($streamEvents);

        if ($images === []) {
            return [];
        }

        $targetPath = $this->resolveOutputPath($prompt, $attachedImages, $images[0]['extension']);
        $savedImages = [];

        foreach ($images as $index => $image) {
            $path = $index === 0 ? $targetPath : $this->appendNumericSuffix($targetPath, $index + 1);

            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Unable to create directory "%s".', $directory));
            }

            if (file_put_contents($path, $image['binary']) === false) {
                throw new \RuntimeException(sprintf('Unable to write image file "%s".', $path));
            }

            $savedImages[] = (new GeneratedImage(
                path: $path,
                filename: basename($path),
                mimeType: $image['mime_type'],
                extension: $image['extension'],
                size: strlen($image['binary']),
                provider: $provider,
                model: $model,
                extraMetadata: array_filter([
                    'revised_prompt' => $image['revised_prompt'],
                    'provider_response' => $metadata['final_response'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
            ))->toMetadata();
        }

        return $savedImages;
    }

    /**
     * @param list<array<string, mixed>> $streamEvents
     * @return list<array{binary: string, mime_type: string, extension: string, revised_prompt: ?string}>
     */
    private function extractImagesFromStreamEvents(array $streamEvents): array
    {
        $images = [];

        foreach ($streamEvents as $event) {
            if (!is_array($event) || ($event['type'] ?? null) !== 'response.image_generation_call.partial_image') {
                continue;
            }

            $encoded = $event['partial_image_b64'] ?? null;

            if (!is_string($encoded) || $encoded === '') {
                continue;
            }

            $binary = base64_decode($encoded, true);

            if ($binary === false) {
                continue;
            }

            $extension = match ($event['output_format'] ?? 'png') {
                'jpg', 'jpeg' => 'jpg',
                'webp' => 'webp',
                'gif' => 'gif',
                'bmp' => 'bmp',
                default => 'png',
            };

            $images[] = [
                'binary' => $binary,
                'mime_type' => match ($extension) {
                    'jpg' => 'image/jpeg',
                    'webp' => 'image/webp',
                    'gif' => 'image/gif',
                    'bmp' => 'image/bmp',
                    default => 'image/png',
                },
                'extension' => $extension,
                'revised_prompt' => is_string($event['revised_prompt'] ?? null) ? $event['revised_prompt'] : null,
            ];
        }

        return $images;
    }

    /**
     * @param list<array<string, mixed>> $attachedImages
     */
    private function resolveOutputPath(string $prompt, array $attachedImages, string $extension): string
    {
        $baseDirectory = $this->resolveBaseDirectory($prompt, $attachedImages);
        $filename = $this->extractFilenameFromPrompt($prompt);

        if ($filename === null) {
            $filename = sprintf('generated_image_%s.%s', substr(bin2hex(random_bytes(6)), 0, 12), $extension);
        } elseif (pathinfo($filename, PATHINFO_EXTENSION) === '') {
            $filename .= '.' . $extension;
        }

        return rtrim($baseDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * @param list<array<string, mixed>> $attachedImages
     */
    private function resolveBaseDirectory(string $prompt, array $attachedImages): string
    {
        $defaultDirectory = $this->workingDirectory;

        if ($defaultDirectory === null || $defaultDirectory === '') {
            $defaultDirectory = getcwd() ?: '.';
        }

        if (
            preg_match('/im selben ordner|same folder/i', $prompt) === 1
            && $attachedImages !== []
            && is_string($attachedImages[0]['path'] ?? null)
        ) {
            return dirname($attachedImages[0]['path']);
        }

        return $defaultDirectory;
    }

    private function extractFilenameFromPrompt(string $prompt): ?string
    {
        if (preg_match("/['\"]([^'\"\\n\\r]+\\.(?:png|jpe?g|webp|gif|bmp))['\"]/i", $prompt, $matches) === 1) {
            return basename($matches[1]);
        }

        return null;
    }

    private function appendNumericSuffix(string $path, int $index): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $directory = dirname($path);

        return $directory . DIRECTORY_SEPARATOR . $filename . '_' . $index . ($extension !== '' ? '.' . $extension : '');
    }
}
