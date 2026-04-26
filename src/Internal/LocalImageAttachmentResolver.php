<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal;

use Armin\AiAgent\Exception\InvalidToolInput;
use Symfony\Component\Finder\Finder;

final class LocalImageAttachmentResolver
{
    private const MAX_DIMENSION = 2048;

    /**
     * @var array<string, string>
     */
    private const SUPPORTED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'png',
        'image/bmp' => 'png',
        'image/x-ms-bmp' => 'png',
    ];

    public function __construct(
        private readonly ?string $workingDirectory = null,
    ) {
    }

    public function resolve(string $path): LocalImageAttachment
    {
        $resolvedPath = $this->resolvePath($path);

        if (!is_file($resolvedPath)) {
            throw new InvalidToolInput(sprintf('The image file "%s" was not found.', $resolvedPath));
        }

        if (!is_readable($resolvedPath)) {
            throw new InvalidToolInput(sprintf('The image file "%s" is not readable.', $resolvedPath));
        }

        $imageInfo = @getimagesize($resolvedPath);

        if (!is_array($imageInfo)) {
            throw new InvalidToolInput(sprintf('The file "%s" is not a valid image.', $resolvedPath));
        }

        $mimeType = $imageInfo['mime'] ?? null;

        if (!is_string($mimeType) || !isset(self::SUPPORTED_MIME_TYPES[$mimeType])) {
            throw new InvalidToolInput(sprintf('The image format "%s" is not supported.', (string) $mimeType));
        }

        $binary = file_get_contents($resolvedPath);

        if ($binary === false) {
            throw new InvalidToolInput(sprintf('The image file "%s" could not be read.', $resolvedPath));
        }

        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];
        $finalWidth = $width;
        $finalHeight = $height;
        $wasResized = $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION;
        $finalMimeType = $mimeType;

        if ($wasResized) {
            [$binary, $finalWidth, $finalHeight, $finalMimeType] = $this->resize($binary, $mimeType, $width, $height);
        }

        return new LocalImageAttachment(
            path: $resolvedPath,
            mimeType: $finalMimeType,
            width: $width,
            height: $height,
            wasResized: $wasResized,
            finalWidth: $finalWidth,
            finalHeight: $finalHeight,
            binary: $binary,
        );
    }

    /**
     * @return list<LocalImageAttachment>
     */
    public function detectFromPrompt(string $prompt): array
    {
        preg_match_all(
            '/(?<![A-Za-z0-9_])((?:[A-Za-z]:[\\\\\/]|\.{1,2}[\\\\\/]|\/)?(?:[^\s"\'`]+[\\\\\/])*[^\s"\'`]+\.(?:jpe?g|png|gif|webp|bmp))(?![A-Za-z0-9_])/i',
            $prompt,
            $matches,
        );

        $attachments = [];
        $seen = [];

        foreach ($matches[1] ?? [] as $match) {
            $candidate = rtrim($match, ".,;:!?)]}'\"");
            $resolvedPath = $this->resolvePath($candidate);
            $uniqueKey = realpath($resolvedPath) ?: $resolvedPath;

            if (isset($seen[$uniqueKey]) || !$this->isReadableFile($resolvedPath)) {
                continue;
            }

            $attachments[] = $this->resolve($candidate);
            $seen[$uniqueKey] = true;
        }

        return $attachments;
    }

    private function isReadableFile(string $path): bool
    {
        $directory = dirname($path);

        if (!is_dir($directory)) {
            return false;
        }

        $finder = new Finder();
        $finder->files()->in($directory)->name(basename($path))->depth('== 0');

        return $finder->hasResults() && is_readable($path);
    }

    private function resolvePath(string $path): string
    {
        if ($path === '' || $this->isAbsolutePath($path)) {
            return $path;
        }

        $workingDirectory = $this->workingDirectory;

        if ($workingDirectory === null || $workingDirectory === '') {
            $workingDirectory = getcwd();
        }

        if (!is_string($workingDirectory) || $workingDirectory === '') {
            return $path;
        }

        return rtrim($workingDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }

    /**
     * @return array{0: string, 1: int, 2: int, 3: string}
     */
    private function resize(string $binary, string $mimeType, int $width, int $height): array
    {
        if (class_exists(\Imagick::class)) {
            return $this->resizeWithImagick($binary, $mimeType, $width, $height);
        }

        if (function_exists('imagecreatefromstring')) {
            return $this->resizeWithGd($binary, $mimeType, $width, $height);
        }

        throw new InvalidToolInput('No supported PHP image extension is available to resize the image.');
    }

    /**
     * @return array{0: string, 1: int, 2: int, 3: string}
     */
    private function resizeWithImagick(string $binary, string $mimeType, int $width, int $height): array
    {
        $image = new \Imagick();
        $image->readImageBlob($binary);

        [$finalWidth, $finalHeight] = $this->scaledDimensions($width, $height);
        $image->resizeImage($finalWidth, $finalHeight, \Imagick::FILTER_LANCZOS, 1, true);

        $targetFormat = self::SUPPORTED_MIME_TYPES[$mimeType];
        $finalMimeType = $targetFormat === 'jpg' ? 'image/jpeg' : 'image/' . $targetFormat;
        $image->setImageFormat($targetFormat);

        return [$image->getImagesBlob(), $finalWidth, $finalHeight, $finalMimeType];
    }

    /**
     * @return array{0: string, 1: int, 2: int, 3: string}
     */
    private function resizeWithGd(string $binary, string $mimeType, int $width, int $height): array
    {
        $source = imagecreatefromstring($binary);

        if ($source === false) {
            throw new InvalidToolInput('The image could not be decoded for resizing.');
        }

        [$finalWidth, $finalHeight] = $this->scaledDimensions($width, $height);
        $target = imagecreatetruecolor($finalWidth, $finalHeight);

        if ($target === false) {
            imagedestroy($source);
            throw new InvalidToolInput('The resized image canvas could not be created.');
        }

        if ($mimeType !== 'image/jpeg') {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefill($target, 0, 0, $transparent);
        }

        imagecopyresampled($target, $source, 0, 0, 0, 0, $finalWidth, $finalHeight, $width, $height);

        ob_start();
        $encoded = match (self::SUPPORTED_MIME_TYPES[$mimeType]) {
            'jpg' => imagejpeg($target, null, 90),
            'webp' => imagewebp($target, null, 90),
            default => imagepng($target),
        };
        $output = ob_get_clean();

        imagedestroy($target);
        imagedestroy($source);

        if ($encoded !== true || !is_string($output)) {
            throw new InvalidToolInput('The resized image could not be encoded.');
        }

        $finalMimeType = match (self::SUPPORTED_MIME_TYPES[$mimeType]) {
            'jpg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return [$output, $finalWidth, $finalHeight, $finalMimeType];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function scaledDimensions(int $width, int $height): array
    {
        $scale = min(self::MAX_DIMENSION / $width, self::MAX_DIMENSION / $height);

        return [
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
        ];
    }
}
