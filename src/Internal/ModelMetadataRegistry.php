<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal;

final class ModelMetadataRegistry
{
    /**
     * @var array<string, ModelMetadata>|null
     */
    private ?array $models = null;

    public function __construct(
        private readonly string $resourcePath = __DIR__ . '/Resources/models.json',
        private readonly ModelNameParser $modelNameParser = new ModelNameParser(),
    ) {
    }

    public function find(string $qualifiedModel): ?ModelMetadata
    {
        try {
            $resolvedModel = $this->modelNameParser->parse($qualifiedModel);
        } catch (\Throwable) {
            return null;
        }

        return $this->load()[$resolvedModel->qualifiedName()] ?? null;
    }

    /**
     * @return array<string, ModelMetadata>
     */
    private function load(): array
    {
        if (is_array($this->models)) {
            return $this->models;
        }

        $contents = file_get_contents($this->resourcePath);
        if (!is_string($contents)) {
            throw new \RuntimeException(sprintf('Unable to read model registry resource "%s".', $this->resourcePath));
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_array($decoded['models'] ?? null)) {
            throw new \RuntimeException(sprintf('Invalid model registry resource "%s".', $this->resourcePath));
        }

        $models = [];

        foreach ($decoded['models'] as $model) {
            if (!is_array($model)) {
                continue;
            }

            $provider = is_string($model['provider'] ?? null) ? $model['provider'] : null;
            $name = is_string($model['model'] ?? null) ? $model['model'] : null;
            $contextWindow = is_int($model['context_window'] ?? null) ? $model['context_window'] : null;
            $maxOutputTokens = is_int($model['max_output_tokens'] ?? null) ? $model['max_output_tokens'] : null;
            $pricing = is_array($model['pricing'] ?? null) ? $model['pricing'] : null;
            $source = is_array($model['source'] ?? null) ? $model['source'] : null;

            if (
                $provider === null
                || $name === null
                || $contextWindow === null
                || $maxOutputTokens === null
                || $pricing === null
                || $source === null
                || !is_string($source['url'] ?? null)
                || !is_numeric($pricing['input_per_million_usd'] ?? null)
                || !is_numeric($pricing['output_per_million_usd'] ?? null)
            ) {
                continue;
            }

            $metadata = new ModelMetadata(
                provider: $provider,
                model: $name,
                contextWindow: $contextWindow,
                maxOutputTokens: $maxOutputTokens,
                pricing: new ModelPricing(
                    inputPerMillionUsd: (float) $pricing['input_per_million_usd'],
                    cachedInputPerMillionUsd: is_numeric($pricing['cached_input_per_million_usd'] ?? null)
                        ? (float) $pricing['cached_input_per_million_usd']
                        : null,
                    outputPerMillionUsd: (float) $pricing['output_per_million_usd'],
                    notes: is_string($pricing['notes'] ?? null) ? $pricing['notes'] : null,
                    tiers: is_array($pricing['tiers'] ?? null) ? array_values(array_filter($pricing['tiers'], 'is_array')) : [],
                ),
                source: $source,
            );

            $models[$metadata->qualifiedName()] = $metadata;
        }

        return $this->models = $models;
    }
}
