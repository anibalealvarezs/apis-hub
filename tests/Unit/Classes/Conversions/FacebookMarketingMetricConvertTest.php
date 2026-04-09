<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\Conversions;

use Anibalealvarezs\FacebookGraphApi\Conversions\FacebookMarketingMetricConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Entities\Analytics\Account;
use Entities\Analytics\Campaign;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Channeled\ChanneledAd;
use Entities\Analytics\Channeled\ChanneledAdGroup;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Enums\Period;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\Unit\BaseUnitTestCase;
use Carbon\Carbon;

class FacebookMarketingMetricConvertTest extends BaseUnitTestCase
{
    private MockObject|Account $account;
    private MockObject|ChanneledAccount $channeledAccount;
    private MockObject|Campaign $campaign;
    private MockObject|ChanneledCampaign $channeledCampaign;
    private MockObject|ChanneledAdGroup $channeledAdGroup;
    private MockObject|ChanneledAd $channeledAd;

    protected function setUp(): void
    {
        parent::setUp();

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

        $result = FacebookMarketingMetricConvert::adAccountMetrics(
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

        $result = FacebookMarketingMetricConvert::campaignMetrics(
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

        $result = FacebookMarketingMetricConvert::adsetMetrics(
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

        $result = FacebookMarketingMetricConvert::adMetrics(
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

    public function testAdAccountRobustness(): void
    {
        // Ad account metrics with missing 'date_start'
        $rows = [['impressions' => 100]];
        $result = FacebookMarketingMetricConvert::adAccountMetrics($rows, null, $this->account, 'ca123');
        $this->assertCount(1, $result);
        $this->assertEquals(Carbon::now()->toDateString(), $result->first()->metricDate);
    }
}
