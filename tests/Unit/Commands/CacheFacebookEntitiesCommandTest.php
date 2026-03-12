<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Commands\CacheFacebookEntitiesCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class CacheFacebookEntitiesCommandTest extends TestCase
{
    public function testCommandName(): void
    {
        $application = new Application();
        $application->add(new CacheFacebookEntitiesCommand());

        $command = $application->find('app:cache-facebook-entities');
        $this->assertInstanceOf(CacheFacebookEntitiesCommand::class, $command);
    }
}
