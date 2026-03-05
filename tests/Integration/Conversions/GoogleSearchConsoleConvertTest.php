<?php

namespace Tests\Integration\Conversions;

use Classes\Conversions\GoogleSearchConsoleConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Entities\Analytics\Page;
use Tests\Integration\BaseIntegrationTestCase;

class GoogleSearchConsoleConvertTest extends BaseIntegrationTestCase
{
    public function testMetricsConversionAggregatesAndTransformsDataCorrectly(): void
    {
        // 1. Arrange: Create our Database Page entity
        $pageEntity = new Page();
        $pageEntity->addUrl('https://example.com/page');
        $pageEntity->addPlatformId('example_com_page_id');
        $this->entityManager->persist($pageEntity);
        $this->entityManager->flush();

        // Simulate 2 raw rows returned correctly mapped through Helpers layer
        $rows = [
            [
                // Keys map to: ['date', 'query', 'country', 'page', 'device']
                'keys' => ['2026-03-05', 'mock query', 'USA', 'https://example.com/page', 'MOBILE'],
                'subset' => ['date', 'query', 'country', 'page', 'device'],
                'impressions' => 100,
                'clicks' => 10,
                'position' => 5.5,
                'ctr' => 0.1,
                'impressions_difference' => 0,
                'clicks_difference' => 0,
                'synthetic' => false,
            ],
            [
                // Perfectly matching keys to force an aggregation scenario!
                'keys' => ['2026-03-05', 'mock query', 'USA', 'https://example.com/page', 'MOBILE'],
                'subset' => ['date', 'query', 'country', 'page', 'device'],
                'impressions' => 50,
                'clicks' => 20,
                'position' => 2.0,
                'ctr' => 0.4,
                'impressions_difference' => 0,
                'clicks_difference' => 0,
                'synthetic' => false,
            ]
        ];

        // 2. Act: Pipe Data through the GoogleSearchConsoleConvert tool
        $collection = GoogleSearchConsoleConvert::metrics(
            rows: $rows,
            siteUrl: 'https://example.com',
            siteKey: 'example_com',
            logger: null,
            pageEntity: $pageEntity,
            em: $this->entityManager
        );

        // 3. Assert
        $this->assertInstanceOf(ArrayCollection::class, $collection);
        
        // Because the rows have identical metricConfigKey signatures, they should aggregate to EXACTLY 4 final metrics
        // 1 Impressions, 1 Clicks, 1 Position, 1 CTR.
        $this->assertCount(4, $collection);

        $metricsMap = [];
        foreach ($collection as $item) {
            // These aren't raw rows anymore, they're objects formatted specifically for Doctrine entities natively!
            $metricsMap[$item->name] = $item;
        }

        $this->assertArrayHasKey('impressions', $metricsMap);
        $this->assertArrayHasKey('clicks', $metricsMap);
        $this->assertArrayHasKey('position', $metricsMap);
        $this->assertArrayHasKey('ctr', $metricsMap);

        // Assert Math logic for aggregation:
        // Clicks = 10 + 20 = 30
        $this->assertEquals(30, $metricsMap['clicks']->value);
        
        // Impressions = 100 + 50 = 150
        $this->assertEquals(150, $metricsMap['impressions']->value);
        
        // CTR = Clicks (30) / Impressions (150) = 0.2
        $this->assertEquals(0.2, $metricsMap['ctr']->value);
        
        // Position Weighted = ((5.5 * 100) + (2.0 * 50)) / 150 = 650 / 150 = 4.3333333333333
        $this->assertEquals(650 / 150, $metricsMap['position']->value);

        // Validate Doctrine formatting parameters
        $this->assertEquals(\Enums\Channel::google_search_console->value, $metricsMap['impressions']->channel);
        $this->assertStringStartsWith('gsc_example_com_', $metricsMap['impressions']->platformId);
        $this->assertEquals('mock query', $metricsMap['impressions']->query);
        $this->assertEquals('USA', $metricsMap['impressions']->countryCode);
        $this->assertEquals('MOBILE', $metricsMap['impressions']->deviceType);
        
        // Assert the $pageEntity relational hydration successfully piped through
        $this->assertEquals($pageEntity->getId(), $metricsMap['impressions']->page->getId());
    }
}
