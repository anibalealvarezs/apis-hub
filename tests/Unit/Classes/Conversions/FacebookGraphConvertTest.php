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
use Tests\Unit\BaseUnitTestCase;

class FacebookGraphConvertTest extends BaseUnitTestCase
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
        parent::setUp();
        $this->page = $this->createMock(Page::class);
        $this->page->method('getUrl')->willReturn($this->faker->url);

        $this->post = $this->createMock(Post::class);
        $this->post->method('getPostId')->willReturn('post' . $this->faker->randomNumber());

        $this->account = $this->createMock(Account::class);
        $this->account->method('getName')->willReturn($this->faker->word);

        $this->channeledAccount = $this->createMock(ChanneledAccount::class);
        $this->channeledAccount->method('getPlatformId')->willReturn('ca' . $this->faker->randomNumber());

        $this->campaign = $this->createMock(Campaign::class);
        $this->campaign->method('getCampaignId')->willReturn('camp' . $this->faker->randomNumber());

        $this->channeledCampaign = $this->createMock(ChanneledCampaign::class);
        $this->channeledCampaign->method('getPlatformId')->willReturn('cc' . $this->faker->randomNumber());

        $this->channeledAdGroup = $this->createMock(ChanneledAdGroup::class);
        $this->channeledAdGroup->method('getPlatformId')->willReturn('cag' . $this->faker->randomNumber());

        $this->channeledAd = $this->createMock(ChanneledAd::class);
        $this->channeledAd->method('getPlatformId')->willReturn('cad' . $this->faker->randomNumber());
    }

    public function testPageMetrics(): void
    {
        $value = $this->faker->numberBetween(1, 1000);
        $platformId = 'page' . $this->faker->randomNumber();
        $date = $this->faker->iso8601;

        $rows = [
            [
                'name' => 'page_impressions',
                'values' => [
                    [
                        'value' => $value,
                        'end_time' => $date
                    ]
                ]
            ]
        ];

        $result = FacebookGraphConvert::pageMetrics(
            $rows,
            $platformId,
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
        $this->assertEquals($value, $metric->value);
        $this->assertEquals($platformId, $metric->platformId);
        $this->assertEquals(Channel::facebook_organic->value, $metric->channel);
    }

    public function testAdAccountMetrics(): void
    {
        $impressions = $this->faker->numberBetween(1000, 5000);
        $clicks = $this->faker->numberBetween(10, 100);
        $date = $this->faker->date();
        $platformId = 'ca' . $this->faker->randomNumber();

        $rows = [
            [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'date_start' => $date,
                'age' => '18-24',
                'gender' => 'male',
                'actions' => []
            ]
        ];

        $result = FacebookGraphConvert::adAccountMetrics(
            $rows,
            null,
            $this->account,
            $platformId,
            Period::Daily
        );

        $this->assertCount(3, $result);
        $metric = $result->first();
        $this->assertContains($metric->name, ['impressions', 'clicks']);
        $this->assertEquals($platformId, $metric->platformId);
        $this->assertCount(2, $metric->dimensions);
    }

    public function testIgAccountMetrics(): void
    {
        $value = $this->faker->numberBetween(1000, 5000);
        $date = $this->faker->date();

        $rows = [
            [
                'name' => 'impressions',
                'total_value' => [
                    'value' => $value
                ]
            ]
        ];

        $result = FacebookGraphConvert::igAccountMetrics(
            $rows,
            $date,
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
        $this->assertEquals($value, $metric->value);
        $this->assertEquals($this->channeledAccount->getPlatformId(), $metric->platformId);
    }

    public function testIgMediaMetrics(): void
    {
        $likes = $this->faker->numberBetween(1, 100);
        $rows = [
            [
                'name' => 'likes',
                'values' => [
                    [
                        'value' => $likes
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
        $this->assertEquals($likes, $metric->value);
        $this->assertEquals($this->post->getPostId(), $metric->platformId);
        $this->assertEquals(Period::Lifetime->value, $metric->period);
    }

    public function testCampaignMetrics(): void
    {
        $impressions = $this->faker->numberBetween(1000, 5000);
        $clicks = $this->faker->numberBetween(10, 100);
        $date = $this->faker->date();

        $rows = [
            [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'date_start' => $date,
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
        $this->assertCount(3, $result);
    }

    public function testAdsetMetrics(): void
    {
        $impressions = $this->faker->numberBetween(100, 1000);
        $clicks = $this->faker->numberBetween(1, 50);
        $date = $this->faker->date();

        $rows = [
            [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'date_start' => $date,
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
        $this->assertCount(3, $result);
    }

    public function testAdMetrics(): void
    {
        $impressions = $this->faker->numberBetween(10, 500);
        $clicks = $this->faker->numberBetween(0, 20);
        $date = $this->faker->date();

        $rows = [
            [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'date_start' => $date,
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
        $this->assertCount(3, $result);
    }

    public function testRobustness(): void
    {
        // 1. Page metrics with missing 'values'
        $rows = [['name' => 'page_impressions']];
        $result = FacebookGraphConvert::pageMetrics($rows, 'p123');
        $this->assertCount(0, $result);

        // 2. Ad account metrics with missing 'date_start'
        $rows = [['impressions' => 100]];
        $result = FacebookGraphConvert::adAccountMetrics($rows, null, $this->account, 'ca123');
        $this->assertCount(1, $result);
        $this->assertEquals(Carbon::now()->toDateString(), $result->first()->metricDate);

        // 3. IG account metrics with missing 'total_value'
        $rows = [['name' => 'impressions']];
        $result = FacebookGraphConvert::igAccountMetrics($rows, '2023-01-01', $this->page, $this->account, $this->channeledAccount);
        $this->assertCount(1, $result);
        $this->assertEquals(0, $result->first()->value);

        // 4. IG media metrics with empty 'values'
        $rows = [['name' => 'likes', 'values' => []]];
        $result = FacebookGraphConvert::igMediaMetrics($rows, $this->page, $this->post, $this->account, $this->channeledAccount);
        $this->assertCount(1, $result);
        $this->assertEquals(0, $result->first()->value);
    }
}
