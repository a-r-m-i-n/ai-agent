<?php

declare(strict_types=1);

namespace Armin\AiAgent\Internal;

use Armin\AiAgent\Exception\InvalidModel;

final class ModelNameParser
{
    public function parse(string $qualifiedModel): ResolvedModel
    {
        $separatorPosition = strpos($qualifiedModel, ':');

        if ($separatorPosition === false) {
            throw InvalidModel::missingProviderPrefix();
        }

        $provider = strtolower(trim(substr($qualifiedModel, 0, $separatorPosition)));
        $model = trim(substr($qualifiedModel, $separatorPosition + 1));

        if ($provider === '' || $model === '') {
            throw InvalidModel::missingProviderPrefix();
        }

        if (!in_array($provider, ['openai', 'anthropic', 'gemini'], true)) {
            throw InvalidModel::unsupportedProvider($provider);
        }

        return new ResolvedModel($provider, $model, $provider . ':' . $model);
    }
}
