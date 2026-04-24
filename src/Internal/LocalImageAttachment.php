<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

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
     *     final_height: int,
     *     base64: string,
     *     data_url: string
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
            'base64' => base64_encode($this->binary),
            'data_url' => sprintf('data:%s;base64,%s', $this->mimeType, base64_encode($this->binary)),
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
