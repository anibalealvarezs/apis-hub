<?php

namespace Tests\Integration\Sync;

use Commands\Analytics\ScheduleInitialJobsCommand;
use Entities\Job;
use Enums\JobStatus;
use Helpers\Helpers;
use Repositories\JobRepository;
use Services\Sync\SyncTelemetryService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Integration\BaseIntegrationTestCase;

class SyncProcessTest extends BaseIntegrationTestCase
{
    /** @var JobRepository */
    private $jobRepo;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure Helpers uses the SAME EntityManager as the test to see seeded data
        $reflection = new \ReflectionClass(Helpers::class);
        $property = $reflection->getProperty('entityManager');
        $property->setAccessible(true);
        $property->setValue(null, $this->entityManager);

        $this->jobRepo = $this->entityManager->getRepository(Job::class);
        $this->entityManager->flush();

        // CLEANUP: Only remove jobs related to our test markers
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement("
            DELETE FROM jobs 
            WHERE worker_id IN ('worker-1', 'worker-2', 'dead-worker', 'new-worker') 
               OR payload->>'account_id' LIKE 'test_acc_%'
               OR payload->>'instance_name' LIKE 'test_instance%'
        ");
        
        // Reset configs
        Helpers::resetConfigs();
    }

    /**
     * Test 1: Verify that ScheduleInitialJobsCommand schedules jobs correctly.
     */
    public function testInitialJobScheduling()
    {
        $mockChannel = 'facebook_marketing';
        $this->seedChannel($mockChannel, 'facebook', 'Facebook Marketing');

        // Mock configurations using Reflection
        $this->mockConfigurations([
            'instances' => [
                'test_instance_initial' => [
                    'enabled' => true,
                    'channels' => [$mockChannel => ['enabled' => true]]
                ]
            ]
        ], [
            $mockChannel => [
                'enabled' => true,
                'entities' => ['campaign' => ['enabled' => true]]
            ]
        ]);

        $command = new ScheduleInitialJobsCommand();
        $command->run(new ArrayInput([]), new NullOutput());

        $jobs = $this->jobRepo->findAll();
        $this->assertGreaterThan(0, count($jobs), "Jobs should be scheduled for the mock channel");
        
        $job = $jobs[0];
        $this->assertEquals($mockChannel, $job->getChannel());
        $this->assertEquals('campaign', $job->getEntity());
    }

    /**
     * Test 2: Verify parallel job claiming logic.
     */
    public function testParallelJobClaiming()
    {
        $fbChannel = 'facebook_marketing';
        $googleChannel = 'google_search_console';
        
        $this->seedChannel($fbChannel, 'facebook', 'Facebook Marketing');
        $this->seedChannel($googleChannel, 'google', 'Google Search Console');

        // Create two jobs for different channels but same instance/account
        $job1Data = $this->jobRepo->create((object)[
            'entity' => 'campaign',
            'channel' => $fbChannel,
            'payload' => ['account_id' => 'test_acc_123', 'instance_name' => 'test_instance_parallel']
        ]);
        $job2Data = $this->jobRepo->create((object)[
            'entity' => 'page',
            'channel' => $googleChannel,
            'payload' => ['account_id' => 'test_acc_123', 'instance_name' => 'test_instance_parallel']
        ]);
        $this->entityManager->flush();

        // Worker 1 claims a job
        $claimed1 = $this->jobRepo->claimAvailableJob([JobStatus::scheduled->value], 'worker-1', 'test_instance_parallel');
        $this->assertNotNull($claimed1, "Worker 1 should claim a job");

        // Worker 2 should still be able to claim the other job because it's a different channel
        $claimed2 = $this->jobRepo->claimAvailableJob([JobStatus::scheduled->value], 'worker-2', 'test_instance_parallel');
        $this->assertNotNull($claimed2, "Worker 2 should claim the second job in parallel");
        
        $this->assertNotEquals($claimed1->getId(), $claimed2->getId());
    }

    /**
     * Test 3: Verify telemetry aggregation logic.
     */
    public function testTelemetryAggregation()
    {
        $fbDriver = new \Anibalealvarezs\MetaHubDriver\Drivers\FacebookMarketingDriver();
        $fbChannelName = $fbDriver->getChannel();
        $fbEntity = \Anibalealvarezs\MetaHubDriver\Enums\MetaEntityType::CAMPAIGN->value;

        $this->seedChannel($fbChannelName, $fbDriver->getProviderName(), $fbDriver->getChannelLabel());

        // 1. Create mixed jobs for Facebook with unique test IDs
        $testInstance = 'test_instance_telemetry';
        
        // Completed
        $this->jobRepo->create((object)[
            'entity' => $fbEntity, 'channel' => $fbChannelName, 'status' => JobStatus::completed->value,
            'payload' => ['account_id' => 'test_acc_telemetry_1', 'instance_name' => $testInstance]
        ]);
        // Processing
        $this->jobRepo->create((object)[
            'entity' => $fbEntity, 'channel' => $fbChannelName, 'status' => JobStatus::processing->value,
            'payload' => ['account_id' => 'test_acc_telemetry_2', 'instance_name' => $testInstance]
        ]);
        // Scheduled
        $this->jobRepo->create((object)[
            'entity' => $fbEntity, 'channel' => $fbChannelName, 'status' => JobStatus::scheduled->value,
            'payload' => ['account_id' => 'test_acc_telemetry_3', 'instance_name' => $testInstance]
        ]);
        $this->entityManager->flush();

        // 2. Get Telemetry
        $redis = Helpers::getRedisClient();
        $cache = new \Services\CacheService($redis);
        $cache->deletePattern('telemetry_*'); // Clear cache to force fresh data

        $service = new SyncTelemetryService($cache);
        $telemetry = $service->getGlobalStatus();

        // 3. Verify counts for our specific test markers
        $fbStats = null;
        $channels = $telemetry['channels'] ?? [];
        foreach ($channels as $key => $item) {
            if (is_array($item) && isset($item['channel']) && strtolower($item['channel']) === strtolower($fbChannelName)) {
                $fbStats = $item;
                break;
            }
        }

        if (!$fbStats) {
            $available = array_keys($channels);
            $this->fail("Facebook Marketing stats not found in 'channels'. Available: " . implode(', ', $available));
        }

        $this->assertEquals(3, $fbStats['total_jobs'], "Total jobs count should match");
        $this->assertEquals(1, $fbStats['completed'], "Done jobs count should match");
        $this->assertEquals(1, $fbStats['processing'], "Active jobs count should match");
    }

    /**
     * Test 4: Verify that workers recover jobs after an abrupt shutdown.
     */
    public function testWorkerRecoveryAfterAbruptShutdown()
    {
        $fbDriver = new \Anibalealvarezs\MetaHubDriver\Drivers\FacebookMarketingDriver();
        $fbChannelName = $fbDriver->getChannel();
        $fbEntity = \Anibalealvarezs\MetaHubDriver\Enums\MetaEntityType::CAMPAIGN->value;

        $this->seedChannel($fbChannelName, $fbDriver->getProviderName(), $fbDriver->getChannelLabel());

        // 1. Create a job and mark it as processing
        $jobData = $this->jobRepo->create((object)[
            'entity' => $fbEntity,
            'channel' => $fbChannelName,
            'status' => JobStatus::processing->value,
            'payload' => ['account_id' => 'test_acc_resilient', 'instance_name' => 'test_instance_resilient'],
            'worker_id' => 'dead-worker'
        ]);
        $this->entityManager->flush();
        
        $jobId = is_array($jobData) ? $jobData['id'] : $jobData->getId();

        // 2. Backdate
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement("UPDATE jobs SET updated_at = NOW() - INTERVAL '11 minutes' WHERE id = ?", [$jobId]);

        // 3. Re-claim
        $newJob = $this->jobRepo->claimAvailableJob(
            status: [JobStatus::scheduled->value],
            workerId: 'new-worker',
            instanceName: 'test_instance_resilient'
        );

        $this->assertNotNull($newJob, "The stale job should have been reclaimed");
        $this->assertEquals($jobId, $newJob->getId());
        $this->assertEquals('new-worker', $newJob->getWorkerId());
    }

    private function seedChannel(string $name, string $providerName, string $label): void
    {
        $channelRepo = $this->entityManager->getRepository(\Entities\Analytics\Channel::class);
        $providerRepo = $this->entityManager->getRepository(\Entities\Analytics\Provider::class);

        $provider = $providerRepo->findOneBy(['name' => $providerName]);
        if (!$provider) {
            $provider = new \Entities\Analytics\Provider();
            $provider->setName($providerName);
            $provider->setLabel(ucfirst($providerName));
            $this->entityManager->persist($provider);
            $this->entityManager->flush();
        }

        $channel = $channelRepo->findOneBy(['name' => $name]);
        if (!$channel) {
            $channel = new \Entities\Analytics\Channel();
            $channel->setName($name);
            $channel->setLabel($label);
            $channel->setProvider($provider);
            $this->entityManager->persist($channel);
            $this->entityManager->flush();
        }
    }

    private function mockConfigurations(array $projectConfig, array $channelsConfig): void
    {
        $reflection = new \ReflectionClass(Helpers::class);
        
        $projectProp = $reflection->getProperty('projectConfig');
        $projectProp->setAccessible(true);
        $projectProp->setValue(null, $projectConfig);

        $channelsProp = $reflection->getProperty('channelsConfig');
        $channelsProp->setAccessible(true);
        $channelsProp->setValue(null, $channelsConfig);
    }
}
