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
use Anibalealvarezs\FacebookGraphApi\Enums\MetricSet;
use Tests\Integration\BaseIntegrationTestCase;

class FacebookGraphConvertTest extends BaseIntegrationTestCase
{
    public function testAdAccountMetricsTransformsDataCorrectly(): void
    {
        // 1. Arrange: Create our Database Account entity
        $accountEntity = new Account();
        $accountEntity->addName($this->faker->company . ' Account');
        $this->entityManager->persist($accountEntity);
        $this->entityManager->flush();

        $dateStart = $this->faker->date();
        $channeledAccountPlatformId = 'act_' . $this->faker->numerify('#########');

        // Simulate 2 raw raw rows returned from Facebook Insights API
        $rows = [
            [
                'age' => '18-24',
                'gender' => 'male',
                'date_start' => $dateStart,
                'impressions' => (string) $this->faker->numberBetween(1000, 10000),
                'clicks' => (string) $this->faker->numberBetween(10, 500),
                'spend' => (string) $this->faker->randomFloat(2, 5, 200),
                'actions' => [
                    ['action_type' => 'link_click', 'value' => (string) $this->faker->numberBetween(5, 100)]
                ]
            ],
            [
                'age' => '25-34',
                'gender' => 'female',
                'date_start' => $dateStart,
                'impressions' => (string) $this->faker->numberBetween(1000, 10000),
                'clicks' => (string) $this->faker->numberBetween(10, 500),
                'spend' => (string) $this->faker->randomFloat(2, 5, 200),
                'cost_per_unique_outbound_click' => [
                     ['action_type' => 'cost_per_unique_outbound_click', 'value' => (string) $this->faker->randomFloat(2, 0.1, 2.0)]
                ]
            ]
        ];

        // 2. Act: Pipe Data through the FacebookGraphConvert tool
        $collection = FacebookGraphConvert::adAccountMetrics(
            rows: $rows,
            logger: null,
            accountEntity: $accountEntity,
            channeledAccountPlatformId: $channeledAccountPlatformId,
            period: Period::Daily,
            metricSet: MetricSet::FULL,
        );

        // 3. Assert
        $this->assertInstanceOf(ArrayCollection::class, $collection);
        
        // Row 1 has 4 metrics matching the filter (impressions, clicks, spend, actions).
        // Row 2 has 4 metrics matching the filter (impressions, clicks, spend, cost_per_unique_outbound_click).
        // Total metrics should be 8
        $this->assertCount(8, $collection);

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

        $this->assertEquals($rows[0]['impressions'], $metricsMap['18-24_male_impressions']->value);
        $this->assertEquals($channeledAccountPlatformId, $metricsMap['18-24_male_impressions']->platformId);
        $this->assertEquals(\Enums\Channel::facebook->value, $metricsMap['18-24_male_impressions']->channel);
        $this->assertEquals($dateStart, $metricsMap['18-24_male_impressions']->metricDate);

        // Ensure metadata extraction captures complex fields like actions natively
        $this->assertArrayHasKey('actions', $metricsMap['18-24_male_impressions']->metadata);
        $this->assertEquals($rows[0]['actions'][0]['value'], $metricsMap['18-24_male_impressions']->metadata['actions'][0]['value']);

        // Validate Row 2 assertions
        $this->assertArrayHasKey('25-34_female_cost_per_unique_outbound_click', $metricsMap);
        // The Conversion script has a specific ternary for cost_per_unique_outbound_click checking array indexing:
        $this->assertEquals($rows[1]['cost_per_unique_outbound_click'][0]['value'], $metricsMap['25-34_female_cost_per_unique_outbound_click']->value); 
    }

    public function testPageMetricsTransformsDataCorrectly(): void
    {
        $pagePlatformId = $this->faker->uuid;
        $pageEntity = new Page();
        $pageEntity->addUrl($this->faker->url);
        $pageEntity->addPlatformId($pagePlatformId);
        $this->entityManager->persist($pageEntity);
        $this->entityManager->flush();

        $rows = [
            [
                'name' => 'page_impressions',
                'values' => [
                    ['value' => $this->faker->numberBetween(100, 10000), 'end_time' => $this->faker->iso8601],
                    ['value' => $this->faker->numberBetween(100, 10000), 'end_time' => $this->faker->iso8601]
                ]
            ]
        ];

        $collection = FacebookGraphConvert::pageMetrics(
            rows: $rows,
            pagePlatformId: $pagePlatformId,
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
        $accountEntity->addName($this->faker->name . ' Account');
        $this->entityManager->persist($accountEntity);
        
        $igPlatformId = 'ig_' . $this->faker->numerify('#####');
        $channeledAccount = new ChanneledAccount();
        $channeledAccount->addPlatformId($igPlatformId);
        $channeledAccount->addName($this->faker->userName);
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
                                ['dimension_values' => [$this->faker->countryCode], 'value' => $this->faker->numberBetween(1, 1000)],
                                ['dimension_values' => [$this->faker->countryCode], 'value' => $this->faker->numberBetween(1, 1000)]
                            ]
                        ]
                    ]
                ]
            ],
            [
                'name' => 'impressions',
                'total_value' => ['value' => $this->faker->numberBetween(1, 10000)]
            ]
        ];

        $collection = FacebookGraphConvert::igAccountMetrics(
            rows: $rows,
            date: $this->faker->date(),
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
        $campaignPlatformId = $this->faker->uuid;
        $campaignEntity = new Campaign();
        $campaignEntity->addCampaignId($campaignPlatformId);
        $campaignEntity->addName($this->faker->sentence(3));
        $this->entityManager->persist($campaignEntity);
        
        $channeledCampaign = new ChanneledCampaign();
        $channeledCampaign->addPlatformId($this->faker->uuid);
        $channeledCampaign->addBudget((float) $this->faker->numberBetween(50, 5000));
        $channeledCampaign->addChannel(\Enums\Channel::facebook->value);
        $channeledCampaign->addCampaign($campaignEntity);
        
        $channeledAccount = new ChanneledAccount();
        $channeledAccount->addPlatformId('act_' . $this->faker->numerify('#########'));
        $channeledAccount->addName($this->faker->company);
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
                'date_start' => $this->faker->date(),
                'impressions' => (string) $this->faker->numberBetween(100, 10000),
                'clicks' => (string) $this->faker->numberBetween(1, 500)
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

        $this->assertCount(2, $collection); // Impressions and clicks (actions missing from row)
    }

    public function testRobustness(): void
    {
        // 1. AdAccount with empty actions (should default to 0)
        $accountEntity = new Account();
        $accountEntity->addName($this->faker->company);
        $this->entityManager->persist($accountEntity);
        $this->entityManager->flush();

        $rows = [['date_start' => $this->faker->date(), 'impressions' => '100', 'actions' => []]];
        $collection = FacebookGraphConvert::adAccountMetrics($rows, null, $accountEntity, metricSet: MetricSet::BASIC);
        $this->assertCount(2, $collection); // Impressions and actions
        $this->assertEquals('100', $collection->first()->value);

        // 2. AdAccount with missing spend (should be skipped by metrics filter typically, but let's check)
        $rows = [['date_start' => $this->faker->date(), 'impressions' => '100']];
        $collection = FacebookGraphConvert::adAccountMetrics($rows, null, $accountEntity, metricSet: MetricSet::BASIC);
        $this->assertCount(1, $collection);
    }
}
