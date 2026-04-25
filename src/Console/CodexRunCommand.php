<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Console;

use Armin\CodexPhp\Auth\CodexAuthFileLoader;
use Armin\CodexPhp\CodexClient;
use Armin\CodexPhp\CodexConfig;
use Armin\CodexPhp\CodexTokenUsage;
use Armin\CodexPhp\Exception\MissingModel;
use Armin\CodexPhp\Internal\AuthResolver;
use Armin\CodexPhp\Internal\ContextUsageFormatter;
use Armin\CodexPhp\Internal\DefaultSystemPromptBuilder;
use Armin\CodexPhp\Internal\ModelMetadata;
use Armin\CodexPhp\Internal\ModelMetadataRegistry;
use Armin\CodexPhp\Internal\TokenCostCalculator;
use Armin\CodexPhp\Tool\ToolRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'codex')]
final class CodexRunCommand extends Command
{
    public function __construct(
        private readonly CodexConfig $config = new CodexConfig(),
        private readonly ?CodexClient $client = null,
        private readonly CodexAuthFileLoader $authFileLoader = new CodexAuthFileLoader(),
        private readonly AuthResolver $authResolver = new AuthResolver(),
        private readonly ModelMetadataRegistry $modelMetadataRegistry = new ModelMetadataRegistry(),
        private readonly ContextUsageFormatter $contextUsageFormatter = new ContextUsageFormatter(),
        private readonly TokenCostCalculator $tokenCostCalculator = new TokenCostCalculator(),
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
            ->addOption('session-file', null, InputOption::VALUE_REQUIRED, 'Path to a JSON file used to persist session history.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $prompt = $this->resolvePromptInput((string) $input->getArgument('prompt'));
        $modelOption = $input->getOption('model');
        $keyOption = $input->getOption('key');
        $authFileOption = $input->getOption('auth-file');
        $sessionFileOption = $input->getOption('session-file');
        $auth = is_string($authFileOption) && $authFileOption !== ''
            ? $this->authFileLoader->load($authFileOption)
            : null;

        $config = $auth === null
            ? $this->config
            : new CodexConfig(
                apiKey: $this->config->apiKey(),
                model: $this->config->model(),
                auth: $auth,
                sessionFile: $this->config->sessionFile(),
                workingDirectory: $this->config->workingDirectory(),
                systemPrompt: $this->config->systemPrompt(),
                systemPromptMode: $this->config->systemPromptMode(),
            );

        if (is_string($modelOption) && $modelOption !== '') {
            $config->setModel($modelOption);
        }

        if (is_string($keyOption) && $keyOption !== '') {
            $config->setApiKey($keyOption);
        }

        if (is_string($sessionFileOption) && $sessionFileOption !== '') {
            $config->setSessionFile($sessionFileOption);
        }

        $model = $config->model();
        $this->authResolver->resolve($config);

        if ($model === null) {
            throw MissingModel::forEnvVar($config->modelEnvVar());
        }

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $io->writeln('<fg=gray>System prompt:</>');
            $this->writeDiagnosticBlock($io, $this->buildSystemPrompt($config));
            $io->newLine();
            $io->writeln('<fg=gray>User prompt:</>');
            $this->writeDiagnosticBlock($io, $prompt);
            $io->newLine();
        }

        $client = $this->client ?? new CodexClient($config);
        $response = $client->request($prompt);

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $io->writeln('<fg=gray>Output:</>');
        }

        $io->writeln($response->content());

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $io->newLine();
            $io->writeln('<fg=gray>Statistics:</>');
            $this->writeTokenDiagnostics(
                $io,
                $client->getRequestTokens(),
                $client->getSessionTokens(),
                $this->modelMetadataRegistry->find($response->model()),
            );
        }

        return Command::SUCCESS;
    }

    private function resolvePromptInput(string $prompt): string
    {
        if (!is_file($prompt) || !is_readable($prompt)) {
            return $prompt;
        }

        $contents = file_get_contents($prompt);

        return is_string($contents) ? $contents : $prompt;
    }

    private function buildSystemPrompt(CodexConfig $config): string
    {
        return (new DefaultSystemPromptBuilder(
            $config,
            ToolRegistry::withBuiltins($config->workingDirectory()),
        ))->build();
    }

    private function writeDiagnosticBlock(SymfonyStyle $io, string $content): void
    {
        foreach (preg_split("/\r\n|\n|\r/", $content) ?: [] as $line) {
            $io->writeln(sprintf('<fg=gray>%s</>', $line));
        }
    }

    private function writeTokenDiagnostics(SymfonyStyle $io, CodexTokenUsage $requestUsage, CodexTokenUsage $sessionUsage, ?ModelMetadata $modelMetadata): void
    {
        $table = new Table($io);
        $table->setStyle('box');
        $table->setHeaders([
            '<fg=gray>Metric</>',
            '<fg=gray>Request</>',
            '<fg=gray>Session</>',
        ]);

        foreach ($this->usageRows($requestUsage, $sessionUsage, $modelMetadata) as $row) {
            $table->addRow($row);
        }

        $table->render();
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function usageRows(CodexTokenUsage $requestUsage, CodexTokenUsage $sessionUsage, ?ModelMetadata $modelMetadata): array
    {
        $rows = [];
        $metrics = [
            ['input', $requestUsage->input(), $sessionUsage->input()],
            ['cached_input', $requestUsage->cachedInput(), $sessionUsage->cachedInput()],
            ['output', $requestUsage->output(), $sessionUsage->output()],
            ['reasoning', $requestUsage->reasoning(), $sessionUsage->reasoning()],
            ['total', $requestUsage->total(), $sessionUsage->total()],
            ['image_generation_input', $requestUsage->imageGenerationInput(), $sessionUsage->imageGenerationInput()],
            ['image_generation_output', $requestUsage->imageGenerationOutput(), $sessionUsage->imageGenerationOutput()],
            ['image_generation_total', $requestUsage->imageGenerationTotal(), $sessionUsage->imageGenerationTotal()],
            ['tool_calls', $requestUsage->toolCalls(), $sessionUsage->toolCalls()],
        ];

        foreach ($metrics as [$label, $requestValue, $sessionValue]) {
            if ($requestValue === 0 && $sessionValue === 0) {
                continue;
            }

            if ($label === 'total') {
                $rows[] = [
                    '<fg=yellow;options=bold>total</>',
                    sprintf('<fg=yellow;options=bold>%s</>', $this->contextUsageFormatter->format($requestValue, $modelMetadata)),
                    sprintf('<fg=yellow;options=bold>%s</>', $this->contextUsageFormatter->format($sessionValue, $modelMetadata)),
                ];

                continue;
            }

            $rows[] = [$label, $this->formatNumber($requestValue), $this->formatNumber($sessionValue)];
        }

        $requestDetails = $this->formatToolCallDetails($requestUsage);
        $sessionDetails = $this->formatToolCallDetails($sessionUsage);

        if ($requestDetails !== '-' || $sessionDetails !== '-') {
            $rows[] = ['tool_call_details', $requestDetails, $sessionDetails];
        }

        $requestCost = $this->tokenCostCalculator->estimate($requestUsage, $modelMetadata);
        $sessionCost = $this->tokenCostCalculator->estimate($sessionUsage, $modelMetadata);

        if (
            $requestCost !== null
            && $sessionCost !== null
            && !$this->isZeroUsage($requestUsage, $sessionUsage)
        ) {
            $rows[] = ['estimated_cost', $requestCost->formatUsd(), $sessionCost->formatUsd()];
        }

        return $rows;
    }

    private function formatToolCallDetails(CodexTokenUsage $usage): string
    {
        $details = $usage->toolCallDetails();

        if ($details === []) {
            return '-';
        }

        $parts = [];
        foreach ($details as $toolName => $count) {
            $parts[] = sprintf('%s:%d', $toolName, $count);
        }

        return implode(', ', $parts);
    }

    private function formatNumber(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private function isZeroUsage(CodexTokenUsage $requestUsage, CodexTokenUsage $sessionUsage): bool
    {
        return $requestUsage->toArray() === (new CodexTokenUsage())->toArray()
            && $sessionUsage->toArray() === (new CodexTokenUsage())->toArray();
    }
}
