<?php

namespace Tests\Unit\Classes;

use Classes\MetricsProcessor;
use Doctrine\ORM\EntityManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\Common\Collections\ArrayCollection;
use Tests\Unit\BaseUnitTestCase;
use Entities\Analytics\Country;
use Entities\Analytics\Device;
use Enums\Country as CountryEnum;
use Enums\Device as DeviceEnum;

class MetricsProcessorTest extends BaseUnitTestCase
{
    private $entityManager;
    private $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->connection = $this->createMock(Connection::class);
        $this->entityManager->method('getConnection')->willReturn($this->connection);
    }

    public function testProcessQueriesReturnsMap(): void
    {
        $metricsArr = [
            (object)['query' => 'SELECT 1'],
            (object)['query' => 'SELECT 2'],
        ];
        $metrics = new ArrayCollection($metricsArr);

        // Mock Result for existing queries
        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [['id' => 101, 'query' => 'SELECT 1']], // First call: find existing
            [['id' => 101, 'query' => 'SELECT 1'], ['id' => 102, 'query' => 'SELECT 2']] // Second call: after insert
        );
        
        $this->connection->method('executeQuery')->willReturn($resultMock);
        
        // Mock executeStatement for INSERT IGNORE
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->willReturn(1);

        $res = MetricsProcessor::processQueries($metrics, $this->entityManager);
        
        $this->assertArrayHasKey('map', $res);
        $this->assertEquals(101, $res['map']['SELECT 1']);
        $this->assertEquals(102, $res['map']['SELECT 2']);
        $this->assertEquals('SELECT 1', $res['mapReverse'][101]);
    }

    public function testProcessCountriesReturnsMap(): void
    {
        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $this->entityManager->method('getRepository')->with(Country::class)->willReturn($repo);

        $country = $this->createMock(Country::class);
        $country->method('getCode')->willReturn(CountryEnum::USA);
        $country->method('getId')->willReturn(1);

        $repo->method('findAll')->willReturn([$country]);

        $res = MetricsProcessor::processCountries(new ArrayCollection(), $this->entityManager);
        
        $this->assertArrayHasKey('USA', $res['map']);
        $this->assertSame($country, $res['map']['USA']);
    }

    public function testProcessDevicesReturnsMap(): void
    {
        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $this->entityManager->method('getRepository')->with(Device::class)->willReturn($repo);

        $device = $this->createMock(Device::class);
        $device->method('getType')->willReturn(DeviceEnum::DESKTOP);
        $device->method('getId')->willReturn(5);

        $repo->method('findAll')->willReturn([$device]);

        $res = MetricsProcessor::processDevices(new ArrayCollection(), $this->entityManager);
        
        $this->assertArrayHasKey('desktop', $res['map']);
        $this->assertSame($device, $res['map']['desktop']);
    }

    public function testProcessAccountsReturnsMap(): void
    {
        $mockAcc = $this->createMock(\Entities\Analytics\Account::class);
        $mockAcc->method('getId')->willReturn(123);
        
        $metrics = new ArrayCollection([
            (object)['account' => $mockAcc]
        ]);

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAllAssociative')->willReturn([
            ['id' => 123]
        ]);
        
        $this->connection->method('executeQuery')->willReturn($resultMock);

        $res = MetricsProcessor::processAccounts($metrics, $this->entityManager);
        
        $this->assertArrayHasKey(123, $res['map']);
        $this->assertEquals(123, $res['map'][123]);
    }

    public function testProcessChanneledAccountsReturnsMap(): void
    {
        $metrics = new ArrayCollection([
            (object)['channeledAccountPlatformId' => 'p123']
        ]);

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAllAssociative')->willReturn([
            ['id' => 456, 'platform_id' => 'p123']
        ]);
        
        $this->connection->method('executeQuery')->willReturn($resultMock);

        $res = MetricsProcessor::processChanneledAccounts($metrics, $this->entityManager);
        
        $this->assertArrayHasKey('p123', $res['map']);
        $this->assertEquals(456, $res['map']['p123']);
    }

    public function testProcessCampaignsReturnsMap(): void
    {
        $mockCamp = $this->createMock(\Entities\Analytics\Campaign::class);
        $mockCamp->method('getCampaignId')->willReturn('c789');

        $metrics = new ArrayCollection([
            (object)['campaign' => $mockCamp]
        ]);

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAllAssociative')->willReturn([
            ['id' => 789, 'campaign_id' => 'c789']
        ]);
        
        $this->connection->method('executeQuery')->willReturn($resultMock);

        $res = MetricsProcessor::processCampaigns($metrics, $this->entityManager);
        
        $this->assertArrayHasKey('c789', $res['map']);
        $this->assertEquals(789, $res['map']['c789']);
    }

    public function testInjectVirtualDailyMetrics(): void
    {
        $metricDate = '2023-01-02';
        $metric = (object)[
            'channel' => 1,
            'name' => 'clicks',
            'period' => \Enums\Period::Lifetime->value,
            'metricDate' => $metricDate,
            'value' => 100,
            'dimensions' => [],
            'query' => null,
            'countryCode' => null,
            'deviceType' => null,
        ];
        $metrics = new ArrayCollection([$metric]);

        // Signature for the lifetime metric
        $signature = \Classes\KeyGenerator::generateMetricConfigKey(
            channel: 1,
            name: 'clicks',
            period: \Enums\Period::Lifetime->value
        );

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchAllAssociative')->willReturn([
            [
                'config_signature' => $signature,
                'metric_date' => '2023-01-01',
                'value' => 80
            ]
        ]);
        
        $this->connection->method('executeQuery')->willReturn($resultMock);

        MetricsProcessor::injectVirtualDailyMetrics($metrics, $this->entityManager);
        
        $this->assertCount(2, $metrics);
        $daily = $metrics->get(1);
        $this->assertEquals('clicks_daily', $daily->name);
        $this->assertEquals(\Enums\Period::Daily->value, $daily->period);
        $this->assertEquals(20, $daily->value); // 100 - 80
    }
}
