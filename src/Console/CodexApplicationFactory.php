<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Console;

use Symfony\Component\Console\Application;

final class CodexApplicationFactory
{
    public static function create(?CodexRunCommand $command = null): Application
    {
        $application = new Application('codex');
        $application->addCommand($command ?? new CodexRunCommand());
        $application->setDefaultCommand('codex', true);

        return $application;
    }
}
