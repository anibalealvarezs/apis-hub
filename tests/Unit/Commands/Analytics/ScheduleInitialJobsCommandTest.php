<?php

declare(strict_types=1);

namespace Tests\Unit\Commands\Analytics;

use Commands\Analytics\ScheduleInitialJobsCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManager;
use Entities\Analytics\Channel;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Job;
use Enums\JobStatus;
use Helpers\Helpers;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Repositories\JobRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use ReflectionClass;

class ScheduleInitialJobsCommandTest extends TestCase
{
    private ScheduleInitialJobsCommand $command;
    private $entityManager;
    private $connection;

    protected function setUp(): void
    {
        Helpers::resetConfigs();

        // Set debug mode to true for tests to capture debug output
        $helpersReflection = new ReflectionClass(Helpers::class);
        $projectConfigProperty = $helpersReflection->getProperty('projectConfig');
        $projectConfigProperty->setAccessible(true);
        $projectConfigProperty->setValue(null, ['debug' => true]);

        $this->entityManager = $this->createMock(EntityManager::class);
        $this->connection = $this->createMock(Connection::class);

        $this->entityManager->method('getConnection')
            ->willReturn($this->connection);

        $this->command = new ScheduleInitialJobsCommand($this->entityManager);
    }

    public function testExecuteWithNoInstancesReturnsSuccess(): void
    {
        $input = new ArrayInput([], $this->command->getDefinition());
        $output = new BufferedOutput();

        $method = new ReflectionMethod(ScheduleInitialJobsCommand::class, 'execute');
        $method->setAccessible(true);
        $result = $method->invoke($this->command, $input, $output);

        $this->assertEquals(Command::SUCCESS, $result);
        $this->assertStringContainsString("No instances found in project configuration.", $output->fetch());
    }

    public function testExecuteSkipsInstanceWithMissingChannel(): void
    {
        $helpersReflection = new ReflectionClass(Helpers::class);
        $projectConfigProperty = $helpersReflection->getProperty('projectConfig');
        $projectConfigProperty->setAccessible(true);
        $projectConfigProperty->setValue(null, [
            'debug' => true,
            'instances' => [
                ['name' => 'test-instance', 'entity' => 'metrics'] // Missing channel
            ]
        ]);

        $input = new ArrayInput([], $this->command->getDefinition());
        $output = new BufferedOutput();

        $method = new ReflectionMethod(ScheduleInitialJobsCommand::class, 'execute');
        $method->setAccessible(true);
        $result = $method->invoke($this->command, $input, $output);

        $this->assertEquals(Command::SUCCESS, $result);
        $out = $output->fetch();
        $this->assertStringContainsString("Instance test-instance is missing channel or entity. Skipping.", $out);
        $this->assertStringContainsString("Successfully scheduled 0 new jobs", $out);
    }

    public function testExecuteSkipsDisabledChannel(): void
    {
        $helpersReflection = new ReflectionClass(Helpers::class);
        $projectConfigProperty = $helpersReflection->getProperty('projectConfig');
        $projectConfigProperty->setAccessible(true);
        $projectConfigProperty->setValue(null, [
            'debug' => true,
            'instances' => [
                ['name' => 'test-instance', 'channel' => 'facebook', 'entity' => 'metrics']
            ]
        ]);
        
        $channelsConfigProperty = $helpersReflection->getProperty('channelsConfig');
        $channelsConfigProperty->setAccessible(true);
        $channelsConfigProperty->setValue(null, [
            'facebook' => ['enabled' => false]
        ]);

        $input = new ArrayInput([], $this->command->getDefinition());
        $output = new BufferedOutput();

        $method = new ReflectionMethod(ScheduleInitialJobsCommand::class, 'execute');
        $method->setAccessible(true);
        $result = $method->invoke($this->command, $input, $output);

        $this->assertEquals(Command::SUCCESS, $result);
        $out = $output->fetch();
        $this->assertStringContainsString("Skipping test-instance: channel facebook is disabled.", $out);
    }

    public function testExecuteSchedulesNewJobWhenNoPreviousJobExists(): void
    {
        $helpersReflection = new ReflectionClass(Helpers::class);
        $projectConfigProperty = $helpersReflection->getProperty('projectConfig');
        $projectConfigProperty->setAccessible(true);
        $projectConfigProperty->setValue(null, [
            'debug' => true,
            'instances' => [
                [
                    'name' => 'test-instance', 
                    'channel' => 'facebook', 
                    'entity' => 'metrics'
                ]
            ]
        ]);
        
        $channelsConfigProperty = $helpersReflection->getProperty('channelsConfig');
        $channelsConfigProperty->setAccessible(true);
        $channelsConfigProperty->setValue(null, [
            'facebook' => ['enabled' => true]
        ]);

        // Mock no existing job found
        $this->connection->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(false);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function ($job) {
                return $job instanceof Job && 
                       $job->getChannel() === 'facebook' &&
                       $job->getEntity() === 'metrics' &&
                       $job->getStatus() === JobStatus::scheduled->value;
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Set postgres helper to true to hit the connection branch
        putenv('DB_CONNECTION=pgsql');

        $input = new ArrayInput([], $this->command->getDefinition());
        $output = new BufferedOutput();

        $method = new ReflectionMethod(ScheduleInitialJobsCommand::class, 'execute');
        $method->setAccessible(true);
        $result = $method->invoke($this->command, $input, $output);

        $this->assertEquals(Command::SUCCESS, $result);
        $out = $output->fetch();
        $this->assertStringContainsString("Created initial scheduled job for test-instance", $out);
        $this->assertStringContainsString("Successfully scheduled 1 new jobs", $out);
    }
}
