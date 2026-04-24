<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Internal;

use Armin\CodexPhp\Exception\InvalidToolInput;
use Armin\CodexPhp\Exception\ModelDoesNotSupportImageOutput;
use Armin\CodexPhp\Auth\CodexAuth;
use Symfony\AI\Platform\Bridge\OpenAi\DallE\Base64Image;
use Symfony\AI\Platform\Bridge\OpenAi\DallE\ImageResult;
use Symfony\AI\Platform\Bridge\OpenAi\DallE\UrlImage;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\Result\ChoiceResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Armin\CodexPhp\Internal\Provider\OpenAiCodexStream;

final class ImageGenerator
{
    public function __construct(
        private readonly ?string $workingDirectory = null,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly ?ResolvedAuth $auth = null,
    ) {
    }

    /**
     * @param array{prompt: string, path?: mixed, filename?: mixed, overwrite?: mixed} $input
     */
    public function generate(PlatformInterface $platform, string $provider, string $model, array $input): GeneratedImage
    {
        $prompt = $input['prompt'] ?? null;

        if (!is_string($prompt) || $prompt === '') {
            throw new InvalidToolInput('The "prompt" input must be a non-empty string.');
        }

        if (!$platform->getModelCatalog()->getModel($model)->supports(Capability::OUTPUT_IMAGE)) {
            throw ModelDoesNotSupportImageOutput::forModel(sprintf('%s:%s', $provider, $model));
        }

        if ($provider === 'openai' && $this->auth?->mode() === CodexAuth::MODE_TOKENS) {
            [$binary, $mimeType, $extraMetadata] = $this->generateWithOpenAiTokenResponses($model, $prompt);
        } else {
            $result = $platform->invoke($model, $prompt)->getResult();
            [$binary, $mimeType, $extraMetadata] = $this->extractBinary($result);
        }

        $path = $this->resolveOutputPath(
            is_string($input['path'] ?? null) ? $input['path'] : null,
            is_string($input['filename'] ?? null) ? $input['filename'] : null,
            $this->extensionFromMimeType($mimeType),
            ($input['overwrite'] ?? true) !== false,
        );

        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create directory "%s".', $directory));
        }

        if (file_put_contents($path, $binary) === false) {
            throw new \RuntimeException(sprintf('Unable to write image file "%s".', $path));
        }

        return new GeneratedImage(
            path: $path,
            filename: basename($path),
            mimeType: $mimeType,
            extension: pathinfo($path, PATHINFO_EXTENSION),
            size: strlen($binary),
            provider: $provider,
            model: $model,
            extraMetadata: $extraMetadata,
        );
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function extractBinary(ResultInterface $result): array
    {
        if ($result instanceof ImageResult) {
            $image = $result->getContent()[0] ?? null;

            if ($image instanceof Base64Image) {
                $binary = base64_decode($image->encodedImage, true);

                if ($binary === false) {
                    throw new \RuntimeException('The generated image data is not valid base64.');
                }

                return [$binary, 'image/png', array_filter(['revised_prompt' => $result->getRevisedPrompt()])];
            }

            if ($image instanceof UrlImage) {
                if ($this->httpClient === null) {
                    throw new \RuntimeException('The provider returned an image URL, but no HTTP client is available to download it.');
                }

                $response = $this->httpClient->request('GET', $image->url);
                $binary = $response->getContent();
                $mimeTypeHeader = $response->getHeaders(false)['content-type'][0] ?? 'image/png';
                $mimeType = trim(explode(';', $mimeTypeHeader)[0]);

                return [$binary, $mimeType, array_filter([
                    'revised_prompt' => $result->getRevisedPrompt(),
                    'source_url' => $image->url,
                ])];
            }
        }

        if ($result instanceof BinaryResult) {
            return [$result->getContent(), $result->getMimeType() ?? 'application/octet-stream', []];
        }

        if ($result instanceof MultiPartResult || $result instanceof ChoiceResult) {
            foreach ($result->getContent() as $part) {
                try {
                    return $this->extractBinary($part);
                } catch (\RuntimeException) {
                }
            }
        }

        throw new \RuntimeException(sprintf('The model returned "%s" instead of image output.', $result::class));
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function generateWithOpenAiTokenResponses(string $model, string $prompt): array
    {
        $accessToken = $this->auth?->accessToken();
        $accountId = $this->auth?->accountId();

        if (!is_string($accessToken) || $accessToken === '' || !is_string($accountId) || $accountId === '') {
            throw new \RuntimeException('OpenAI token-based image generation requires access token and account id.');
        }

        $httpClient = $this->httpClient ?? HttpClient::create();
        $response = $httpClient->request('POST', 'https://chatgpt.com/backend-api/codex/responses', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'ChatGPT-Account-ID' => $accountId,
                'User-Agent' => 'codex-cli/0.124.0',
            ],
            'json' => [
                'model' => $model,
                'instructions' => 'Generate exactly one image that matches the user prompt.',
                'input' => [[
                    'role' => 'user',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => $prompt,
                    ]],
                ]],
                'tools' => [
                    ['type' => 'image_generation'],
                ],
                'store' => false,
                'stream' => true,
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $rawBody = $response->getContent(false);

        if ($statusCode >= 400) {
            $data = json_decode($rawBody, true);
            $message = is_array($data) && isset($data['error']['message']) && is_string($data['error']['message'])
                ? $data['error']['message']
                : (is_array($data) && isset($data['detail']) && is_string($data['detail']) ? $data['detail'] : 'Image generation request failed.');
            throw new \RuntimeException($message);
        }

        $stream = new OpenAiCodexStream();
        $lastImageEvent = null;
        $finalResponse = null;

        foreach ($stream->stream($response) as $event) {
            if (!is_array($event)) {
                continue;
            }

            if (($event['type'] ?? null) === 'response.image_generation_call.partial_image' && isset($event['partial_image_b64']) && is_string($event['partial_image_b64'])) {
                $lastImageEvent = $event;
                continue;
            }

            if (($event['type'] ?? null) === 'response.completed' && isset($event['response']) && is_array($event['response'])) {
                $finalResponse = $event['response'];
            }
        }

        if (!is_array($lastImageEvent)) {
            throw new \RuntimeException('OpenAI token response did not contain generated image data.');
        }

        $binary = base64_decode($lastImageEvent['partial_image_b64'], true);

        if ($binary === false) {
            throw new \RuntimeException('Generated image result is not valid base64.');
        }

        $mimeType = match ($lastImageEvent['output_format'] ?? 'png') {
            'jpeg', 'jpg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            default => 'image/png',
        };

        return [$binary, $mimeType, array_filter([
            'revised_prompt' => $lastImageEvent['revised_prompt'] ?? null,
            'provider_response' => $finalResponse,
        ])];
    }

    private function resolveOutputPath(?string $path, ?string $filename, string $extension, bool $overwrite): string
    {
        $baseDirectory = $this->workingDirectory;

        if ($baseDirectory === null || $baseDirectory === '') {
            $baseDirectory = getcwd() ?: '.';
        }

        if ($path !== null && $path !== '') {
            $resolvedPath = $this->resolvePath($path, $baseDirectory);

            if ($filename !== null && $filename !== '') {
                $targetPath = rtrim($resolvedPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
            } elseif (is_dir($resolvedPath) || str_ends_with($path, '/') || str_ends_with($path, '\\')) {
                $targetPath = rtrim($resolvedPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->defaultFilename($extension);
            } else {
                $targetPath = $resolvedPath;
            }
        } elseif ($filename !== null && $filename !== '') {
            $targetPath = rtrim($baseDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        } else {
            $targetPath = rtrim($baseDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->defaultFilename($extension);
        }

        if (pathinfo($targetPath, PATHINFO_EXTENSION) === '') {
            $targetPath .= '.' . $extension;
        }

        if ($overwrite || !is_file($targetPath)) {
            return $targetPath;
        }

        return $this->uniqueAlternativePath($targetPath);
    }

    private function resolvePath(string $path, string $baseDirectory): string
    {
        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return $path;
        }

        return rtrim($baseDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }

    private function defaultFilename(string $extension): string
    {
        return 'new_image_' . bin2hex(random_bytes(6)) . '.' . $extension;
    }

    private function uniqueAlternativePath(string $path): string
    {
        $directory = dirname($path);
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        do {
            $candidate = sprintf(
                '%s%s%s_%s%s',
                $directory,
                DIRECTORY_SEPARATOR,
                $filename,
                bin2hex(random_bytes(4)),
                $extension !== '' ? '.' . $extension : '',
            );
        } while (is_file($candidate));

        return $candidate;
    }

    private function extensionFromMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/bmp', 'image/x-ms-bmp' => 'bmp',
            default => throw new \RuntimeException(sprintf('Unsupported generated image mime type "%s".', $mimeType)),
        };
    }
}
