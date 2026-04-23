<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Console;

use Armin\CodexPhp\CodexConfig;
use Armin\CodexPhp\Exception\MissingApiKey;
use Armin\CodexPhp\Exception\MissingModel;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'codex')]
final class CodexRunCommand extends Command
{
    public function __construct(
        private readonly CodexConfig $config = new CodexConfig(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Runs Codex in non-interactive mode.')
            ->addArgument('prompt', InputArgument::REQUIRED, 'The prompt to execute.')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'The model to use.')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'The API key to use.');
    }

    /**
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $prompt = (string) $input->getArgument('prompt');
        $modelOption = $input->getOption('model');
        $keyOption = $input->getOption('key');

        $model = $this->config->resolveModel(is_string($modelOption) ? $modelOption : null);
        $apiKey = $this->config->resolveApiKey(is_string($keyOption) ? $keyOption : null);

        if ($model === null) {
            throw MissingModel::forEnvVar($this->config->modelEnvVar());
        }

        if ($apiKey === null) {
            throw MissingApiKey::forEnvVar($this->config->apiKeyEnvVar());
        }

        $keySource = is_string($keyOption) && $keyOption !== '' ? 'option' : 'env';
        $modelSource = is_string($modelOption) && $modelOption !== '' ? 'option' : 'env';

        $payload = [
            'mode' => 'simulation',
            'prompt' => $prompt,
            'model' => $model,
            'model_source' => $modelSource,
            'api_key_source' => $keySource,
            'api_key_masked' => $this->maskApiKey($apiKey),
        ];

        $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }

    private function maskApiKey(string $apiKey): string
    {
        if (strlen($apiKey) <= 4) {
            return str_repeat('*', strlen($apiKey));
        }

        return substr($apiKey, 0, 2) . str_repeat('*', max(strlen($apiKey) - 4, 1)) . substr($apiKey, -2);
    }
}
