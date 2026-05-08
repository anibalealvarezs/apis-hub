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
        $fbChannel = $fbDriver->getChannel();
        $fbEntity = \Enums\AnalyticsEntity::campaign->value;

        // Mock a channel config so the command has something to schedule
        $mockConfig = [
            $fbChannel => [
                'enabled' => true,
                'sync' => [
                    'entities' => [$fbEntity]
                ]
            ]
        ];
        
        $reflection = new \ReflectionClass(Helpers::class);
        $prop = $reflection->getProperty('channelsConfig');
        $prop->setAccessible(true);
        $prop->setValue(null, $mockConfig);

        $command = new ScheduleInitialJobsCommand($this->entityManager);
        
        // Run first time
        $command->run(new ArrayInput(['--instance' => 'global']), new NullOutput());
        
        $count = $this->jobRepo->count([]);
        $this->assertGreaterThan(0, $count, "Jobs should be scheduled for the mock channel");
        
        $initialCount = $count;
        
        // Run second time (should be idempotent for same instance)
        $command->run(new ArrayInput(['--instance' => 'global']), new NullOutput());
        
        $this->assertEquals($initialCount, $this->jobRepo->count([]), "Jobs should not be duplicated for same instance");
    }

    /**
     * Test 2: Verify that multiple workers can claim jobs for the same instance but different channels in parallel.
     * This specifically tests the fix for the global lock issue.
     */
    public function testParallelJobClaiming()
    {
        $fbChannel = (new \Anibalealvarezs\MetaHubDriver\Drivers\FacebookMarketingDriver())->getChannel();
        $gscChannel = (new \Anibalealvarezs\GoogleHubDriver\Drivers\SearchConsoleDriver())->getChannel();

        $fbEntity = \Anibalealvarezs\MetaHubDriver\Enums\MetaEntityType::CAMPAIGN->value;
        $gscEntity = \Enums\AnalyticsEntity::queries->value;

        // 1. Create two jobs for the same instance but different channels
        $this->jobRepo->create((object)[
            'entity' => $fbEntity,
            'channel' => $fbChannel,
            'status' => JobStatus::scheduled->value,
            'payload' => ['instance_name' => 'global', 'params' => ['account_id' => null]]
        ]);

        $this->jobRepo->create((object)[
            'entity' => $gscEntity,
            'channel' => $gscChannel,
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
        $this->assertEquals($fbChannel, $jobA->getChannel());

        // 3. Worker B tries to claim the second job (DIFFERENT CHANNEL, SAME INSTANCE)
        $jobB = $this->jobRepo->claimAvailableJob(
            status: [JobStatus::scheduled->value],
            workerId: 'worker-B',
            instanceName: 'global'
        );
        
        $this->assertNotNull($jobB, "Worker B SHOULD be able to claim a job from the same instance if the channel is different");
        $this->assertEquals($gscChannel, $jobB->getChannel());
    }

    /**
     * Test 3: Verify Telemetry aggregation accuracy.
     */
    public function testTelemetryAggregation()
    {
        $fbChannel = (new \Anibalealvarezs\MetaHubDriver\Drivers\FacebookMarketingDriver())->getChannel();
        $fbEntity = \Anibalealvarezs\MetaHubDriver\Enums\MetaEntityType::CAMPAIGN->value;

        // 1. Setup jobs in various states
        $this->jobRepo->create((object)[
            'entity' => $fbEntity,
            'channel' => $fbChannel,
            'status' => JobStatus::completed->value,
            'payload' => ['instance_name' => 'global', 'params' => ['account_id' => 'acc1']]
        ]);

        $this->jobRepo->create((object)[
            'entity' => $fbEntity,
            'channel' => $fbChannel,
            'status' => JobStatus::processing->value,
            'payload' => ['instance_name' => 'global', 'params' => ['account_id' => 'acc1']]
        ]);

        $this->jobRepo->create((object)[
            'entity' => $fbEntity,
            'channel' => $fbChannel,
            'status' => JobStatus::failed->value,
            'payload' => ['instance_name' => 'global', 'params' => ['account_id' => 'acc2']]
        ]);

        $this->entityManager->flush();

        // 2. Get Telemetry
        $service = new SyncTelemetryService();
        $telemetry = $service->getGlobalStatus();

        // 3. Verify counts
        $fbStats = null;
        foreach ($telemetry as $item) {
            if ($item['name'] === 'FACEBOOK MARKETING') {
                $fbStats = $item;
                break;
            }
        }

        $this->assertNotNull($fbStats, "Facebook Marketing stats should exist");
        $this->assertEquals(3, $fbStats['totalJobs'], "Total jobs count should match");
        $this->assertEquals(1, $fbStats['doneJobs'], "Done jobs count should match");
        $this->assertEquals(1, $fbStats['activeJobs'], "Active jobs count should match");
    }
}
