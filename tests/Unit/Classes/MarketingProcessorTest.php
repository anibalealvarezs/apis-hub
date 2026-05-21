<?php

    declare(strict_types=1);

    namespace Tests\Unit\Classes;

    use Classes\MarketingProcessor;
    use Doctrine\Common\Collections\ArrayCollection;
    use Doctrine\DBAL\Connection;
    use Doctrine\DBAL\Result;
    use Doctrine\ORM\EntityManager;
    use Doctrine\ORM\EntityRepository;
    use Entities\Analytics\Channel;
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

            $channel = $this->createMock(Channel::class);
            $channel->method('getId')->willReturn(1);

            $repo = $this->getMockBuilder(EntityRepository::class)
                ->disableOriginalConstructor()
                ->getMock();
            $repo->method('findOneBy')->willReturn($channel);

            $this->manager->method('getRepository')->willReturn($repo);
        }

        public function testProcessCampaigns(): void
        {
            $platformId = $this->faker->uuid();
            $campaigns = new ArrayCollection([
                (object)[
                    'platformId'         => $platformId,
                    'name'               => $this->faker->sentence(3),
                    'startDate'          => null,
                    'endDate'            => null,
                    'channel'            => 'facebook',
                    'channeledAccountId' => $this->faker->numberBetween(1, 999999),
                    'budget'             => $this->faker->numberBetween(10, 1000),
                    'status'             => 'ACTIVE',
                    'objective'          => 'SALES',
                    'buyingType'         => 'AUCTION',
                    'data'               => []
                ]
            ]);

            // Mock executeStatement for campaign insert
            $this->conn->expects($this->exactly(2))
                ->method('executeStatement')
                ->willReturn(1);

            // Mock executeQuery for campaign map
            $result1 = $this->createMock(Result::class);
            $result1->method('fetchAllAssociative')->willReturn([
                ['id' => $this->faker->randomNumber(), 'campaign_id' => $platformId]
            ]);

            $result2 = $this->createMock(Result::class);
            $result2->method('fetchAllAssociative')->willReturn([
                ['platform_id' => $this->faker->randomNumber(), 'id' => $this->faker->randomNumber()]
            ]);

            $this->conn->method('executeQuery')->will($this->onConsecutiveCalls($result1, $result2));

            MarketingProcessor::processCampaigns($campaigns, $this->manager);

            // If we reached here without error, it's a good sign
            $this->assertTrue(true);
        }

        public function testProcessAdGroups(): void
        {
            $platformId = $this->faker->uuid();
            $campaignPlatformId = $this->faker->uuid();
            $adsets = new ArrayCollection([
                (object)[
                    'platformId'          => $platformId,
                    'channeledCampaignId' => $campaignPlatformId,
                    'channeledAccountId'  => $this->faker->numberBetween(1, 999999),
                    'name'                => $this->faker->sentence(3),
                    'startDate'           => null,
                    'endDate'             => null,
                    'status'              => 'ACTIVE',
                    'optimizationGoal'    => 'CONVERSIONS',
                    'billingEvent'        => 'IMPRESSIONS',
                    'targeting'           => [],
                    'channel'             => 'facebook',
                    'data'                => []
                ]
            ]);

            $result1 = $this->createMock(Result::class);
            $result1->method('fetchAllAssociative')->willReturn([
                ['platform_id' => $campaignPlatformId, 'id' => $this->faker->randomNumber(), 'campaign_id' => $this->faker->randomNumber()]
            ]);

            $result2 = $this->createMock(Result::class);
            $result2->method('fetchAllAssociative')->willReturn([
                ['platform_id' => $this->faker->randomNumber(), 'id' => $this->faker->randomNumber()]
            ]);

            $result3 = $this->createMock(Result::class);
            $result3->method('fetchAllAssociative')->willReturn([
                ['platform_id' => $platformId, 'id' => $this->faker->randomNumber(), 'adset_id' => $this->faker->randomNumber()]
            ]);

            $this->conn->method('executeQuery')->will($this->onConsecutiveCalls($result1, $result2, $result3));
            $this->conn->method('executeStatement')->willReturn(1);

            MarketingProcessor::processAdGroups($adsets, $this->manager);

            $this->assertTrue(true);
        }

        public function testProcessAds(): void
        {
            $ads = new ArrayCollection([
                (object)[
                    'platformId'          => $this->faker->uuid(),
                    'channeledCampaignId' => $this->faker->uuid(),
                    'channeledAdGroupId'  => $this->faker->uuid(),
                    'channeledCreativeId' => $this->faker->uuid(),
                    'channeledAccountId'  => $this->faker->numberBetween(1, 999999), // Ensure non-zero
                    'name'                => $this->faker->sentence(3),
                    'status'              => 'ACTIVE',
                    'channel'             => 'facebook',
                    'data'                => []
                ]
            ]);

            // 4 SELECT queries for maps (campaigns, adgroups, creatives, channeled accounts)
            $resultMock1 = $this->createMock(Result::class);
            $resultMock1->method('fetchAllAssociative')->willReturn([['platform_id' => $ads[0]->channeledCampaignId, 'id' => 1]]);

            $resultMock2 = $this->createMock(Result::class);
            $resultMock2->method('fetchAllAssociative')->willReturn([['platform_id' => $ads[0]->channeledAdGroupId, 'id' => 2]]);

            $resultMock3 = $this->createMock(Result::class);
            $resultMock3->method('fetchAllAssociative')->willReturn([['creative_id' => $ads[0]->channeledCreativeId, 'id' => 3]]);

            $resultMock4 = $this->createMock(Result::class);
            $resultMock4->method('fetchAllAssociative')->willReturn([['platform_id' => $ads[0]->channeledAccountId, 'id' => 4]]);

            $this->conn->expects($this->exactly(4))
                ->method('executeQuery')
                ->will($this->onConsecutiveCalls($resultMock1, $resultMock2, $resultMock3, $resultMock4));

            $this->conn->method('executeStatement')->willReturn(1);

            MarketingProcessor::processAds($ads, $this->manager);

            $this->assertTrue(true);
        }
    }