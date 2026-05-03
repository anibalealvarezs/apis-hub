<?php

declare(strict_types=1);

namespace Tests\Integration\Conversions;

use Anibalealvarezs\MetaHubDriver\Conversions\FacebookMarketingMetricConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Entities\Analytics\Account;
use Entities\Analytics\Campaign;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Anibalealvarezs\ApiSkeleton\Enums\Period;
use Anibalealvarezs\FacebookGraphApi\Enums\MetricSet;
use Tests\Integration\BaseIntegrationTestCase;
use Anibalealvarezs\ApiSkeleton\Enums\Channel;

class FacebookMarketingMetricIntegrationTest extends BaseIntegrationTestCase
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

        // 2. Act: Pipe Data through the FacebookMarketingMetricConvert tool
        $collection = FacebookMarketingMetricConvert::adAccountMetrics(
            rows: $rows,
            logger: null,
            account: $accountEntity,
            channeledAccount: $channeledAccountPlatformId,
            period: Period::Daily,
            metricSet: MetricSet::FULL,
        );

        // 3. Assert
        $this->assertInstanceOf(ArrayCollection::class, $collection);
        
        $this->assertCount(10, $collection);

        $metricsMap = [];
        foreach ($collection as $item) {
            if (!$item) continue;
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
        $metricImpressions = $metricsMap['18-24_male_impressions'];
        $this->assertNotNull($metricImpressions);

        $this->assertArrayHasKey('18-24_male_clicks', $metricsMap);
        $metricClicks = $metricsMap['18-24_male_clicks'];
        $this->assertNotNull($metricClicks);

        $this->assertArrayHasKey('18-24_male_spend', $metricsMap);
        $metricSpend = $metricsMap['18-24_male_spend'];
        $this->assertNotNull($metricSpend);

        $this->assertEquals($rows[0]['impressions'], $metricImpressions->value);
        $this->assertEquals($channeledAccountPlatformId, $metricImpressions->platformId);
        $this->assertEquals(Channel::facebook_marketing->value, $metricImpressions->channel);
        $this->assertEquals($dateStart, $metricImpressions->metricDate);

        // Ensure metadata extraction captures complex fields like actions natively
        $this->assertArrayHasKey('actions', $metricImpressions->metadata);
        $this->assertEquals($rows[0]['actions'][0]['value'], $metricImpressions->metadata['actions'][0]['value']);

        // Validate Row 2 assertions
        $this->assertArrayHasKey('25-34_female_cost_per_unique_outbound_click', $metricsMap);
        $metricCost = $metricsMap['25-34_female_cost_per_unique_outbound_click'];
        $this->assertNotNull($metricCost);
        $this->assertEquals($rows[1]['cost_per_unique_outbound_click'][0]['value'], $metricCost->value);
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
        $channeledCampaign->addChannel(Channel::facebook_marketing->value);
        $channeledCampaign->addCampaign($campaignEntity);
        
        $accountEntity = new Account();
        $accountEntity->addName($this->faker->company . ' Root Account');
        $this->entityManager->persist($accountEntity);
        
        $channeledAccount = new ChanneledAccount();
        $channeledAccount->addPlatformId('act_' . $this->faker->numerify('#########'));
        $channeledAccount->addName($this->faker->company);
        $channeledAccount->addType('META_AD_ACCOUNT');
        $channeledAccount->addChannel(Channel::facebook_marketing->value);
        $channeledAccount->addAccount($accountEntity);
        
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

        $collection = FacebookMarketingMetricConvert::campaignMetrics(
            rows: $rows,
            logger: null,
            channeledAccount: $channeledAccount,
            campaign: $campaignEntity,
            channeledCampaign: $channeledCampaign,
            period: Period::Daily
        );

        $this->assertCount(2, $collection);
        $metric = $collection->first();
        $this->assertEquals($campaignEntity, $metric->campaign);
        $this->assertEquals($channeledCampaign, $metric->channeledCampaign);
        $this->assertEquals($channeledAccount, $metric->channeledAccount);
    }

    public function testRobustness(): void
    {
        $accountEntity = new Account();
        $accountEntity->addName($this->faker->company);
        $this->entityManager->persist($accountEntity);
        $this->entityManager->flush();

        $rows = [['date_start' => $this->faker->date(), 'impressions' => '100', 'actions' => []]];
        $collection = FacebookMarketingMetricConvert::adAccountMetrics($rows, null, $accountEntity, metricSet: MetricSet::BASIC);
        $this->assertCount(2, $collection);
        $first = $collection->first();
        $this->assertNotNull($first);
        $this->assertEquals('100', $first->value);

        $rows = [['date_start' => $this->faker->date(), 'impressions' => '100']];
        $collection = FacebookMarketingMetricConvert::adAccountMetrics($rows, null, $accountEntity, metricSet: MetricSet::BASIC);
        $this->assertCount(1, $collection);
    }
}
