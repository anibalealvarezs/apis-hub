<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\Conversions;

use Classes\Conversions\FacebookConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use Tests\Unit\BaseUnitTestCase;

class FacebookConvertTest extends BaseUnitTestCase
{
    public function testCampaigns(): void
    {
        $platformId = '123456789';
        $name = 'Test Campaign';
        $status = 'ACTIVE';
        $objective = 'OUTCOME_SALES';
        $channeledAccountId = 1;

        $data = [
            [
                'id' => $platformId,
                'name' => $name,
                'status' => $status,
                'objective' => $objective,
                'start_time' => '2024-01-01T00:00:00+0000',
                'stop_time' => '2024-12-31T23:59:59+0000',
                'lifetime_budget' => '100000',
                'buying_type' => 'AUCTION',
            ]
        ];

        $result = FacebookConvert::campaigns($data, $channeledAccountId);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $campaign = $result->first();

        $this->assertEquals($platformId, $campaign->platformId);
        $this->assertEquals($name, $campaign->name);
        $this->assertEquals($status, $campaign->status);
        $this->assertEquals($channeledAccountId, $campaign->channeledAccountId);
        $this->assertEquals(Channel::facebook_marketing->value, $campaign->channel);
    }

    public function testAdsets(): void
    {
        $platformId = '987654321';
        $name = 'Test AdSet';
        $campaignId = '123456789';
        $channeledAccountId = 1;

        $data = [
            [
                'id' => $platformId,
                'name' => $name,
                'campaign_id' => $campaignId,
                'status' => 'ACTIVE',
                'optimization_goal' => 'OFFSITE_CONVERSIONS',
                'billing_event' => 'IMPRESSIONS',
                'start_time' => '2024-01-01T00:00:00+0000',
                'end_time' => '2024-12-31T23:59:59+0000',
                'targeting' => ['geo_locations' => ['countries' => ['US']]],
            ]
        ];

        $result = FacebookConvert::adsets($data, $channeledAccountId);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $adset = $result->first();

        $this->assertEquals($platformId, $adset->platformId);
        $this->assertEquals($name, $adset->name);
        $this->assertEquals($campaignId, $adset->channeledCampaignId);
        $this->assertEquals($channeledAccountId, $adset->channeledAccountId);
    }

    public function testAds(): void
    {
        $platformId = '456789123';
        $name = 'Test Ad';
        $campaignId = '123456789';
        $adsetId = '987654321';
        $channeledAccountId = 1;

        $data = [
            [
                'id' => $platformId,
                'name' => $name,
                'campaign_id' => $campaignId,
                'adset_id' => $adsetId,
                'status' => 'ACTIVE',
            ]
        ];

        $result = FacebookConvert::ads($data, $channeledAccountId);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $ad = $result->first();

        $this->assertEquals($platformId, $ad->platformId);
        $this->assertEquals($name, $ad->name);
        $this->assertEquals($adsetId, $ad->channeledAdGroupId);
    }

    public function testPages(): void
    {
        $platformId = '111222333';
        $name = 'Test Page';
        $accountId = 1;

        $data = [
            [
                'id' => $platformId,
                'name' => $name,
                'access_token' => 'dummy_token',
                'category' => 'Test Category',
            ]
        ];

        $result = FacebookConvert::pages($data, $accountId);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $page = $result->first();

        $this->assertEquals($platformId, $page->platformId);
        $this->assertEquals($name, $page->title);
        $this->assertEquals($accountId, $page->accountId);
    }

    public function testPosts(): void
    {
        $platformId = '777888999';
        $message = 'Test Post Message';
        $pageId = 123;
        $accountId = 1;

        $data = [
            [
                'id' => $platformId,
                'message' => $message,
                'created_time' => '2024-01-01T00:00:00+0000',
                'permalink_url' => 'https://facebook.com/post/1',
            ]
        ];

        $result = FacebookConvert::posts($data, $pageId, $accountId);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $post = $result->first();

        $this->assertEquals($platformId, $post->platformId);
        $this->assertEquals($pageId, $post->pageId);
    }
}
