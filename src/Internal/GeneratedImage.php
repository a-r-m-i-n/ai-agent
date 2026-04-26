<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal;

final class GeneratedImage
{
    /**
     * @param array<string, mixed> $extraMetadata
     */
    public function __construct(
        private readonly string $path,
        private readonly string $filename,
        private readonly string $mimeType,
        private readonly string $extension,
        private readonly int $size,
        private readonly string $provider,
        private readonly string $model,
        private readonly array $extraMetadata = [],
    ) {
    }

    /**
     * @return array{
     *     path: string,
     *     filename: string,
     *     mime_type: string,
     *     extension: string,
     *     size: int,
     *     provider: string,
     *     model: string
     * }&array<string, mixed>
     */
    public function toMetadata(): array
    {
        return [
            'path' => $this->path,
            'filename' => $this->filename,
            'mime_type' => $this->mimeType,
            'extension' => $this->extension,
            'size' => $this->size,
            'provider' => $this->provider,
            'model' => $this->model,
            ...$this->extraMetadata,
        ];
    }
}
