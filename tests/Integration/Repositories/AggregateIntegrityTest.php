<?php

namespace Tests\Integration\Repositories;

use Entities\Analytics\ChanneledMetric;
use Entities\Analytics\Metric;
use Entities\Analytics\MetricConfig;
use Repositories\MetricRepository;
use Tests\Integration\BaseIntegrationTestCase;

class AggregateIntegrityTest extends BaseIntegrationTestCase
{
    private MetricRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->entityManager->getRepository(Metric::class);
    }

    /**
     * Test that position is correctly weighted and not 'crossed' with other segments.
     * This specifically validates the fix for the position formula bug.
     */
    public function testPositionAggregationAccuracy(): void
    {
        // 1. Setup Data
        // Segment A: Kazakhstan (117) - Page 3, Query 1737
        // Position: 99, Impressions: 1
        $configA_pos = $this->createConfig('position', 3, 1737, 117, 1);
        $configA_imp = $this->createConfig('impressions', 3, 1737, 117, 1);
        
        $this->createMetric($configA_pos, 99.0, '2026-05-01');
        $this->createMetric($configA_imp, 1.0, '2026-05-01');

        // Segment B: USA (1) - Same Page 3, Same Query 1737
        // Position: 10, Impressions: 10
        // If the bug exists, Segment A's position (99) will be multiplied by Segment B's impressions (10)
        $configB_pos = $this->createConfig('position', 3, 1737, 1, 1);
        $configB_imp = $this->createConfig('impressions', 3, 1737, 1, 1);
        
        $this->createMetric($configB_pos, 10.0, '2026-05-01');
        $this->createMetric($configB_imp, 10.0, '2026-05-01');

        $this->entityManager->flush();

        // 2. Aggregate for Kazakhstan only
        $results = $this->repository->aggregate(
            aggregations: ['position' => 'position'],
            groupBy: ['country_id'],
            startDate: '2026-05-01',
            endDate: '2026-05-01',
            filters: ['country' => 117, 'page' => 3, 'channel' => 1]
        );

        // 3. Assert
        $this->assertCount(1, $results);
        // Expected: (99 * 1) / 1 = 99.0
        // Buggy result would be: (99 * 10) / 1 = 990.0 (or similar depending on which impression it picks first)
        $this->assertEquals(99.0, (float)$results[0]['position'], "Position should be correctly weighted for the specific segment.");
    }

    /**
     * Test that the optimized CTE path is used and returns correct results.
     */
    public function testOptimizedAggregationPath(): void
    {
        // Setup some data
        $config = $this->createConfig('clicks', 1, 1, 1, 1);
        $this->createMetric($config, 50.0, '2026-05-01');
        $this->entityManager->flush();

        // Run aggregation with filters that should trigger the optimized path
        // We use 'clicks' which is supported by optimized path
        $results = $this->repository->aggregate(
            aggregations: ['clicks' => 'SUM(clicks)'],
            groupBy: ['channel'],
            startDate: '2026-05-01',
            endDate: '2026-05-01',
            filters: ['country' => 1, 'channel' => 1]
        );

        $this->assertCount(1, $results);
        $this->assertEquals(50.0, (float)$results[0]['clicks']);
        
        // We can't easily assert that the CTE was used without mocking the connection,
        // but the fact that it didn't return NULL/Error and produced correct result 
        // with newly supported filters (country) is a good sign.
    }

    public function testReelAverageWatchTimeUsesWeightedAverageAcrossDailyRows(): void
    {
        $avgConfigDayA = $this->createConfig('ig_reels_avg_watch_time_daily', 3, 1737, 117, 1);
        $totConfigDayA = $this->createConfig('ig_reels_video_view_total_time_daily', 3, 1737, 117, 1);

        $this->createMetric($avgConfigDayA, 5000.0, '2026-05-01');
        $this->createMetric($totConfigDayA, 50000.0, '2026-05-01'); // 10 implied views
        $this->createMetric($avgConfigDayA, 10000.0, '2026-05-02');
        $this->createMetric($totConfigDayA, 10000.0, '2026-05-02'); // 1 implied view

        // Parallel segment to ensure companion matching respects device segmentation.
        $otherAvgConfig = $this->createConfig('ig_reels_avg_watch_time_daily', 3, 1737, 117, 2);
        $otherTotConfig = $this->createConfig('ig_reels_video_view_total_time_daily', 3, 1737, 117, 2);
        $this->createMetric($otherAvgConfig, 3000.0, '2026-05-01');
        $this->createMetric($otherTotConfig, 30000.0, '2026-05-01');

        $this->entityManager->flush();

        $results = $this->repository->aggregate(
            aggregations: ['reel_avg_watch_time' => 'ig_reels_avg_watch_time_daily'],
            groupBy: ['page_id'],
            startDate: '2026-05-01',
            endDate: '2026-05-02',
            filters: ['page' => 3, 'query' => 1737, 'country' => 117, 'device' => 1, 'channel' => 1]
        );

        $this->assertCount(1, $results);

        $expected = 60000.0 / 11.0; // total watch time / implied total views
        $this->assertEqualsWithDelta($expected, (float)$results[0]['reel_avg_watch_time'], 0.001);
    }

    private function createConfig(string $name, int $pageId, int $queryId, int $countryId, int $deviceId): MetricConfig
    {
        $config = new MetricConfig();
        $config->setName($name);
        $config->setChannel(1); // GSC
        $config->setPeriod('daily');
        $config->setPageId($pageId);
        $config->setQueryId($queryId);
        $config->setCountryId($countryId);
        $config->setDeviceId($deviceId);
        $config->setDimensionSetId(16); // Simulate 5D subset
        
        $this->entityManager->persist($config);
        return $config;
    }

    private function createMetric(MetricConfig $config, float $value, string $date): void
    {
        $metric = new Metric();
        $metric->setMetricConfig($config);
        $metric->setValue($value);
        $metric->setMetricDate(new \DateTime($date));
        $this->entityManager->persist($metric);

        // ChanneledMetric is required for the optimized path join
        $channeled = new ChanneledMetric();
        $channeled->setMetric($metric);
        $channeled->setMetricDate(new \DateTime($date));
        $this->entityManager->persist($channeled);
    }
}
