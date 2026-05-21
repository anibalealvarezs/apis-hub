<?php

declare(strict_types=1);

namespace Tests\Unit\Commands\Analytics;

use Commands\Analytics\RetryFailedJobsCommand;
use Doctrine\ORM\EntityManager;
use Entities\Job;
use Enums\JobStatus;
use Helpers\Helpers;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Repositories\JobRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RetryFailedJobsCommandTest extends TestCase
{
    private $jobRepository;
    private RetryFailedJobsCommand $command;
    private $entityManager;

    protected function setUp(): void
    {
        Helpers::resetConfigs();

        $this->entityManager = $this->createMock(EntityManager::class);
        $this->jobRepository = $this->createMock(JobRepository::class);

        $this->entityManager->method('getRepository')
            ->with(Job::class)
            ->willReturn($this->jobRepository);

        Helpers::setEntityManager($this->entityManager);

        $this->command = new RetryFailedJobsCommand($this->entityManager);
    }

    public function testExecuteWithoutChannelReschedulesAllFailedJobs(): void
    {
        $mockJob1 = new Job();
        $mockJob2 = new Job();
        
        $this->jobRepository->expects($this->once())
            ->method('findBy')
            ->with(['status' => JobStatus::failed->value])
            ->willReturn([$mockJob1, $mockJob2]);

        $this->entityManager->expects($this->exactly(2))
            ->method('persist');
            
        $this->entityManager->expects($this->once())
            ->method('flush');

        $input = new ArrayInput([], $this->command->getDefinition());
        $output = new BufferedOutput();

        $method = new ReflectionMethod(RetryFailedJobsCommand::class, 'execute');
        $method->setAccessible(true);
        $result = $method->invoke($this->command, $input, $output);

        $this->assertEquals(Command::SUCCESS, $result);
        $this->assertStringContainsString("Rescheduling 2 failed jobs...", $output->fetch());
        
        $this->assertEquals(JobStatus::scheduled->value, $mockJob1->getStatus());
        $this->assertEquals(JobStatus::scheduled->value, $mockJob2->getStatus());
    }

    public function testExecuteWithChannelReschedulesFilteredJobs(): void
    {
        $channel = 'facebook';
        $mockJob1 = new Job();
        
        $this->jobRepository->expects($this->once())
            ->method('findBy')
            ->with(['status' => JobStatus::failed->value, 'channel' => $channel])
            ->willReturn([$mockJob1]);

        $this->entityManager->expects($this->once())
            ->method('persist');
            
        $this->entityManager->expects($this->once())
            ->method('flush');

        $input = new ArrayInput(['--channel' => $channel], $this->command->getDefinition());
        $output = new BufferedOutput();

        $method = new ReflectionMethod(RetryFailedJobsCommand::class, 'execute');
        $method->setAccessible(true);
        $result = $method->invoke($this->command, $input, $output);

        $this->assertEquals(Command::SUCCESS, $result);
        $this->assertStringContainsString("Rescheduling 1 failed jobs...", $output->fetch());
    }

    public function testExecuteHandlesEmptyResultSetGracefully(): void
    {
        $this->jobRepository->expects($this->once())
            ->method('findBy')
            ->willReturn([]);

        $this->entityManager->expects($this->never())
            ->method('persist');
            
        $this->entityManager->expects($this->never())
            ->method('flush');

        $input = new ArrayInput([], $this->command->getDefinition());
        $output = new BufferedOutput();

        $method = new ReflectionMethod(RetryFailedJobsCommand::class, 'execute');
        $method->setAccessible(true);
        $result = $method->invoke($this->command, $input, $output);

        $this->assertEquals(Command::SUCCESS, $result);
        $this->assertStringContainsString("No failed jobs found to retry.", $output->fetch());
    }

    public function testExecuteCatchesExceptions(): void
    {
        $this->jobRepository->expects($this->once())
            ->method('findBy')
            ->willThrowException(new \Exception("Database failed"));

        $input = new ArrayInput([], $this->command->getDefinition());
        $output = new BufferedOutput();

        $method = new ReflectionMethod(RetryFailedJobsCommand::class, 'execute');
        $method->setAccessible(true);
        $result = $method->invoke($this->command, $input, $output);

        $this->assertEquals(Command::FAILURE, $result);
        $this->assertStringContainsString("Error: Database failed", $output->fetch());
    }
}
