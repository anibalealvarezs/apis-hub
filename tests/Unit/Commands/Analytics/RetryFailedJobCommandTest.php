<?php

declare(strict_types=1);

namespace Tests\Unit\Commands\Analytics;

use Commands\Analytics\RetryFailedJobCommand;
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

class RetryFailedJobCommandTest extends TestCase
{
    private $jobRepository;
    private RetryFailedJobCommand $command;
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

        $this->command = new RetryFailedJobCommand($this->entityManager);
    }

    public function testExecuteFailsIfJobNotFound(): void
    {
        $jobId = 999;
        
        $this->jobRepository->expects($this->once())
            ->method('find')
            ->with($jobId)
            ->willReturn(null);

        $input = new ArrayInput(['job_id' => $jobId], $this->command->getDefinition());
        $output = new BufferedOutput();

        $method = new ReflectionMethod(RetryFailedJobCommand::class, 'execute');
        $method->setAccessible(true);
        $result = $method->invoke($this->command, $input, $output);

        $this->assertEquals(Command::FAILURE, $result);
        $this->assertStringContainsString("Job with ID {$jobId} not found.", $output->fetch());
    }

    public function testExecuteFailsIfJobNotFailed(): void
    {
        $jobId = 123;
        $mockJob = new Job();
        $mockJob->addStatus(JobStatus::completed->value);
        
        $this->jobRepository->expects($this->once())
            ->method('find')
            ->with($jobId)
            ->willReturn($mockJob);

        $input = new ArrayInput(['job_id' => $jobId], $this->command->getDefinition());
        $output = new BufferedOutput();

        $method = new ReflectionMethod(RetryFailedJobCommand::class, 'execute');
        $method->setAccessible(true);
        $result = $method->invoke($this->command, $input, $output);

        $this->assertEquals(Command::FAILURE, $result);
        $this->assertStringContainsString("Job {$jobId} is not in a failed state", $output->fetch());
    }

    public function testExecuteRetriesJobSuccessfully(): void
    {
        $jobId = 123;
        
        // Use a real entity to allow cloning without mock proxy issues
        $mockJob = new Job();
        $mockJob->addStatus(JobStatus::failed->value);
        
        $reflection = new \ReflectionClass(Job::class);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($mockJob, $jobId);
        
        $this->jobRepository->expects($this->once())
            ->method('find')
            ->with($jobId)
            ->willReturn($mockJob);

        $this->entityManager->expects($this->exactly(2))
            ->method('persist');
            
        $this->entityManager->expects($this->exactly(2))
            ->method('flush');

        $input = new ArrayInput(['job_id' => $jobId], $this->command->getDefinition());
        $output = new BufferedOutput();

        $method = new ReflectionMethod(RetryFailedJobCommand::class, 'execute');
        $method->setAccessible(true);
        $result = $method->invoke($this->command, $input, $output);

        $this->assertEquals(Command::SUCCESS, $result);
        $this->assertStringContainsString("Successfully created new job", $output->fetch());
    }
}
