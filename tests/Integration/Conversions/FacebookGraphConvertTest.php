<?php

namespace Tests\Integration\Conversions;

use Classes\Conversions\FacebookGraphConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Entities\Analytics\Account;
use Entities\Analytics\Campaign;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Entities\Analytics\Page;
use Entities\Analytics\Post;
use Enums\Period;
use Tests\Integration\BaseIntegrationTestCase;

class FacebookGraphConvertTest extends BaseIntegrationTestCase
{
    public function testAdAccountMetricsTransformsDataCorrectly(): void
    {
        // 1. Arrange: Create our Database Account entity
        $accountEntity = new Account();
        $accountEntity->addName('Test FB Account');
        $this->entityManager->persist($accountEntity);
        $this->entityManager->flush();

        // Simulate 2 raw rows returned from Facebook Insights API
        $rows = [
            [
                'age' => '18-24',
                'gender' => 'male',
                'date_start' => '2026-03-05',
                'impressions' => '5000',
                'clicks' => '100',
                'spend' => '25.50',
                'actions' => [
                    ['action_type' => 'link_click', 'value' => '50']
                ]
            ],
            [
                'age' => '25-34',
                'gender' => 'female',
                'date_start' => '2026-03-05',
                'impressions' => '3000',
                'clicks' => '45',
                'spend' => '15.00',
                'cost_per_unique_outbound_click' => [
                     ['action_type' => 'cost_per_unique_outbound_click', 'value' => '0.33']
                ]
            ]
        ];

        // 2. Act: Pipe Data through the FacebookGraphConvert tool
        $collection = FacebookGraphConvert::adAccountMetrics(
            rows: $rows,
            logger: null,
            accountEntity: $accountEntity,
            channeledAccountPlatformId: 'act_123456789',
            period: Period::Daily
        );

        // 3. Assert
        $this->assertInstanceOf(ArrayCollection::class, $collection);
        
        // Row 1 has 3 metrics matching the filter (impressions, clicks, spend).
        // Row 2 has 4 metrics matching the filter (impressions, clicks, spend, cost_per_unique_outbound_click).
        // Total metrics should be 7
        $this->assertCount(7, $collection);

        $metricsMap = [];
        foreach ($collection as $item) {
            $age = '';
            $gender = '';
            foreach ($item->dimensions as $dim) {
                if ($dim['dimensionKey'] === 'age') $age = $dim['dimensionValue'];
                if ($dim['dimensionKey'] === 'gender') $gender = $dim['dimensionValue'];
            }
            $metricsMap[$age . '_' . $gender . '_' . $item->name] = $item;
        }

        // Validate Row 1 assertions
        $this->assertArrayHasKey('18-24_male_impressions', $metricsMap);
        $this->assertArrayHasKey('18-24_male_clicks', $metricsMap);
        $this->assertArrayHasKey('18-24_male_spend', $metricsMap);

        $this->assertEquals('5000', $metricsMap['18-24_male_impressions']->value);
        $this->assertEquals('act_123456789', $metricsMap['18-24_male_impressions']->platformId);
        $this->assertEquals(\Enums\Channel::facebook->value, $metricsMap['18-24_male_impressions']->channel);
        $this->assertEquals('2026-03-05', $metricsMap['18-24_male_impressions']->metricDate);

        // Ensure metadata extraction captures complex fields like actions natively
        $this->assertArrayHasKey('actions', $metricsMap['18-24_male_impressions']->metadata);
        $this->assertEquals('50', $metricsMap['18-24_male_impressions']->metadata['actions'][0]['value']);

        // Validate Row 2 assertions
        $this->assertArrayHasKey('25-34_female_cost_per_unique_outbound_click', $metricsMap);
        // The Conversion script has a specific ternary for cost_per_unique_outbound_click checking array indexing:
        $this->assertEquals('0.33', $metricsMap['25-34_female_cost_per_unique_outbound_click']->value); 
    }

    public function testPageMetricsTransformsDataCorrectly(): void
    {
        $pageEntity = new Page();
        $pageEntity->addUrl('https://facebook.com/testpage');
        $pageEntity->addPlatformId('page_123');
        $this->entityManager->persist($pageEntity);
        $this->entityManager->flush();

        $rows = [
            [
                'name' => 'page_impressions',
                'values' => [
                    ['value' => 5000, 'end_time' => '2026-03-05T07:00:00+0000'],
                    ['value' => 6000, 'end_time' => '2026-03-06T07:00:00+0000']
                ]
            ]
        ];

        $collection = FacebookGraphConvert::pageMetrics(
            rows: $rows,
            pagePlatformId: 'page_123',
            postPlatformId: '',
            logger: null,
            pageEntity: $pageEntity,
            postEntity: null,
            period: Period::Daily
        );

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(2, $collection);
    }

    public function testIgAccountMetricsTransformsDataCorrectly(): void
    {
        $accountEntity = new Account();
        $accountEntity->addName('IG Account');
        $this->entityManager->persist($accountEntity);
        
        $channeledAccount = new ChanneledAccount();
        $channeledAccount->addPlatformId('ig_123');
        $channeledAccount->addName('IG Act');
        $channeledAccount->addType(\Enums\Account::INSTAGRAM);
        $channeledAccount->addChannel(\Enums\Channel::facebook->value);
        $channeledAccount->addAccount($accountEntity);
        $this->entityManager->persist($channeledAccount);
        $this->entityManager->flush();

        $rows = [
            [
                'name' => 'follower_count',
                'total_value' => [
                    'breakdowns' => [
                        [
                            'dimension_keys' => ['country'],
                            'results' => [
                                ['dimension_values' => ['US'], 'value' => 500],
                                ['dimension_values' => ['CA'], 'value' => 200]
                            ]
                        ]
                    ]
                ]
            ],
            [
                'name' => 'impressions',
                'total_value' => ['value' => 1000]
            ]
        ];

        $collection = FacebookGraphConvert::igAccountMetrics(
            rows: $rows,
            date: '2026-03-05',
            pageEntity: null,
            accountEntity: $accountEntity,
            channeledAccountEntity: $channeledAccount,
            logger: null,
            period: Period::Daily
        );

        $this->assertCount(3, $collection);
    }

    public function testCampaignMetricsTransformsDataCorrectly(): void
    {
        $campaignEntity = new Campaign();
        $campaignEntity->addCampaignId('camp_abc');
        $campaignEntity->addName('Test Campaign');
        $this->entityManager->persist($campaignEntity);
        
        $channeledCampaign = new ChanneledCampaign();
        $channeledCampaign->addPlatformId('c_123');
        $channeledCampaign->addBudget(100.0);
        $channeledCampaign->addChannel(\Enums\Channel::facebook->value);
        $channeledCampaign->addCampaign($campaignEntity);
        
        $channeledAccount = new ChanneledAccount();
        $channeledAccount->addPlatformId('act_123');
        $channeledAccount->addName('Account_Name');
        $channeledAccount->addType(\Enums\Account::META_AD_ACCOUNT);
        $channeledAccount->addChannel(\Enums\Channel::facebook->value);
        // Campaign requires account technically in DB relations typically, but we only supply it direct for metric config signature
        
        $this->entityManager->persist($channeledAccount);
        $this->entityManager->persist($channeledCampaign);
        $this->entityManager->flush();

        $rows = [
            [
                'age' => '18-24',
                'gender' => 'male',
                'date_start' => '2026-03-05',
                'impressions' => '1000',
                'clicks' => '50'
            ]
        ];

        $collection = FacebookGraphConvert::campaignMetrics(
            rows: $rows,
            logger: null,
            channeledAccountEntity: $channeledAccount,
            campaignEntity: $campaignEntity,
            channeledCampaignEntity: $channeledCampaign,
            period: Period::Daily
        );

        $this->assertCount(2, $collection); // Impressions and clicks
    }
}
