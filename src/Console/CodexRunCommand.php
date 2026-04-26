<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Console;

use Armin\CodexPhp\Auth\CodexAuthFileLoader;
use Armin\CodexPhp\CodexClient;
use Armin\CodexPhp\CodexConfig;
use Armin\CodexPhp\CodexTokenUsage;
use Armin\CodexPhp\Exception\MissingModel;
use Armin\CodexPhp\Internal\AuthResolver;
use Armin\CodexPhp\Internal\CodexTokenUsageExtractor;
use Armin\CodexPhp\Internal\ContextUsageFormatter;
use Armin\CodexPhp\Internal\DefaultSystemPromptBuilder;
use Armin\CodexPhp\Internal\ModelMetadata;
use Armin\CodexPhp\Internal\ModelMetadataRegistry;
use Armin\CodexPhp\Internal\Session\CodexSessionStore;
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
    private readonly CodexConfig $config;

    public function __construct(
        ?CodexConfig $config = null,
        private readonly ?CodexClient $client = null,
        private readonly CodexAuthFileLoader $authFileLoader = new CodexAuthFileLoader(),
        private readonly AuthResolver $authResolver = new AuthResolver(),
        private readonly ModelMetadataRegistry $modelMetadataRegistry = new ModelMetadataRegistry(),
        private readonly ContextUsageFormatter $contextUsageFormatter = new ContextUsageFormatter(),
        private readonly TokenCostCalculator $tokenCostCalculator = new TokenCostCalculator(),
    ) {
        $this->config = $config ?? $this->createDefaultConfig();

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
            ->addOption('session-file', null, InputOption::VALUE_REQUIRED, 'Path to a JSON file used to persist session history.')
            ->addOption('debug', null, InputOption::VALUE_REQUIRED, 'Debug mode: "system_prompt", "statistics", or "stats".');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $prompt = $this->resolvePromptInput((string) $input->getArgument('prompt'));
        $modelOption = $input->getOption('model');
        $keyOption = $input->getOption('key');
        $authFileOption = $input->getOption('auth-file');
        $sessionFileOption = $input->getOption('session-file');
        $debugOption = $input->getOption('debug');
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

        $effectiveConfig = $this->withDefaultWorkingDirectory($config);
        $debugMode = $this->resolveDebugMode($debugOption);

        if ($debugMode === 'system_prompt') {
            $io->writeln($this->buildSystemPrompt($effectiveConfig));

            return Command::SUCCESS;
        }

        if ($debugMode === 'statistics') {
            $io->writeln('<fg=gray>Statistics:</>');
            $this->writeSessionDiagnostics(
                $io,
                $this->readSessionUsage($effectiveConfig->sessionFile()),
                $this->modelMetadataRegistry->find($effectiveConfig->model() ?? ''),
            );

            return Command::SUCCESS;
        }

        if ($debugMode === 'history') {
            $this->writeSessionHistory($io, $this->loadRequiredSessionStore($effectiveConfig->sessionFile())->load()->messages());

            return Command::SUCCESS;
        }

        $model = $effectiveConfig->model();
        $this->authResolver->resolve($effectiveConfig);

        if ($model === null) {
            throw MissingModel::forEnvVar($effectiveConfig->modelEnvVar());
        }

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $io->writeln('<fg=gray>System prompt:</>');
            $this->writeDiagnosticBlock($io, $this->buildSystemPrompt($effectiveConfig));
            $io->newLine();
            $io->writeln('<fg=gray>User prompt:</>');
            $this->writeDiagnosticBlock($io, $prompt);
            $io->newLine();
        }

        $client = $this->client ?? new CodexClient($effectiveConfig);
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

    private function createDefaultConfig(): CodexConfig
    {
        $workingDirectory = getcwd();

        if (!is_string($workingDirectory) || $workingDirectory === '') {
            return new CodexConfig();
        }

        return new CodexConfig(
            workingDirectory: $workingDirectory,
        );
    }

    private function withDefaultWorkingDirectory(CodexConfig $config): CodexConfig
    {
        if ($config->workingDirectory() !== null) {
            return $config;
        }

        $workingDirectory = getcwd();

        if (!is_string($workingDirectory) || $workingDirectory === '') {
            return $config;
        }

        return new CodexConfig(
            apiKey: $config->apiKey(),
            model: $config->model(),
            auth: $config->auth(),
            sessionFile: $config->sessionFile(),
            workingDirectory: $workingDirectory,
            systemPrompt: $config->systemPrompt(),
            systemPromptMode: $config->systemPromptMode(),
        );
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

    private function writeSessionDiagnostics(SymfonyStyle $io, CodexTokenUsage $sessionUsage, ?ModelMetadata $modelMetadata): void
    {
        $table = new Table($io);
        $table->setStyle('box');
        $table->setHeaders([
            '<fg=gray>Metric</>',
            '<fg=gray>Session</>',
        ]);

        foreach ($this->sessionUsageRows($sessionUsage, $modelMetadata) as $row) {
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

    private function resolveDebugMode(mixed $debugOption): ?string
    {
        if (!is_string($debugOption) || $debugOption === '') {
            return null;
        }

        return match ($debugOption) {
            'system_prompt' => 'system_prompt',
            'statistics', 'stats' => 'statistics',
            'history' => 'history',
            default => throw new \InvalidArgumentException(sprintf(
                'Invalid debug mode "%s". Supported values are "system_prompt", "statistics", "stats", and "history".',
                $debugOption,
            )),
        };
    }

    /**
     * @return list<array{string, string}>
     */
    private function sessionUsageRows(CodexTokenUsage $sessionUsage, ?ModelMetadata $modelMetadata): array
    {
        $rows = [];
        $metrics = [
            ['input', $sessionUsage->input()],
            ['cached_input', $sessionUsage->cachedInput()],
            ['output', $sessionUsage->output()],
            ['reasoning', $sessionUsage->reasoning()],
            ['total', $sessionUsage->total()],
            ['image_generation_input', $sessionUsage->imageGenerationInput()],
            ['image_generation_output', $sessionUsage->imageGenerationOutput()],
            ['image_generation_total', $sessionUsage->imageGenerationTotal()],
            ['tool_calls', $sessionUsage->toolCalls()],
        ];

        foreach ($metrics as [$label, $sessionValue]) {
            if ($sessionValue === 0) {
                continue;
            }

            if ($label === 'total') {
                $rows[] = [
                    '<fg=yellow;options=bold>total</>',
                    sprintf('<fg=yellow;options=bold>%s</>', $this->contextUsageFormatter->format($sessionValue, $modelMetadata)),
                ];

                continue;
            }

            $rows[] = [$label, $this->formatNumber($sessionValue)];
        }

        $sessionDetails = $this->formatToolCallDetails($sessionUsage);
        if ($sessionDetails !== '-') {
            $rows[] = ['tool_call_details', $sessionDetails];
        }

        $sessionCost = $this->tokenCostCalculator->estimate($sessionUsage, $modelMetadata);
        if ($sessionCost !== null && $sessionUsage->toArray() !== (new CodexTokenUsage())->toArray()) {
            $rows[] = ['estimated_cost', $sessionCost->formatUsd()];
        }

        return $rows;
    }

    private function readSessionUsage(?string $sessionFile): CodexTokenUsage
    {
        if ($sessionFile === null || $sessionFile === '') {
            return new CodexTokenUsage();
        }

        $store = new CodexSessionStore($sessionFile);
        if (!$store->exists()) {
            return new CodexTokenUsage();
        }

        return (new CodexTokenUsageExtractor())->fromSession($store->load());
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function writeSessionHistory(SymfonyStyle $io, array $messages): void
    {
        $requestNumber = 0;

        foreach ($messages as $index => $message) {
            $role = $message['role'] ?? null;

            if ($role === 'user') {
                ++$requestNumber;
                $io->section(sprintf('Request %d', $requestNumber));
                $io->writeln('<fg=white>User prompt:</>');
                $this->writeDiagnosticBlock($io, (string) ($message['content'] ?? ''));
                $io->newLine();

                continue;
            }

            if ($role === 'assistant') {
                $this->writeHistoryAssistantMessage(
                    $io,
                    $message,
                    $requestNumber,
                    $this->isFinalAssistantMessage($messages, $index) ? null : $this->assistantStepNumber($messages, $index),
                    $this->isFinalAssistantMessage($messages, $index),
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function writeHistoryAssistantMessage(SymfonyStyle $io, array $message, int $requestNumber, ?int $responseIndex, bool $isFinal): void
    {
        $label = $isFinal
            ? 'Response'
            : sprintf('Response %d', $responseIndex);

        $io->writeln(sprintf('<fg=white>%s:</>', $label));

        $toolCalls = $message['tool_calls'] ?? null;
        if (is_array($toolCalls) && $toolCalls !== []) {
            $io->writeln('<fg=gray>Tool calls:</>');

            foreach (array_values(array_filter($toolCalls, 'is_array')) as $toolCall) {
                $name = $toolCall['name'] ?? 'unknown';
                $toolCallId = $toolCall['id'] ?? null;
                $suffix = is_string($toolCallId) && $toolCallId !== '' ? sprintf(' [%s]', $toolCallId) : '';
                $arguments = $toolCall['arguments'] ?? [];
                $encodedArguments = json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

                $io->writeln(sprintf('<fg=gray>- %s%s %s</>', $name, $suffix, $encodedArguments));
            }
        }

        $content = $this->extractAssistantContent($message);
        if ($content !== '') {
            $this->writeDiagnosticBlock($io, $content);
        }

        $io->newLine();
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function isFinalAssistantMessage(array $messages, int $index): bool
    {
        for ($cursor = $index + 1, $count = count($messages); $cursor < $count; ++$cursor) {
            $role = $messages[$cursor]['role'] ?? null;

            if ($role === 'user') {
                return true;
            }

            if ($role === 'assistant') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function assistantStepNumber(array $messages, int $index): int
    {
        $step = 0;

        for ($cursor = $index; $cursor >= 0; --$cursor) {
            $role = $messages[$cursor]['role'] ?? null;

            if ($role === 'user') {
                break;
            }

            if ($role === 'assistant') {
                ++$step;
            }
        }

        return $step;
    }

    private function loadRequiredSessionStore(?string $sessionFile): CodexSessionStore
    {
        if ($sessionFile === null || $sessionFile === '') {
            throw new \InvalidArgumentException('Debug mode "history" requires --session-file.');
        }

        $store = new CodexSessionStore($sessionFile);
        if (!$store->exists()) {
            throw new \InvalidArgumentException(sprintf(
                'Debug mode "history" requires an existing session file. File not found: %s',
                $sessionFile,
            ));
        }

        return $store;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function extractAssistantContent(array $message): string
    {
        $content = $message['content'] ?? null;

        if (is_string($content) && trim($content) !== '') {
            return $content;
        }

        $finalResponse = $message['metadata']['final_response'] ?? null;

        return $this->extractTextFromFinalResponse(is_array($finalResponse) ? $finalResponse : []);
    }

    /**
     * @param array<string, mixed> $finalResponse
     */
    private function extractTextFromFinalResponse(array $finalResponse): string
    {
        $output = $finalResponse['output'] ?? null;

        if (!is_array($output)) {
            return '';
        }

        $parts = [];

        foreach ($output as $item) {
            if (!is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            $content = $item['content'] ?? null;

            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }

                $type = $contentItem['type'] ?? null;

                if ($type === 'output_text' && is_string($contentItem['text'] ?? null)) {
                    $parts[] = $contentItem['text'];
                    continue;
                }

                if ($type === 'text' && is_string($contentItem['text'] ?? null)) {
                    $parts[] = $contentItem['text'];
                }
            }
        }

        return implode("\n", array_filter($parts, static fn (string $part): bool => $part !== ''));
    }
}
