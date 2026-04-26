<?php

declare(strict_types=1);

namespace Armin\AiAgent\Console;

use Symfony\Component\Console\Application;

final class AiAgentApplicationFactory
{
    public static function create(?AiAgentRunCommand $command = null): Application
    {
        $application = new Application('ai-agent');
        $application->addCommand($command ?? new AiAgentRunCommand());
        $application->setDefaultCommand('ai-agent', true);

        return $application;
    }
}
