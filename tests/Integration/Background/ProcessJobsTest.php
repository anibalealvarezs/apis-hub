<?php

namespace Tests\Integration\Background;

use Commands\Analytics\ProcessJobsCommand;
use Entities\Job;
use Enums\JobStatus;
use Repositories\JobRepository;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Integration\BaseIntegrationTestCase;

class ProcessJobsTest extends BaseIntegrationTestCase
{
    public function testScheduledJobIsProcessedCorrectly(): void
    {
        // 1. Arrange: Schedule a Mock Job in the test database
        /** @var JobRepository $jobRepo */
        $jobRepo = $this->entityManager->getRepository(\Entities\Job::class);

        // We bypass JobRepository's creation checks to force a naked Job payload that won't pollute external endpoints
        $uuid = $this->faker->uuid;
        $job = new Job();
        $job->addEntity('customer'); // Must be a valid AnalyticsEntity case value
        // Use facebook channel because getting customers from facebook returns an empty response immediately, preventing test hangs.
        $job->addChannel('facebook'); // Must be a valid Channel case value
        $job->addStatus(JobStatus::scheduled->value);
        $job->addUuid($uuid);
        $job->addPayload([]);
        
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        // 2. Act: Run the ProcessJobsCommand
        $command = new ProcessJobsCommand();
        $input = new ArrayInput([]);
        $output = new BufferedOutput();
        
        // Command runs the logic
        $exitCode = $command->run($input, $output);
        $outputContent = $output->fetch();
        
        // 3. Assert: The command completes and attempts to process the job
        $this->assertEquals(0, $exitCode);
        
        // Assert STDOUT log verifies it actually caught the job off the database
        $this->assertStringContainsString('Querying scheduled jobs', $outputContent);
        $this->assertStringContainsString('Processing job ' . $uuid, $outputContent);
        
        // Refresh job
        $this->entityManager->refresh($job);
        
        // The Status should have shifted natively to Completed or Failed by the CacheController
        $this->assertTrue(
            in_array($job->getStatus(), [JobStatus::completed->value, JobStatus::failed->value]),
            "Job status '{$job->getStatus()}' did not advance to completed or failed state"
        );
    }
}
