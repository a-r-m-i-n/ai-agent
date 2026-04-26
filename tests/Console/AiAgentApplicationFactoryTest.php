<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tests\Console;

use Armin\AiAgent\AiAgentClient;
use Armin\AiAgent\AiAgentResponse;
use Armin\AiAgent\Console\AiAgentRunCommand;
use Armin\AiAgent\Console\AiAgentApplicationFactory;
use Armin\AiAgent\Internal\AiAgentRuntimeInterface;
use ReflectionProperty;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class AiAgentApplicationFactoryTest extends TestCase
{
    public function testApplicationRunsAiAgentCommandAsDefault(): void
    {
        $runtime = new class implements AiAgentRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): AiAgentResponse
            {
                return new AiAgentResponse('Factory test response', 'openai:gpt-5');
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                throw new \BadMethodCallException('not used');
            }
        };

        $application = AiAgentApplicationFactory::create(new AiAgentRunCommand(client: new AiAgentClient(runtime: $runtime)));
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(new ArrayInput([
            'prompt' => 'Smoke test',
            '--model' => 'openai:gpt-5',
            '--key' => 'test-key',
        ]), $output);

        self::assertTrue($application->has('ai-agent'));
        self::assertSame(0, $exitCode);
        self::assertSame("Factory test response\n", $output->fetch());
    }

    public function testApplicationFactorySetsCurrentWorkingDirectoryOnDefaultCommand(): void
    {
        $application = AiAgentApplicationFactory::create();
        $command = $application->find('ai-agent');
        $property = new ReflectionProperty($command, 'config');

        /** @var mixed $config */
        $config = $property->getValue($command);

        self::assertInstanceOf(\Armin\AiAgent\AiAgentConfig::class, $config);
        self::assertSame(getcwd(), $config->workingDirectory());
    }
}
