<?php

declare(strict_types=1);

namespace Tests\Unit\Commands\Analytics;

use Commands\Analytics\ResetStuckJobsCommand;
use Doctrine\ORM\EntityManager;
use Entities\Job;
use Helpers\Helpers;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Repositories\JobRepository;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ResetStuckJobsCommandTest extends TestCase
{
    private $jobRepository;
    private ResetStuckJobsCommand $command;
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

        $this->command = new ResetStuckJobsCommand($this->entityManager);
    }

    public function testExecuteResetsOrphanedJobs(): void
    {
        $threshold = 60;
        
        $this->jobRepository->expects($this->once())
            ->method('resetAllOrphanedJobs')
            ->with($threshold)
            ->willReturn(5); // 5 jobs reset

        $input = new ArrayInput(['--threshold' => $threshold], $this->command->getDefinition());
        $output = new BufferedOutput();

        $method = new ReflectionMethod(ResetStuckJobsCommand::class, 'execute');
        $method->setAccessible(true);
        $result = $method->invoke($this->command, $input, $output);

        $this->assertEquals(0, $result);
        $out = $output->fetch();
        $this->assertStringContainsString("Resetting orphaned jobs (threshold: {$threshold}m)", $out);
        $this->assertStringContainsString("Successfully reset 5 orphaned jobs", $out);
    }
}
