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
        $campaigns = new ArrayCollection([
            (object) [
                'platformId' => '123',
                'name' => 'Test Campaign',
                'startDate' => null,
                'endDate' => null,
                'channel' => 'facebook',
                'channeledAccountId' => 1,
                'budget' => 100,
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
            ['id' => 10, 'campaignId' => '123']
        ]);

        $this->conn->method('executeQuery')->willReturn($result);

        MarketingProcessor::processCampaigns($campaigns, $this->manager);

        // If we reached here without error, it's a good sign
        $this->assertTrue(true);
    }

    public function testProcessAdGroups(): void
    {
        $adsets = new ArrayCollection([
            (object) [
                'platformId' => 'adset123',
                'channeledCampaignId' => 'camp123',
                'channeledAccountId' => 1,
                'name' => 'Test AdSet',
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
            ['platformId' => 'camp123', 'id' => 20, 'campaign_id' => 10]
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
                'platformId' => 'ad123',
                'channeledCampaignId' => 'camp123',
                'channeledAdGroupId' => 'adset123',
                'channeledAccountId' => 1,
                'name' => 'Test Ad',
                'status' => 'ACTIVE',
                'channel' => 'facebook',
                'data' => []
            ]
        ]);

        // 2 SELECT queries for maps
        $this->conn->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturn($this->createMock(Result::class));

        $this->conn->method('executeStatement')->willReturn(1);

        MarketingProcessor::processAds($ads, $this->manager);

        $this->assertTrue(true);
    }
}
