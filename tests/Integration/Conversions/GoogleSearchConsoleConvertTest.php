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
        $siteUrl = $this->faker->url;
        $siteKey = str_replace(['https://', 'http://', '.', '/'], ['', '', '_', ''], $siteUrl);
        $pageUrl = $siteUrl . '/' . $this->faker->slug;
        $pagePlatformId = $this->faker->uuid;
        $date = $this->faker->date();
        $query = $this->faker->word;
        $country = $this->faker->countryCode;
        $device = $this->faker->randomElement(['MOBILE', 'DESKTOP', 'TABLET']);

        // 1. Arrange: Create our Database Page entity
        $pageEntity = new Page();
        $pageEntity->addUrl($pageUrl);
        $pageEntity->addPlatformId($pagePlatformId);
        $this->entityManager->persist($pageEntity);
        $this->entityManager->flush();

        $v1 = [
            'impressions' => $this->faker->numberBetween(100, 1000),
            'clicks' => $this->faker->numberBetween(10, 100),
            'position' => $this->faker->randomFloat(2, 1, 50),
        ];
        $v2 = [
            'impressions' => $this->faker->numberBetween(100, 1000),
            'clicks' => $this->faker->numberBetween(10, 100),
            'position' => $this->faker->randomFloat(2, 1, 50),
        ];

        // Simulate 2 raw rows returned correctly mapped through Helpers layer
        $rows = [
            [
                // Keys map to: ['date', 'query', 'country', 'page', 'device']
                'keys' => [$date, $query, $country, $pageUrl, $device],
                'subset' => ['date', 'query', 'country', 'page', 'device'],
                'impressions' => $v1['impressions'],
                'clicks' => $v1['clicks'],
                'position' => $v1['position'],
                'ctr' => $v1['clicks'] / $v1['impressions'],
                'impressions_difference' => 0,
                'clicks_difference' => 0,
                'synthetic' => false,
            ],
            [
                // Perfectly matching keys to force an aggregation scenario!
                'keys' => [$date, $query, $country, $pageUrl, $device],
                'subset' => ['date', 'query', 'country', 'page', 'device'],
                'impressions' => $v2['impressions'],
                'clicks' => $v2['clicks'],
                'position' => $v2['position'],
                'ctr' => $v2['clicks'] / $v2['impressions'],
                'impressions_difference' => 0,
                'clicks_difference' => 0,
                'synthetic' => false,
            ]
        ];

        // 2. Act: Pipe Data through the GoogleSearchConsoleConvert tool
        $collection = GoogleSearchConsoleConvert::metrics(
            rows: $rows,
            siteUrl: $siteUrl,
            siteKey: $siteKey,
            logger: null,
            pageEntity: $pageEntity,
            em: $this->entityManager
        );

        // 3. Assert
        $this->assertInstanceOf(ArrayCollection::class, $collection);
        
        $totalClicks = $v1['clicks'] + $v2['clicks'];
        $totalImpressions = $v1['impressions'] + $v2['impressions'];
        $weightedPosition = (($v1['position'] * $v1['impressions']) + ($v2['position'] * $v2['impressions'])) / $totalImpressions;
        $totalCtr = $totalClicks / $totalImpressions;

        // Because the rows have identical metricConfigKey signatures, they should aggregate to EXACTLY 4 final metrics
        // 1 Impressions, 1 Clicks, 1 Position, 1 CTR.
        $this->assertCount(4, $collection);

        $metricsMap = [];
        foreach ($collection as $item) {
            $metricsMap[$item->name] = $item;
        }

        $this->assertEquals($totalClicks, $metricsMap['clicks']->value);
        $this->assertEquals($totalImpressions, $metricsMap['impressions']->value);
        $this->assertEquals($totalCtr, $metricsMap['ctr']->value);
        $this->assertEquals($weightedPosition, $metricsMap['position']->value);

        $this->assertEquals(\Enums\Channel::google_search_console->value, $metricsMap['impressions']->channel);
        $this->assertStringStartsWith('gsc_' . $siteKey . '_', $metricsMap['impressions']->platformId);
        $this->assertEquals($query, $metricsMap['impressions']->query);
        $this->assertEquals($country, $metricsMap['impressions']->countryCode);
        $this->assertEquals($device, $metricsMap['impressions']->deviceType);
        
        $this->assertEquals($pageEntity->getId(), $metricsMap['impressions']->page->getId());
    }
}
