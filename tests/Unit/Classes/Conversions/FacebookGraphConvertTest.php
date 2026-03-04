<?php

namespace Tests\Unit\Classes\Conversions;

use Classes\Conversions\FacebookGraphConvert;
use Carbon\Carbon;
use Doctrine\Common\Collections\ArrayCollection;
use Entities\Analytics\Account;
use Entities\Analytics\Campaign;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Channeled\ChanneledAd;
use Entities\Analytics\Channeled\ChanneledAdGroup;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Entities\Analytics\Page;
use Entities\Analytics\Post;
use Enums\Channel;
use Enums\Period;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FacebookGraphConvertTest extends TestCase
{
    private MockObject|Page $page;
    private MockObject|Post $post;
    private MockObject|Account $account;
    private MockObject|ChanneledAccount $channeledAccount;
    private MockObject|Campaign $campaign;
    private MockObject|ChanneledCampaign $channeledCampaign;
    private MockObject|ChanneledAdGroup $channeledAdGroup;
    private MockObject|ChanneledAd $channeledAd;

    protected function setUp(): void
    {
        $this->page = $this->createMock(Page::class);
        $this->page->method('getUrl')->willReturn('https://example.com');

        $this->post = $this->createMock(Post::class);
        $this->post->method('getPostId')->willReturn('post123');

        $this->account = $this->createMock(Account::class);
        $this->account->method('getName')->willReturn('account123');

        $this->channeledAccount = $this->createMock(ChanneledAccount::class);
        $this->channeledAccount->method('getPlatformId')->willReturn('ca123');

        $this->campaign = $this->createMock(Campaign::class);
        $this->campaign->method('getCampaignId')->willReturn('camp123');

        $this->channeledCampaign = $this->createMock(ChanneledCampaign::class);
        $this->channeledCampaign->method('getPlatformId')->willReturn('cc123');

        $this->channeledAdGroup = $this->createMock(ChanneledAdGroup::class);
        $this->channeledAdGroup->method('getPlatformId')->willReturn('cag123');

        $this->channeledAd = $this->createMock(ChanneledAd::class);
        $this->channeledAd->method('getPlatformId')->willReturn('cad123');
    }

    public function testPageMetrics(): void
    {
        $rows = [
            [
                'name' => 'page_impressions',
                'values' => [
                    [
                        'value' => 100,
                        'end_time' => '2026-03-03T07:00:00+0000'
                    ]
                ]
            ]
        ];

        $result = FacebookGraphConvert::pageMetrics(
            $rows,
            'page123',
            '',
            null,
            $this->page,
            null,
            Period::Daily
        );

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $metric = $result->first();
        $this->assertEquals('page_impressions', $metric->name);
        $this->assertEquals(100, $metric->value);
        $this->assertEquals('page123', $metric->platformId);
        $this->assertEquals(Channel::facebook->value, $metric->channel);
    }

    public function testAdAccountMetrics(): void
    {
        $rows = [
            [
                'impressions' => 1500,
                'clicks' => 50,
                'date_start' => '2026-03-03',
                'age' => '18-24',
                'gender' => 'male',
                'actions' => []
            ]
        ];

        $result = FacebookGraphConvert::adAccountMetrics(
            $rows,
            null,
            $this->account,
            'ca123',
            Period::Daily
        );

        $this->assertInstanceOf(ArrayCollection::class, $result);
        // expecting 2 metrics: impressions, clicks
        $this->assertCount(2, $result);
        $metric = $result->first();
        $this->assertContains($metric->name, ['impressions', 'clicks']);
        $this->assertEquals('ca123', $metric->platformId);
        $this->assertCount(2, $metric->dimensions); // age and gender
    }

    public function testIgAccountMetrics(): void
    {
        $rows = [
            [
                'name' => 'impressions',
                'total_value' => [
                    'value' => 2000
                ]
            ]
        ];

        $result = FacebookGraphConvert::igAccountMetrics(
            $rows,
            '2026-03-03',
            $this->page,
            $this->account,
            $this->channeledAccount,
            null,
            Period::Daily
        );

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $metric = $result->first();
        $this->assertEquals('impressions', $metric->name);
        $this->assertEquals(2000, $metric->value);
        $this->assertEquals('ca123', $metric->platformId);
    }

    public function testIgMediaMetrics(): void
    {
        $rows = [
            [
                'name' => 'likes',
                'values' => [
                    [
                        'value' => 50
                    ]
                ]
            ]
        ];

        $result = FacebookGraphConvert::igMediaMetrics(
            $rows,
            $this->page,
            $this->post,
            $this->account,
            $this->channeledAccount,
            null
        );

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $metric = $result->first();
        $this->assertEquals('likes', $metric->name);
        $this->assertEquals(50, $metric->value);
        $this->assertEquals('ca123', $metric->platformId);
        $this->assertEquals(Period::Lifetime->value, $metric->period);
    }

    public function testCampaignMetrics(): void
    {
        $rows = [
            [
                'impressions' => 1000,
                'clicks' => 30,
                'date_start' => '2026-03-03',
                'age' => '25-34',
                'gender' => 'female',
                'actions' => []
            ]
        ];

        $result = FacebookGraphConvert::campaignMetrics(
            $rows,
            null,
            $this->channeledAccount,
            $this->campaign,
            $this->channeledCampaign,
            Period::Daily
        );

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(2, $result);
    }

    public function testAdsetMetrics(): void
    {
        $rows = [
            [
                'impressions' => 500,
                'clicks' => 10,
                'date_start' => '2026-03-03',
                'age' => '35-44',
                'gender' => 'unknown',
                'actions' => []
            ]
        ];

        $result = FacebookGraphConvert::adsetMetrics(
            $rows,
            null,
            $this->channeledAccount,
            $this->campaign,
            $this->channeledCampaign,
            $this->channeledAdGroup,
            Period::Daily
        );

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(2, $result);
    }

    public function testAdMetrics(): void
    {
        $rows = [
            [
                'impressions' => 200,
                'clicks' => 5,
                'date_start' => '2026-03-03',
                'age' => '45-54',
                'gender' => 'male',
                'actions' => []
            ]
        ];

        $result = FacebookGraphConvert::adMetrics(
            $rows,
            null,
            $this->channeledAccount,
            $this->campaign,
            $this->channeledCampaign,
            $this->channeledAdGroup,
            $this->channeledAd,
            Period::Daily
        );

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(2, $result);
    }
}
