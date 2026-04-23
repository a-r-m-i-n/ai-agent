<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Console;

use Armin\CodexPhp\Console\CodexApplicationFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class CodexApplicationFactoryTest extends TestCase
{
    public function testApplicationRunsCodexCommandAsDefault(): void
    {
        $application = CodexApplicationFactory::create();
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(new ArrayInput([
            'prompt' => 'Smoke test',
            '--model' => 'gpt-5',
            '--key' => 'test-key',
        ]), $output);

        self::assertTrue($application->has('codex'));
        self::assertSame(0, $exitCode);
        self::assertStringContainsString('"prompt": "Smoke test"', $output->fetch());
    }
}
