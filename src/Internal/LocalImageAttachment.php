<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal;

use Symfony\AI\Platform\Message\Content\Image;

final class LocalImageAttachment
{
    public function __construct(
        private readonly string $path,
        private readonly string $mimeType,
        private readonly int $width,
        private readonly int $height,
        private readonly bool $wasResized,
        private readonly int $finalWidth,
        private readonly int $finalHeight,
        private readonly string $binary,
    ) {
    }

    public function asImageContent(): Image
    {
        return new Image($this->binary, $this->mimeType, $this->path);
    }

    /**
     * @return array{
     *     path: string,
     *     mime_type: string,
     *     width: int,
     *     height: int,
     *     was_resized: bool,
     *     final_width: int,
     *     final_height: int
     * }
     */
    public function toPayload(): array
    {
        return [
            'path' => $this->path,
            'mime_type' => $this->mimeType,
            'width' => $this->width,
            'height' => $this->height,
            'was_resized' => $this->wasResized,
            'final_width' => $this->finalWidth,
            'final_height' => $this->finalHeight,
        ];
    }

    /**
     * @return array{
     *     path: string,
     *     mime_type: string,
     *     width: int,
     *     height: int,
     *     was_resized: bool,
     *     final_width: int,
     *     final_height: int
     * }
     */
    public function toMetadata(): array
    {
        return [
            'path' => $this->path,
            'mime_type' => $this->mimeType,
            'width' => $this->width,
            'height' => $this->height,
            'was_resized' => $this->wasResized,
            'final_width' => $this->finalWidth,
            'final_height' => $this->finalHeight,
        ];
    }
}
