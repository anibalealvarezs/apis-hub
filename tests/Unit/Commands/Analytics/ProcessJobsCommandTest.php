<?php

declare(strict_types=1);

namespace Tests\Unit\Commands\Analytics;

use Commands\Analytics\ProcessJobsCommand;
use Doctrine\ORM\EntityManager;
use Entities\Job;
use Enums\JobStatus;
use PHPUnit\Framework\TestCase;
use Repositories\JobRepository;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ProcessJobsCommandTest extends TestCase
{
    private $entityManager;
    private $jobRepository;
    private $command;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->jobRepository = $this->createMock(JobRepository::class);

        $this->entityManager->method('getRepository')
            ->with(Job::class)
            ->willReturn($this->jobRepository);

        // We need to bypass the constructor's Helpers::getManager()
        $this->command = $this->getMockBuilder(ProcessJobsCommand::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        // Manual construction logic simulation
        $reflection = new \ReflectionClass(ProcessJobsCommand::class);
        $emProperty = $reflection->getProperty('em');
        $emProperty->setAccessible(true);
        $emProperty->setValue($this->command, $this->entityManager);

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $this->entityManager->method('getConnection')->willReturn($connection);
    }

    public function testExecuteSkipsJobWhenDependencyNotMet(): void
    {
        $jobId = 123;
        $uuid = 'test-uuid';
        $mockJob = $this->createMock(Job::class);
        $mockJob->method('getId')->willReturn($jobId);
        $mockJob->method('getUuid')->willReturn($uuid);
        $mockJob->method('getStatus')->willReturn(JobStatus::scheduled->value);
        $mockJob->method('getPayload')->willReturn([
            'params' => [
                'requires' => 'other-instance'
            ]
        ]);

        $this->jobRepository->method('getJobsByStatus')
            ->willReturnOnConsecutiveCalls([$mockJob], []);
        
        // Mock hasSuccessfulRecentJob to return false
        $this->jobRepository->expects($this->once())
            ->method('hasSuccessfulRecentJob')
            ->with('other-instance')
            ->willReturn(false);

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        // Use reflection to call protected execute
        $method = new \ReflectionMethod(ProcessJobsCommand::class, 'execute');
        $method->setAccessible(true);
        $result = $method->invoke($this->command, $input, $output);

        $this->assertEquals(0, $result);
        $this->assertStringContainsString("depends on 'other-instance' which has no successful recent execution. Skipping.", $output->fetch());
    }

    public function testExecuteProcessesJobWhenDependencyMet(): void
    {
        $jobId = 123;
        $uuid = 'test-uuid';
        $mockJob = $this->createMock(Job::class);
        $mockJob->method('getId')->willReturn($jobId);
        $mockJob->method('getUuid')->willReturn($uuid);
        $mockJob->method('getStatus')->willReturn(JobStatus::scheduled->value);
        $mockJob->method('getChannel')->willReturn('facebook');
        $mockJob->method('getEntity')->willReturn('metric');
        $mockJob->method('getPayload')->willReturn([
            'params' => [
                'requires' => 'other-instance'
            ]
        ]);

        $this->jobRepository->method('getJobsByStatus')
            ->willReturnOnConsecutiveCalls([$mockJob], []);
        
        // Mock hasSuccessfulRecentJob to return true
        $this->jobRepository->expects($this->once())
            ->method('hasSuccessfulRecentJob')
            ->with('other-instance')
            ->willReturn(true);

        // Mock claimJob to fail here just to avoid full execution logic in this specific small test
        // or mock the rest of the dependencies if we want full test.
        $this->jobRepository->method('claimJob')->willReturn(false); 

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $method = new \ReflectionMethod(ProcessJobsCommand::class, 'execute');
        $method->setAccessible(true);
        $method->invoke($this->command, $input, $output);

        $out = $output->fetch();
        $this->assertStringNotContainsString("has no successful recent execution. Skipping.", $out);
        $this->assertStringContainsString("Processing job test-uuid", $out);
    }
}
