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
        $this->jobRepo = $this->entityManager->getRepository(Job::class);
    }

    /**
     * Test 1: Verify that initial jobs are scheduled correctly without duplication.
     */
    public function testInitialJobScheduling()
    {
        $fbDriver = new \Anibalealvarezs\MetaHubDriver\Drivers\FacebookMarketingDriver();
        $fbChannelName = $fbDriver->getChannel();
        $fbEntity = \Enums\AnalyticsEntity::campaigns->value;

        // 1. Register driver in factory registry with full metadata via reflection
        $reflectionFactory = new \ReflectionClass(\Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::class);
        $regProp = $reflectionFactory->getProperty('registry');
        $regProp->setAccessible(true);
        $registry = $regProp->getValue();
        $registry[$fbChannelName] = [
            'driver' => get_class($fbDriver),
            'auth' => '',
            'parent' => $fbDriver->getProviderName(),
            'resource_key' => 'ad_accounts'
        ];
        $regProp->setValue(null, $registry);

        // 2. Ensure channel exists in DB
        $this->seedChannel($fbChannelName, $fbDriver->getProviderName(), $fbDriver->getChannelLabel());

        // 3. Mock configurations via reflection
        $reflectionHelpers = new \ReflectionClass(Helpers::class);
        
        // Mock projectConfig (with instances)
        $projectProp = $reflectionHelpers->getProperty('projectConfig');
        $projectProp->setAccessible(true);
        $projectProp->setValue(null, [
            'instances' => [
                [
                    'name' => 'global',
                    'channel' => $fbChannelName,
                    'entity' => $fbEntity,
                    'granular_sync' => true
                ]
            ]
        ]);

        // Mock channelsConfig (with account data)
        $mockChannels = [
            $fbChannelName => [
                'enabled' => true,
                'ad_accounts' => [['id' => '123', 'enabled' => true]],
                'sync' => [
                    'entities' => [$fbEntity]
                ]
            ]
        ];
        $channelsProp = $reflectionHelpers->getProperty('channelsConfig');
        $channelsProp->setAccessible(true);
        $channelsProp->setValue(null, $mockChannels);

        $command = new ScheduleInitialJobsCommand($this->entityManager);
        
        // Run first time
        $command->run(new ArrayInput(['--instance' => 'global']), new \Symfony\Component\Console\Output\BufferedOutput());
        
        $count = $this->jobRepo->count([]);
        $this->assertGreaterThan(0, $count, "Jobs should be scheduled for the mock channel");
        
        $initialCount = $count;
        
        // Run second time (should be idempotent for same instance)
        $command->run(new ArrayInput(['--instance' => 'global']), new \Symfony\Component\Console\Output\BufferedOutput());
        
        $this->assertEquals($initialCount, $this->jobRepo->count([]), "Jobs should not be duplicated for same instance");
    }

    /**
     * Test 2: Verify that multiple workers can claim jobs for the same instance but different channels in parallel.
     * This specifically tests the fix for the global lock issue.
     */
    public function testParallelJobClaiming()
    {
        $fbDriver = new \Anibalealvarezs\MetaHubDriver\Drivers\FacebookMarketingDriver();
        $fbChannelName = $fbDriver->getChannel();
        $fbEntity = \Anibalealvarezs\MetaHubDriver\Enums\MetaEntityType::CAMPAIGN->value;

        $gscDriver = new \Anibalealvarezs\GoogleHubDriver\Drivers\SearchConsoleDriver();
        $gscChannelName = $gscDriver->getChannel();
        $gscEntity = \Enums\AnalyticsEntity::queries->value;

        // Seed channels
        $this->seedChannel($fbChannelName, $fbDriver->getProviderName(), $fbDriver->getChannelLabel());
        $this->seedChannel($gscChannelName, $gscDriver->getProviderName(), $gscDriver->getChannelLabel());

        // 1. Create two jobs for the same instance but different channels
        $this->jobRepo->create((object)[
            'entity' => $fbEntity,
            'channel' => $fbChannelName,
            'status' => JobStatus::scheduled->value,
            'payload' => ['instance_name' => 'global', 'params' => ['account_id' => null]]
        ]);

        $this->jobRepo->create((object)[
            'entity' => $gscEntity,
            'channel' => $gscChannelName,
            'status' => JobStatus::scheduled->value,
            'payload' => ['instance_name' => 'global', 'params' => ['account_id' => null]]
        ]);

        $this->entityManager->flush();

        // 2. Worker A claims the first job
        $jobA = $this->jobRepo->claimAvailableJob(
            status: [JobStatus::scheduled->value],
            workerId: 'worker-A',
            instanceName: 'global'
        );
        $this->assertNotNull($jobA, "Worker A should be able to claim a job");
        $this->assertEquals($fbChannelName, $jobA->getChannel());

        // 3. Worker B tries to claim the second job (DIFFERENT CHANNEL, SAME INSTANCE)
        $jobB = $this->jobRepo->claimAvailableJob(
            status: [JobStatus::scheduled->value],
            workerId: 'worker-B',
            instanceName: 'global'
        );
        
        $this->assertNotNull($jobB, "Worker B SHOULD be able to claim a job from the same instance if the channel is different");
        $this->assertEquals($gscChannelName, $jobB->getChannel());
    }

    /**
     * Test 3: Verify Telemetry aggregation accuracy.
     */
    public function testTelemetryAggregation()
    {
        $fbDriver = new \Anibalealvarezs\MetaHubDriver\Drivers\FacebookMarketingDriver();
        $fbChannelName = $fbDriver->getChannel();
        $fbEntity = \Anibalealvarezs\MetaHubDriver\Enums\MetaEntityType::CAMPAIGN->value;

        // Seed channel
        $this->seedChannel($fbChannelName, $fbDriver->getProviderName(), $fbDriver->getChannelLabel());

        // 1. Setup jobs in various states
        // Ensure payload is a proper JSON-compatible array
        $this->jobRepo->create((object)[
            'entity' => $fbEntity,
            'channel' => $fbChannelName,
            'status' => JobStatus::completed->value,
            'payload' => ['account_id' => 'acc1', 'instance_name' => 'global']
        ]);

        $this->jobRepo->create((object)[
            'entity' => $fbEntity,
            'channel' => $fbChannelName,
            'status' => JobStatus::processing->value,
            'payload' => ['account_id' => 'acc1', 'instance_name' => 'global']
        ]);

        $this->jobRepo->create((object)[
            'entity' => $fbEntity,
            'channel' => $fbChannelName,
            'status' => JobStatus::failed->value,
            'payload' => ['account_id' => 'acc2', 'instance_name' => 'global']
        ]);

        $this->entityManager->flush();

        // 2. Get Telemetry
        $redis = Helpers::getRedisClient();
        $cache = new \Services\CacheService($redis);
        $cache->deletePattern('telemetry_*'); // Clear cache to force fresh data

        $service = new SyncTelemetryService($cache);
        $telemetry = $service->getGlobalStatus();

        // 3. Verify counts
        $fbStats = null;
        foreach ($telemetry as $key => $item) {
            if (is_array($item) && isset($item['channel']) && strtolower($item['channel']) === strtolower($fbChannelName)) {
                $fbStats = $item;
                break;
            }
        }

        if (!$fbStats) {
            $channels = array_filter(array_keys($telemetry), fn($k) => is_array($telemetry[$k]));
            $this->fail("Facebook Marketing stats not found. Available channels: " . implode(', ', $channels) . ". Telemetry keys: " . implode(', ', array_keys($telemetry)));
        }

        $this->assertEquals(3, $fbStats['total_jobs'], "Total jobs count should match");
        $this->assertEquals(1, $fbStats['completed'], "Done jobs count should match");
        $this->assertEquals(1, $fbStats['processing'], "Active jobs count should match");
    }

    /**
     * Test 4: Verify that workers recover jobs after an abrupt shutdown.
     * This tests the 'stale processing' recovery logic.
     */
    public function testWorkerRecoveryAfterAbruptShutdown()
    {
        $fbDriver = new \Anibalealvarezs\MetaHubDriver\Drivers\FacebookMarketingDriver();
        $fbChannelName = $fbDriver->getChannel();
        $fbEntity = \Anibalealvarezs\MetaHubDriver\Enums\MetaEntityType::CAMPAIGN->value;

        // 1. Create a job and mark it as processing (simulating a worker that died)
        $job = $this->jobRepo->create((object)[
            'entity' => $fbEntity,
            'channel' => $fbChannelName,
            'status' => JobStatus::processing->value,
            'payload' => ['account_id' => 'acc_resilient', 'instance_name' => 'global'],
            'worker_id' => 'dead-worker'
        ]);
        $this->entityManager->flush();

        // 2. Manually backdate the job to make it 'stale' (older than 10 minutes)
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement("UPDATE jobs SET updated_at = NOW() - INTERVAL '11 minutes' WHERE id = ?", [$job->getId()]);
        
        // Refresh entity
        $this->entityManager->refresh($job);

        // 3. Try to claim a job with a new worker
        $newJob = $this->jobRepo->claimAvailableJob(
            status: [JobStatus::scheduled->value],
            workerId: 'new-worker',
            instanceName: 'global'
        );

        // 4. Assert the stale job was recovered
        $this->assertNotNull($newJob, "The stale job should have been reclaimed by the new worker");
        $this->assertEquals($job->getId(), $newJob->getId(), "The reclaimed job ID should match the stale one");
        $this->assertEquals('new-worker', $newJob->getWorkerId(), "The worker ID should be updated to the new one");
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
}
