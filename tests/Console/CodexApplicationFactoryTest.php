<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Console;

use Armin\CodexPhp\CodexClient;
use Armin\CodexPhp\CodexResponse;
use Armin\CodexPhp\Console\CodexRunCommand;
use Armin\CodexPhp\Console\CodexApplicationFactory;
use Armin\CodexPhp\Internal\CodexRuntimeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class CodexApplicationFactoryTest extends TestCase
{
    public function testApplicationRunsCodexCommandAsDefault(): void
    {
        $runtime = new class implements CodexRuntimeInterface {
            public function request(string $prompt): CodexResponse
            {
                return new CodexResponse('Factory test response', 'openai:gpt-5');
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
}
