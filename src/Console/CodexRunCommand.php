<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Console;

use Armin\CodexPhp\Auth\CodexAuthFileLoader;
use Armin\CodexPhp\CodexClient;
use Armin\CodexPhp\CodexConfig;
use Armin\CodexPhp\Exception\MissingModel;
use Armin\CodexPhp\Internal\AuthResolver;
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
        private readonly ?CodexClient $client = null,
        private readonly CodexAuthFileLoader $authFileLoader = new CodexAuthFileLoader(),
        private readonly AuthResolver $authResolver = new AuthResolver(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Runs Codex in non-interactive mode.')
            ->addArgument('prompt', InputArgument::REQUIRED, 'The prompt to execute.')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'The model to use.')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'The API key to use.')
            ->addOption('auth-file', null, InputOption::VALUE_REQUIRED, 'Path to an auth.json file.')
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Output only the final provider response when available.')
            ->addOption('debug-all', null, InputOption::VALUE_NONE, 'Output the final provider response together with all parsed stream events when available.');
    }

    /**
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $prompt = (string) $input->getArgument('prompt');
        $modelOption = $input->getOption('model');
        $keyOption = $input->getOption('key');
        $authFileOption = $input->getOption('auth-file');
        $debug = (bool) $input->getOption('debug');
        $debugAll = (bool) $input->getOption('debug-all');
        $auth = is_string($authFileOption) && $authFileOption !== ''
            ? $this->authFileLoader->load($authFileOption)
            : null;

        $config = $auth === null
            ? $this->config
            : new CodexConfig(
                apiKey: null,
                apiKeyEnvVar: $this->config->apiKeyEnvVar(),
                model: $this->config->model(),
                modelEnvVar: $this->config->modelEnvVar(),
                auth: $auth,
                workingDirectory: $this->config->workingDirectory(),
                systemPrompt: $this->config->systemPrompt(),
                systemPromptMode: $this->config->systemPromptMode(),
            );

        $model = $config->resolveModel(is_string($modelOption) ? $modelOption : null);
        $this->authResolver->resolve($config, is_string($keyOption) ? $keyOption : null);

        if ($model === null) {
            throw MissingModel::forEnvVar($config->modelEnvVar());
        }

        $keySource = is_string($keyOption) && $keyOption !== ''
            ? 'option'
            : ($auth !== null ? 'auth_file' : 'env');
        $modelSource = is_string($modelOption) && $modelOption !== '' ? 'option' : 'env';
        $response = ($this->client ?? new CodexClient($config))->request($prompt, $model, is_string($keyOption) ? $keyOption : null);

        $payload = [
            'prompt' => $prompt,
            'model' => $response->model(),
            'model_source' => $modelSource,
            'api_key_source' => $keySource,
            'content' => $response->content(),
            'tool_calls' => $response->toolCalls(),
            'metadata' => $response->metadata(),
        ];

        if ($debugAll) {
            $output->writeln(json_encode([
                'final_response' => $response->metadata()['final_response'] ?? null,
                'stream_events' => $response->metadata()['stream_events'] ?? [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        if ($debug) {
            $output->writeln(json_encode($response->metadata()['final_response'] ?? null, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
