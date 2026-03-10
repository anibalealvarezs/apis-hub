<?php

namespace Tests\Integration\Requests;

use Classes\Requests\MetricRequests;
use Entities\Analytics\Account;
use Entities\Analytics\Campaign;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Channeled\ChanneledAd;
use Entities\Analytics\Channeled\ChanneledAdGroup;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Entities\Analytics\Channeled\ChanneledMetric;
use Enums\Channel;
use Enums\Account as AccountEnum;
use Psr\Log\LoggerInterface;
use Tests\Integration\BaseIntegrationTestCase;
use Anibalealvarezs\FacebookGraphApi\FacebookGraphApi;

class FacebookBulkMetricsTest extends BaseIntegrationTestCase
{
    /** @var FacebookGraphApi|\PHPUnit\Framework\MockObject\MockObject */
    private $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = $this->createMock(FacebookGraphApi::class);
    }

    public function testProcessCampaignsBulkStoresMetrics(): void
    {
        // 1. Arrange
        $adAccountId = 'act_12345';
        $campaignPlatformId = 'camp_67890';
        
        // Setup Account (Parent of ChanneledAccount)
        $account = new Account();
        $account->addName('Main Facebook Account');
        $this->entityManager->persist($account);

        // Setup entities
        $channeledAccount = new ChanneledAccount();
        $channeledAccount->addPlatformId($adAccountId);
        $channeledAccount->addName('Test Account');
        $channeledAccount->addChannel(Channel::facebook->value);
        $channeledAccount->addType(AccountEnum::META_AD_ACCOUNT);
        $channeledAccount->addAccount($account);
        $channeledAccount->addPlatformCreatedAt(new \DateTime());
        $this->entityManager->persist($channeledAccount);

        $campaign = new Campaign();
        $campaign->addCampaignId($campaignPlatformId);
        $campaign->addName('Test Campaign');
        $this->entityManager->persist($campaign);

        $channeledCampaign = new ChanneledCampaign();
        $channeledCampaign->addPlatformId($campaignPlatformId);
        $channeledCampaign->addChannel(Channel::facebook->value);
        $channeledCampaign->addChanneledAccount($channeledAccount);
        $channeledCampaign->addCampaign($campaign);
        $channeledCampaign->addBudget(100.0);
        $this->entityManager->persist($channeledCampaign);

        $this->entityManager->flush();

        $channeledCampaignMap = [
            'map' => [$campaignPlatformId => $channeledCampaign->getId()],
            'mapReverse' => [$channeledCampaign->getId() => $campaignPlatformId]
        ];
        $campaignMap = [
            'map' => [$campaignPlatformId => $campaign->getId()],
            'mapReverse' => [$campaign->getId() => $campaignPlatformId]
        ];

        $today = date('Y-m-d');
        $this->api->expects($this->once())
            ->method('getCampaignInsightsFromAdAccount')
            ->willReturn([
                'data' => [
                    [
                        'campaign_id' => $campaignPlatformId,
                        'impressions' => '1000',
                        'clicks' => '50',
                        'spend' => '10.5',
                        'date_start' => $today,
                        'date_stop' => $today,
                    ]
                ]
            ]);

        // 2. Act
        $reflection = new \ReflectionClass(MetricRequests::class);
        $method = $reflection->getMethod('processCampaignsBulk');
        $method->setAccessible(true);

        $result = $method->invoke(
            null,
            $this->api,
            $this->entityManager,
            $channeledAccount,
            $this->createMock(LoggerInterface::class),
            null,
            null,
            $channeledCampaignMap,
            $campaignMap
        );

        // 3. Assert
        $this->assertTrue($result);
        
        $metrics = $this->entityManager->getRepository(ChanneledMetric::class)->findAll();
        $this->assertNotEmpty($metrics);
        
        $foundImpressions = false;
        foreach ($metrics as $metric) {
            $config = $metric->getMetric()->getMetricConfig();
            if ($config->getName() === 'impressions') {
                $this->assertEquals(1000, $metric->getMetric()->getValue());
                $foundImpressions = true;
            }
        }
        $this->assertTrue($foundImpressions, 'Impressions metric not found in DB');
    }

    public function testProcessAdsetsBulkStoresMetrics(): void
    {
        // 1. Arrange
        $adAccountId = 'act_12345';
        $campaignPlatformId = 'camp_67890';
        $adsetPlatformId = 'set_54321';
        
        // Setup Account
        $account = new Account();
        $account->addName('Main Facebook Account');
        $this->entityManager->persist($account);

        // Setup entities
        $channeledAccount = new ChanneledAccount();
        $channeledAccount->addPlatformId($adAccountId);
        $channeledAccount->addName('Test Account');
        $channeledAccount->addChannel(Channel::facebook->value);
        $channeledAccount->addType(AccountEnum::META_AD_ACCOUNT);
        $channeledAccount->addAccount($account);
        $channeledAccount->addPlatformCreatedAt(new \DateTime());
        $this->entityManager->persist($channeledAccount);

        $campaign = new Campaign();
        $campaign->addCampaignId($campaignPlatformId);
        $campaign->addName('Test Campaign');
        $this->entityManager->persist($campaign);

        $channeledCampaign = new ChanneledCampaign();
        $channeledCampaign->addPlatformId($campaignPlatformId);
        $channeledCampaign->addChannel(Channel::facebook->value);
        $channeledCampaign->addChanneledAccount($channeledAccount);
        $channeledCampaign->addCampaign($campaign);
        $channeledCampaign->addBudget(100.0); // Added required budget
        $this->entityManager->persist($channeledCampaign);

        $channeledAdGroup = new ChanneledAdGroup();
        $channeledAdGroup->addPlatformId($adsetPlatformId);
        $channeledAdGroup->addName('Test Adset'); // Added required name
        $channeledAdGroup->addChannel(Channel::facebook->value);
        $channeledAdGroup->addChanneledAccount($channeledAccount);
        $channeledAdGroup->addChanneledCampaign($channeledCampaign);
        $channeledAdGroup->addCampaign($campaign);
        $this->entityManager->persist($channeledAdGroup);

        $this->entityManager->flush();

        $channeledCampaignMap = [
            'map' => [$campaignPlatformId => $channeledCampaign->getId()],
            'mapReverse' => [$channeledCampaign->getId() => $campaignPlatformId]
        ];
        $campaignMap = [
            'map' => [$campaignPlatformId => $campaign->getId()],
            'mapReverse' => [$campaign->getId() => $campaignPlatformId]
        ];
        $channeledAdGroupMap = [
            'map' => [$adsetPlatformId => $channeledAdGroup->getId()],
            'mapReverse' => [$channeledAdGroup->getId() => $adsetPlatformId],
            'mapCampaign' => [$adsetPlatformId => $campaignPlatformId]
        ];

        $today = date('Y-m-d');
        $this->api->expects($this->once())
            ->method('getAdsetInsightsFromAdAccount')
            ->willReturn([
                'data' => [
                    [
                        'adset_id' => $adsetPlatformId,
                        'impressions' => '500',
                        'clicks' => '25',
                        'spend' => '5.25',
                        'date_start' => $today,
                        'date_stop' => $today,
                    ]
                ]
            ]);

        // 2. Act
        $reflection = new \ReflectionClass(MetricRequests::class);
        $method = $reflection->getMethod('processAdsetsBulk');
        $method->setAccessible(true);

        $result = $method->invoke(
            null,
            $this->api,
            $this->entityManager,
            $channeledAccount,
            $this->createMock(LoggerInterface::class),
            null,
            null,
            $campaignMap,
            $channeledCampaignMap,
            $channeledAdGroupMap
        );

        // 3. Assert
        $this->assertTrue($result);
        
        $metrics = $this->entityManager->getRepository(ChanneledMetric::class)->findAll();
        $this->assertNotEmpty($metrics);
        
        $foundImpressions = false;
        foreach ($metrics as $metric) {
            $config = $metric->getMetric()->getMetricConfig();
            if ($config->getName() === 'impressions') {
                $this->assertEquals(500, $metric->getMetric()->getValue());
                $foundImpressions = true;
            }
        }
        $this->assertTrue($foundImpressions, 'Impressions metric not found for adset');
    }

    public function testProcessAdsBulkStoresMetrics(): void
    {
        // 1. Arrange
        $adAccountId = 'act_12345';
        $campaignPlatformId = 'camp_67890';
        $adsetPlatformId = 'set_54321';
        $adPlatformId = 'ad_11111';
        
        // Setup Account
        $account = new Account();
        $account->addName('Main Facebook Account');
        $this->entityManager->persist($account);

        // Setup entities
        $channeledAccount = new ChanneledAccount();
        $channeledAccount->addPlatformId($adAccountId);
        $channeledAccount->addName('Test Account');
        $channeledAccount->addChannel(Channel::facebook->value);
        $channeledAccount->addType(AccountEnum::META_AD_ACCOUNT);
        $channeledAccount->addAccount($account);
        $channeledAccount->addPlatformCreatedAt(new \DateTime());
        $this->entityManager->persist($channeledAccount);

        $campaign = new Campaign();
        $campaign->addCampaignId($campaignPlatformId);
        $campaign->addName('Test Campaign');
        $this->entityManager->persist($campaign);

        $channeledCampaign = new ChanneledCampaign();
        $channeledCampaign->addPlatformId($campaignPlatformId);
        $channeledCampaign->addChannel(Channel::facebook->value);
        $channeledCampaign->addChanneledAccount($channeledAccount);
        $channeledCampaign->addCampaign($campaign);
        $channeledCampaign->addBudget(100.0);
        $this->entityManager->persist($channeledCampaign);

        $channeledAdGroup = new ChanneledAdGroup();
        $channeledAdGroup->addPlatformId($adsetPlatformId);
        $channeledAdGroup->addName('Test Adset');
        $channeledAdGroup->addChannel(Channel::facebook->value);
        $channeledAdGroup->addChanneledAccount($channeledAccount);
        $channeledAdGroup->addChanneledCampaign($channeledCampaign);
        $channeledAdGroup->addCampaign($campaign);
        $this->entityManager->persist($channeledAdGroup);

        $channeledAd = new ChanneledAd();
        $channeledAd->addPlatformId($adPlatformId);
        $channeledAd->addName('Test Ad');
        $channeledAd->addChannel(Channel::facebook->value);
        $channeledAd->addChanneledCampaign($channeledCampaign);
        $channeledAd->addChanneledAdGroup($channeledAdGroup);
        $this->entityManager->persist($channeledAd);

        $this->entityManager->flush();

        $channeledCampaignMap = [
            'map' => [$campaignPlatformId => $channeledCampaign->getId()],
            'mapReverse' => [$channeledCampaign->getId() => $campaignPlatformId]
        ];
        $campaignMap = [
            'map' => [$campaignPlatformId => $campaign->getId()],
            'mapReverse' => [$campaign->getId() => $campaignPlatformId]
        ];
        $channeledAdGroupMap = [
            'map' => [$adsetPlatformId => $channeledAdGroup->getId()],
            'mapReverse' => [$channeledAdGroup->getId() => $adsetPlatformId],
            'mapCampaign' => [$adsetPlatformId => $campaignPlatformId]
        ];
        $channeledAdMap = [
            'map' => [$adPlatformId => $channeledAd->getId()],
            'mapReverse' => [$channeledAd->getId() => $adPlatformId],
            'mapAdGroup' => [$adPlatformId => $adsetPlatformId],
            'mapCampaign' => [$adPlatformId => $campaignPlatformId]
        ];

        $today = date('Y-m-d');
        $this->api->expects($this->once())
            ->method('getAdInsightsFromAdAccount')
            ->willReturn([
                'data' => [
                    [
                        'ad_id' => $adPlatformId,
                        'impressions' => '250',
                        'clicks' => '10',
                        'spend' => '2.5',
                        'date_start' => $today,
                        'date_stop' => $today,
                    ]
                ]
            ]);

        // 2. Act
        $reflection = new \ReflectionClass(MetricRequests::class);
        $method = $reflection->getMethod('processAdsBulk');
        $method->setAccessible(true);

        $result = $method->invoke(
            null,
            $this->api,
            $this->entityManager,
            $channeledAccount,
            $this->createMock(LoggerInterface::class),
            null,
            null,
            $campaignMap,
            $channeledCampaignMap,
            $channeledAdGroupMap,
            $channeledAdMap
        );

        // 3. Assert
        $this->assertTrue($result);
        
        $metrics = $this->entityManager->getRepository(ChanneledMetric::class)->findAll();
        $this->assertNotEmpty($metrics);
        
        $foundImpressions = false;
        foreach ($metrics as $metric) {
            $config = $metric->getMetric()->getMetricConfig();
            if ($config->getName() === 'impressions') {
                $this->assertEquals(250, $metric->getMetric()->getValue());
                $foundImpressions = true;
            }
        }
        $this->assertTrue($foundImpressions, 'Impressions metric not found for ad');
    }
}
