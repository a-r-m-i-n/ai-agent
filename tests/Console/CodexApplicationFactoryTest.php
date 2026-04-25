<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Console;

use Armin\CodexPhp\CodexClient;
use Armin\CodexPhp\CodexResponse;
use Armin\CodexPhp\Console\CodexRunCommand;
use Armin\CodexPhp\Console\CodexApplicationFactory;
use Armin\CodexPhp\Internal\CodexRuntimeInterface;
use ReflectionProperty;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class CodexApplicationFactoryTest extends TestCase
{
    public function testApplicationRunsCodexCommandAsDefault(): void
    {
        $runtime = new class implements CodexRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): CodexResponse
            {
                return new CodexResponse('Factory test response', 'openai:gpt-5');
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                throw new \BadMethodCallException('not used');
            }
        };

        $application = CodexApplicationFactory::create(new CodexRunCommand(client: new CodexClient(runtime: $runtime)));
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(new ArrayInput([
            'prompt' => 'Smoke test',
            '--model' => 'openai:gpt-5',
            '--key' => 'test-key',
        ]), $output);

        self::assertTrue($application->has('codex'));
        self::assertSame(0, $exitCode);
        self::assertSame("Factory test response\n", $output->fetch());
    }

    public function testApplicationFactorySetsCurrentWorkingDirectoryOnDefaultCommand(): void
    {
        $application = CodexApplicationFactory::create();
        $command = $application->find('codex');
        $property = new ReflectionProperty($command, 'config');

        /** @var mixed $config */
        $config = $property->getValue($command);

        self::assertInstanceOf(\Armin\CodexPhp\CodexConfig::class, $config);
        self::assertSame(getcwd(), $config->workingDirectory());
    }
}
