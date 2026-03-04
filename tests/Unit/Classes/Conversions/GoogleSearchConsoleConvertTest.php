<?php

namespace Tests\Unit\Classes\Conversions;

use Classes\Conversions\GoogleSearchConsoleConvert;
use Carbon\Carbon;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Entities\Analytics\Page;
use Enums\Channel;
use Enums\Country;
use Enums\Device;
use Enums\Period;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GoogleSearchConsoleConvertTest extends TestCase
{
    private MockObject|EntityManager $entityManager;
    private MockObject|Page $page;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->page = $this->createMock(Page::class);
        $this->page->method('getId')->willReturn(1);
        $this->page->method('getUrl')->willReturn('https://example.com');

        $this->entityManager->method('find')
            ->with(Page::class, 1)
            ->willReturn($this->page);
    }

    public function testAggregateMetrics(): void
    {
        $data = [
            'impressions' => 100,
            'clicks' => 5,
            'position' => 2.5,
            'ctr' => 0.05,
            'count' => 1,
        ];

        $new = [
            'impressions' => 50,
            'clicks' => 10,
            'position' => 1.0,
            'ctr' => 0.20,
        ];

        $result = GoogleSearchConsoleConvert::aggregateMetrics($data, $new);

        $this->assertEquals(150, $result['impressions']);
        $this->assertEquals(15, $result['clicks']);
        $this->assertEquals(0.1, $result['ctr']); // 15 clicks / 150 impressions
        $this->assertEquals(2.0, $result['position']); // (100 * 2.5 + 50 * 1.0) / 150 = 300 / 150 = 2.0
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
            pageEntity: $this->page,
            em: $this->entityManager
        );

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(4, $result); // impressions, clicks, position, ctr
    }
}
