<?php

declare(strict_types=1);

namespace Armin\CodexPhp\Console;

use Symfony\Component\Console\Application;

final class CodexApplicationFactory
{
    public static function create(): Application
    {
        $application = new Application('codex');
        $application->addCommand(new CodexRunCommand());
        $application->setDefaultCommand('codex', true);

        return $application;
    }
}
