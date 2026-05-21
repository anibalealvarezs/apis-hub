<?php

declare(strict_types=1);

namespace Tests\Unit\Commands\Analytics;

use Commands\Analytics\InspectJobsCommand;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Entities\Analytics\Channel;
use Enums\JobStatus;
use Helpers\Helpers;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class InspectJobsCommandTest extends TestCase
{
    private InspectJobsCommand $command;
    private $entityManager;
    private $connection;

    protected function setUp(): void
    {
        Helpers::resetConfigs();

        $this->entityManager = $this->createMock(EntityManager::class);
        $this->connection = $this->createMock(Connection::class);

        $this->entityManager->method('getConnection')
            ->willReturn($this->connection);

        Helpers::setEntityManager($this->entityManager);

        $this->command = new InspectJobsCommand($this->entityManager);
    }

    public function testExecuteOutputsStatisticsWhenJobsExist(): void
    {
        $this->connection->expects($this->exactly(2))
            ->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    ['status' => JobStatus::failed->value, 'count' => 10],
                    ['status' => JobStatus::completed->value, 'count' => 45]
                ],
                [
                    ['channel' => 'facebook', 'count' => 5],
                    ['channel' => 'unknown_channel', 'count' => 5]
                ]
            );

        $input = new ArrayInput([], $this->command->getDefinition());
        $output = new BufferedOutput();

        $method = new ReflectionMethod(InspectJobsCommand::class, 'execute');
        $method->setAccessible(true);
        $result = $method->invoke($this->command, $input, $output);

        $this->assertEquals(Command::SUCCESS, $result);
        $out = $output->fetch();
        
        $this->assertStringContainsString("Job Queue Statistics", $out);
        $this->assertStringContainsString("failed: 10", $out);
        $this->assertStringContainsString("completed: 45", $out);
        $this->assertStringContainsString("Failed Jobs (Last 24h) by Channel:", $out);
        $this->assertStringContainsString("facebook: 5", $out);
        $this->assertStringContainsString("unknown_channel: 5", $out);
    }

    public function testExecuteHandlesEmptyDatabase(): void
    {
        $this->connection->expects($this->exactly(2))
            ->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls([], []);

        $input = new ArrayInput([], $this->command->getDefinition());
        $output = new BufferedOutput();

        $method = new ReflectionMethod(InspectJobsCommand::class, 'execute');
        $method->setAccessible(true);
        $result = $method->invoke($this->command, $input, $output);

        $this->assertEquals(Command::SUCCESS, $result);
        $out = $output->fetch();
        
        $this->assertStringContainsString("Job Queue Statistics", $out);
        $this->assertStringContainsString("No jobs found in database.", $out);
        $this->assertStringNotContainsString("Failed Jobs (Last 24h) by Channel:", $out);
    }

    public function testExecuteHandlesExceptionAndFails(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->willThrowException(new \Exception("DB Connection Error"));

        $input = new ArrayInput([], $this->command->getDefinition());
        $output = new BufferedOutput();

        $method = new ReflectionMethod(InspectJobsCommand::class, 'execute');
        $method->setAccessible(true);
        $result = $method->invoke($this->command, $input, $output);

        $this->assertEquals(Command::FAILURE, $result);
        $out = $output->fetch();
        $this->assertStringContainsString("Error: DB Connection Error", $out);
    }
}
