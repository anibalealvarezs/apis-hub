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
        
        // Capture and clear env to ensure isolation doesn't filter out the test job
        $oldSource = getenv('API_SOURCE');
        $oldEntity = getenv('API_ENTITY');
        $oldStart = getenv('START_DATE');
        $oldEnd = getenv('END_DATE');
        putenv('API_SOURCE');
        putenv('API_ENTITY');
        putenv('START_DATE');
        putenv('END_DATE');

        // Command runs the logic
        $exitCode = $command->run($input, $output);
        $outputContent = $output->fetch();

        // Restore env
        if ($oldSource) putenv("API_SOURCE=$oldSource");
        if ($oldEntity) putenv("API_ENTITY=$oldEntity");
        if ($oldStart) putenv("START_DATE=$oldStart");
        if ($oldEnd) putenv("END_DATE=$oldEnd");
        
        // 3. Assert: The command completes and attempts to process the job
        $this->assertEquals(0, $exitCode);
        
        // Assert STDOUT log verifies it actually caught the job off the database
        $this->assertStringContainsString('Querying scheduled and delayed jobs', $outputContent);
        $this->assertStringContainsString('Processing job ' . $uuid, $outputContent);
        
        // Re-fetch job to ensure we have the updated state from DB (detached after EM clear in command)
        $job = $jobRepo->findOneBy(['uuid' => $uuid]);
        
        // The Status should have shifted natively to Completed or Failed by the CacheController
        $this->assertTrue(
            in_array($job->getStatus(), [JobStatus::completed->value, JobStatus::failed->value]),
            "Job status '{$job->getStatus()}' did not advance to completed or failed state"
        );
    }
}
