<?php

declare(strict_types=1);

namespace Tests\Unit\Classes;

use Classes\MarketingProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManager;
use Tests\Unit\BaseUnitTestCase;

class MarketingProcessorTest extends BaseUnitTestCase
{
    private $conn;
    private $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = $this->createMock(Connection::class);
        $this->manager = $this->createMock(EntityManager::class);
        $this->manager->method('getConnection')->willReturn($this->conn);
    }

    public function testProcessCampaigns(): void
    {
        $platformId = $this->faker->uuid();
        $campaigns = new ArrayCollection([
            (object) [
                'platformId' => $platformId,
                'name' => $this->faker->sentence(3),
                'startDate' => null,
                'endDate' => null,
                'channel' => 'facebook',
                'channeledAccountId' => $this->faker->randomNumber(),
                'budget' => $this->faker->numberBetween(10, 1000),
                'status' => 'ACTIVE',
                'objective' => 'SALES',
                'buyingType' => 'AUCTION',
                'data' => []
            ]
        ]);

        // Mock executeStatement for campaign insert
        $this->conn->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturn(1);

        // Mock executeQuery for campaign map
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['id' => $this->faker->randomNumber(), 'campaign_id' => $platformId]
        ]);

        $this->conn->method('executeQuery')->willReturn($result);

        MarketingProcessor::processCampaigns($campaigns, $this->manager);

        // If we reached here without error, it's a good sign
        $this->assertTrue(true);
    }

    public function testProcessAdGroups(): void
    {
        $platformId = $this->faker->uuid();
        $campaignPlatformId = $this->faker->uuid();
        $adsets = new ArrayCollection([
            (object) [
                'platformId' => $platformId,
                'channeledCampaignId' => $campaignPlatformId,
                'channeledAccountId' => $this->faker->randomNumber(),
                'name' => $this->faker->sentence(3),
                'startDate' => null,
                'endDate' => null,
                'status' => 'ACTIVE',
                'optimizationGoal' => 'CONVERSIONS',
                'billingEvent' => 'IMPRESSIONS',
                'targeting' => [],
                'channel' => 'facebook',
                'data' => []
            ]
        ]);

        // Mock executeQuery for campaign map
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['platform_id' => $campaignPlatformId, 'id' => $this->faker->randomNumber(), 'campaign_id' => $this->faker->randomNumber()]
        ]);

        $this->conn->method('executeQuery')->willReturn($result);
        $this->conn->method('executeStatement')->willReturn(1);

        MarketingProcessor::processAdGroups($adsets, $this->manager);

        $this->assertTrue(true);
    }

    public function testProcessAds(): void
    {
        $ads = new ArrayCollection([
            (object) [
                'platformId' => $this->faker->uuid(),
                'channeledCampaignId' => $this->faker->uuid(),
                'channeledAdGroupId' => $this->faker->uuid(),
                'channeledCreativeId' => $this->faker->uuid(),
                'channeledAccountId' => $this->faker->randomNumber(),
                'name' => $this->faker->sentence(3),
                'status' => 'ACTIVE',
                'channel' => 'facebook',
                'data' => []
            ]
        ]);


        // 3 SELECT queries for maps (campaigns, adgroups, creatives)
        $this->conn->expects($this->exactly(3))
            ->method('executeQuery')
            ->willReturn($this->createMock(Result::class));

        $this->conn->method('executeStatement')->willReturn(1);

        MarketingProcessor::processAds($ads, $this->manager);

        $this->assertTrue(true);
    }

}
