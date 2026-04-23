<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Tests\Console;

use Armin\CodexPhp\Console\CodexRunCommand;
use Armin\CodexPhp\Exception\MissingApiKey;
use Armin\CodexPhp\Exception\MissingModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CodexRunCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('CODEX_API_KEY');
        putenv('CODEX_DEFAULT_MODEL');
    }

    public function testCommandOutputsJsonUsingEnvironmentDefaults(): void
    {
        putenv('CODEX_API_KEY=test-key');
        putenv('CODEX_DEFAULT_MODEL=gpt-5');

        $tester = new CommandTester(new CodexRunCommand());
        $tester->execute([
            'prompt' => 'Say hello',
        ]);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('simulation', $payload['mode']);
        self::assertSame('Say hello', $payload['prompt']);
        self::assertSame('gpt-5', $payload['model']);
        self::assertSame('env', $payload['model_source']);
        self::assertSame('env', $payload['api_key_source']);
        self::assertSame('te****ey', $payload['api_key_masked']);
    }

    public function testOptionsOverrideEnvironmentValues(): void
    {
        putenv('CODEX_API_KEY=env-key');
        putenv('CODEX_DEFAULT_MODEL=env-model');

        $tester = new CommandTester(new CodexRunCommand());
        $tester->execute([
            'prompt' => 'Say hello',
            '--model' => 'gpt-5.1',
            '--key' => 'override-key',
        ]);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('gpt-5.1', $payload['model']);
        self::assertSame('option', $payload['model_source']);
        self::assertSame('option', $payload['api_key_source']);
        self::assertSame('ov********ey', $payload['api_key_masked']);
    }

    public function testMissingModelThrows(): void
    {
        putenv('CODEX_API_KEY=test-key');

        $tester = new CommandTester(new CodexRunCommand());

        $this->expectException(MissingModel::class);

        $tester->execute([
            'prompt' => 'Say hello',
        ]);
    }

    public function testMissingApiKeyThrows(): void
    {
        putenv('CODEX_DEFAULT_MODEL=gpt-5');

        $tester = new CommandTester(new CodexRunCommand());

        $this->expectException(MissingApiKey::class);

        $tester->execute([
            'prompt' => 'Say hello',
        ]);
    }
}
