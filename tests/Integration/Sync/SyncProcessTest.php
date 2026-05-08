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
        
        // 1. Reset configs FIRST
        Helpers::resetConfigs();

        // 2. Ensure Helpers uses the SAME EntityManager as the test
        $reflection = new \ReflectionClass(Helpers::class);
        $property = $reflection->getProperty('entityManager');
        $property->setAccessible(true);
        $property->setValue(null, $this->entityManager);

        $this->jobRepo = $this->entityManager->getRepository(Job::class);
        $this->entityManager->flush();

        // 3. CLEANUP: Only remove jobs related to our test markers
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement("
            DELETE FROM jobs 
            WHERE worker_id IN ('worker-1', 'worker-2', 'dead-worker', 'new-worker') 
               OR CAST(payload AS text) LIKE 'test_acc_%'
               OR CAST(payload AS text) LIKE 'test_instance%'
        ");
    }

    /**
     * Test 1: Verify that ScheduleInitialJobsCommand schedules jobs correctly.
     */
    public function testInitialJobScheduling()
    {
        $mockChannel = 'facebook_marketing';
        $this->seedChannel($mockChannel, 'facebook', 'Facebook Marketing');

        // Mock configurations with CORRECT structure (list of objects)
        $this->mockConfigurations([
            'instances' => [
                [
                    'name' => 'test_instance_initial',
                    'enabled' => true,
                    'channel' => $mockChannel,
                    'entity' => 'campaign'
                ]
            ]
        ], [
            $mockChannel => [
                'enabled' => true,
                'entities' => ['campaign' => ['enabled' => true]]
            ]
        ]);

        $command = new ScheduleInitialJobsCommand($this->entityManager);
        $command->run(new ArrayInput([]), new NullOutput());

        $jobs = $this->jobRepo->findAll();
        $found = false;
        foreach ($jobs as $job) {
            if ($job->getChannel() === $mockChannel) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Jobs should be scheduled for the mock channel");
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
        $this->jobRepo->create((object)[
            'entity' => 'campaign',
            'channel' => $fbChannel,
            'payload' => ['account_id' => 'test_acc_parallel_123', 'instance_name' => 'test_instance_parallel']
        ]);
        $this->jobRepo->create((object)[
            'entity' => 'page',
            'channel' => $googleChannel,
            'payload' => ['account_id' => 'test_acc_parallel_123', 'instance_name' => 'test_instance_parallel']
        ]);
        $this->entityManager->flush();

        // Worker 1 claims a job
        $claimed1 = $this->jobRepo->claimAvailableJob(
            status: [JobStatus::scheduled->value], 
            workerId: 'worker-1', 
            instanceName: 'test_instance_parallel'
        );
        $this->assertNotNull($claimed1, "Worker 1 should claim a job");

        // Worker 2 should still be able to claim the other job because it's a different channel
        $claimed2 = $this->jobRepo->claimAvailableJob(
            status: [JobStatus::scheduled->value], 
            workerId: 'worker-2', 
            instanceName: 'test_instance_parallel'
        );
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
        $cache = \Services\CacheService::getInstance($redis);
        $cache->deletePattern('sync_telemetry:*'); // Clear cache to force fresh data

        $service = new SyncTelemetryService($cache);
        $telemetry = $service->getGlobalStatus();

        // 3. Verify counts for our SPECIFIC test accounts
        $channels = $telemetry['channels'] ?? [];
        
        if (Helpers::isDebug()) {
            echo "DEBUG: Telemetry channels keys: " . implode(', ', array_keys($channels)) . "\n";
            if (isset($channels[$fbChannelName])) {
                echo "DEBUG: Found fbChannelName in keys exactly\n";
            }
        }

        $fbChannelData = null;
        foreach ($channels as $name => $data) {
            if (strtolower((string)$name) === strtolower((string)$fbChannelName)) {
                $fbChannelData = $data;
                break;
            }
        }

        if (!$fbChannelData || !isset($fbChannelData['assets'])) {
            $this->fail("Facebook Marketing stats not found in telemetry. Available channels: " . implode(', ', array_keys($channels)));
        }

        // AGGREGATE ONLY OUR TEST ACCOUNTS
        $testStats = [
            'total' => 0,
            'completed' => 0,
            'processing' => 0,
            'scheduled' => 0
        ];

        foreach ($fbChannelData['assets'] as $accId => $stats) {
            if (str_starts_with($accId, 'test_acc_telemetry_')) {
                $testStats['total'] += $stats['total'];
                $testStats['completed'] += $stats['completed'];
                $testStats['processing'] += $stats['processing'];
                $testStats['scheduled'] += $stats['scheduled'];
            }
        }

        $this->assertEquals(3, $testStats['total'], "Total jobs count for test accounts should match");
        $this->assertEquals(1, $testStats['completed'], "Done jobs count for test accounts should match");
        $this->assertEquals(1, $testStats['processing'], "Active jobs count for test accounts should match");
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
            'payload' => ['account_id' => 'test_acc_resilient', 'instance_name' => 'test_instance_resilient']
        ]);
        $this->entityManager->flush();
        
        $jobId = is_array($jobData) ? $jobData['id'] : $jobData->getId();

        // 2. Backdate VERY AGGRESSIVELY to avoid timezone race conditions (e.g. 1 hour ago)
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement("UPDATE jobs SET worker_id = 'dead-worker', updated_at = NOW() - INTERVAL '1 hour' WHERE id = ?", [$jobId]);

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
