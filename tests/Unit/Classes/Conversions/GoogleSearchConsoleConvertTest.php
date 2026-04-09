<?php

namespace Tests\Unit\Classes\Conversions;

use Anibalealvarezs\GoogleApi\Conversions\GoogleSearchConsoleConvert;
use Carbon\Carbon;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Entities\Analytics\Page;
use Enums\Channel;
use Enums\Country;
use Enums\Device;
use Enums\Period;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\Unit\BaseUnitTestCase;

class GoogleSearchConsoleConvertTest extends BaseUnitTestCase
{
    private MockObject|EntityManager $entityManager;
    private MockObject|Page $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->page = $this->createMock(Page::class);
        $pageId = $this->faker->randomNumber();
        $this->page->method('getId')->willReturn($pageId);
        $this->page->method('getUrl')->willReturn($this->faker->url);

        $this->entityManager->method('find')
            ->with(Page::class, $pageId)
            ->willReturn($this->page);
    }

    public function testAggregateMetrics(): void
    {
        $i1 = $this->faker->numberBetween(100, 1000);
        $c1 = $this->faker->numberBetween(1, 100);
        $p1 = $this->faker->randomFloat(2, 1, 50);

        $data = [
            'impressions' => $i1,
            'clicks' => $c1,
            'position' => $p1,
            'ctr' => $c1 / $i1,
            'count' => 1,
        ];

        $i2 = $this->faker->numberBetween(100, 1000);
        $c2 = $this->faker->numberBetween(1, 100);
        $p2 = $this->faker->randomFloat(2, 1, 50);

        $new = [
            'impressions' => $i2,
            'clicks' => $c2,
            'position' => $p2,
            'ctr' => $c2 / $i2,
        ];

        $result = GoogleSearchConsoleConvert::aggregateMetrics($data, $new);

        $totalImpressions = $i1 + $i2;
        $totalClicks = $c1 + $c2;

        $this->assertEquals($totalImpressions, $result['impressions']);
        $this->assertEquals($totalClicks, $result['clicks']);
        $this->assertEquals($totalClicks / $totalImpressions, $result['ctr']);
        $this->assertEquals((($i1 * $p1) + ($i2 * $p2)) / $totalImpressions, $result['position']);
        $this->assertEquals(2, $result['count']);
    }

    public function testMetricsConversion(): void
    {
        $rows = [
            [
                'keys' => [
                    '2026-03-03',
                    'test_query',
                    'https://example.com/test',
                    'ESP',
                    'DESKTOP'
                ],
                'clicks' => 5,
                'impressions' => 100,
                'ctr' => 0.05,
                'position' => 3.5,
                'subset' => 'subset1',
                'impressions_difference' => 10,
                'clicks_difference' => 2
            ]
        ];

        $result = GoogleSearchConsoleConvert::metrics(
            rows: $rows,
            siteUrl: 'https://example.com',
            siteKey: 'example',
            logger: null,
            page: $this->page
        );

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(4, $result); // impressions, clicks, position, ctr
    }

    public function testRobustness(): void
    {
        // 1. GSC metrics with missing 'keys' and differences
        $rows = [
            [
                'clicks' => 5,
                'impressions' => 100,
                'ctr' => 0.05,
                'position' => 3.5,
                // 'keys' is missing here
                'subset' => 'subset1',
            ]
        ];

        $result = GoogleSearchConsoleConvert::metrics(
            rows: $rows,
            siteUrl: 'https://example.com',
            siteKey: 'example',
            logger: null,
            page: $this->page
        );

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(4, $result);
        $metric = $result->first();
        $this->assertEquals(Channel::google_search_console->value, $metric->channel);
        $this->assertEquals('unknown', $metric->query);
        $this->assertEquals(Country::UNK->value, $metric->countryCode);
    }
}
