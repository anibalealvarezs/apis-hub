<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use Anibalealvarezs\ApiDriverCore\Interfaces\LiveWindowProviderInterface;
use Controllers\ChanneledCrudController;
use Entities\Analytics\Channel as ChannelEntity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Response;

class MockLiveWindowDriver implements LiveWindowProviderInterface
{
    public static function supportsLiveWindow(string $metric): bool
    {
        return in_array($metric, ['reach', 'activeUsers', 'newUsers', 'new_users', 'totalUsers', 'total_users'], true);
    }

    public function fetchLiveWindow(
        array $config,
        string $platformId,
        array $metrics,
        string $startDate,
        string $endDate,
        array $dimensions = [],
        array $filters = []
    ): array {
        return [
            [
                'reach' => 1250.0,
                'activeUsers' => 1250.0,
                'country' => 'US',
            ],
            [
                'reach' => 750.0,
                'activeUsers' => 750.0,
                'country' => 'CA',
            ],
        ];
    }
}

class TestableLiveWindowChanneledCrudController extends ChanneledCrudController
{
    public ?object $mockRepository = null;
    public ?LiveWindowProviderInterface $mockDriver = null;

    public function __construct()
    {
        // Bypass parent constructor which expects container / doctrine
    }

    public function getRepository(string $entity, string $configKey = 'channeled_class'): object
    {
        return $this->mockRepository;
    }

    protected function resolveChannelDriver(string $channelKey, array $config = []): mixed
    {
        return $this->mockDriver ?? parent::resolveChannelDriver($channelKey, $config);
    }

    public function invokeTryResolveLiveWindowExecution(
        string $channelKey,
        string $entity,
        array $aggregations,
        array $groupBy,
        mixed $filters,
        ?string $startDate,
        ?string $endDate
    ): ?array {
        return $this->tryResolveLiveWindowExecution(
            channelKey: $channelKey,
            entity: $entity,
            aggregations: $aggregations,
            groupBy: $groupBy,
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate
        );
    }
}

final class LiveWindowAggregationTest extends TestCase
{
    private TestableLiveWindowChanneledCrudController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new TestableLiveWindowChanneledCrudController();
        $this->controller->mockDriver = new MockLiveWindowDriver();
    }

    public function testTryResolveLiveWindowReturnsNullWhenNoDatesProvided(): void
    {
        $result = $this->controller->invokeTryResolveLiveWindowExecution(
            channelKey: 'google_analytics',
            entity: 'metric',
            aggregations: ['reach' => 'sum'],
            groupBy: [],
            filters: ['channeledAccount' => '123456'],
            startDate: null,
            endDate: null
        );

        $this->assertNull($result);
    }

    public function testTryResolveLiveWindowReturnsNullForAdditiveOnlyMetrics(): void
    {
        $result = $this->controller->invokeTryResolveLiveWindowExecution(
            channelKey: 'google_analytics',
            entity: 'metric',
            aggregations: ['sessions' => 'sum', 'pageviews' => 'sum'],
            groupBy: [],
            filters: ['channeledAccount' => '123456'],
            startDate: '2026-01-01',
            endDate: '2026-01-31'
        );

        $this->assertNull($result);
    }

    public function testTryResolveLiveWindowReturnsNullWhenNoAccountFilter(): void
    {
        $result = $this->controller->invokeTryResolveLiveWindowExecution(
            channelKey: 'google_analytics',
            entity: 'metric',
            aggregations: ['reach' => 'sum'],
            groupBy: [],
            filters: [],
            startDate: '2026-01-01',
            endDate: '2026-01-31'
        );

        $this->assertNull($result);
    }

    public function testTryResolveLiveWindowExecutesPureNonAdditiveQuery(): void
    {
        $result = $this->controller->invokeTryResolveLiveWindowExecution(
            channelKey: 'google_analytics',
            entity: 'metric',
            aggregations: ['reach' => 'sum'],
            groupBy: ['country'],
            filters: ['channeledAccount' => 'properties/987654'],
            startDate: '2026-01-01',
            endDate: '2026-01-31'
        );

        $this->assertNotNull($result);
        $this->assertSame('live_window', $result['meta']['engine']);
        $this->assertSame('google_analytics', $result['meta']['provider']);
        $this->assertTrue($result['meta']['resolved_live']);
        $this->assertCount(2, $result['data']);
        $this->assertEquals(1250.0, $result['data'][0]['reach']);
        $this->assertEquals('US', $result['data'][0]['country']);
    }

    public function testTryResolveLiveWindowExecutesHybridQueryAndMergesSQLWithLiveApi(): void
    {
        $mockRepo = $this->createMock(\stdClass::class);
        $mockRepo = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['aggregate'])
            ->getMock();

        $mockRepo->expects($this->once())
            ->method('aggregate')
            ->willReturn([
                ['country' => 'US', 'sessions' => 5000],
                ['country' => 'CA', 'sessions' => 2000],
            ]);

        $this->controller->mockRepository = $mockRepo;

        $result = $this->controller->invokeTryResolveLiveWindowExecution(
            channelKey: 'google_analytics',
            entity: 'metric',
            aggregations: ['sessions' => 'sum', 'reach' => 'sum'],
            groupBy: ['country'],
            filters: ['channeledAccount' => 'properties/987654'],
            startDate: '2026-01-01',
            endDate: '2026-01-31'
        );

        $this->assertNotNull($result);
        $this->assertSame('hybrid_live_window', $result['meta']['engine']);
        $this->assertTrue($result['meta']['resolved_live']);
        $this->assertCount(2, $result['data']);

        // Assert merged data: sessions from SQL + reach from Live API
        $this->assertEquals('US', $result['data'][0]['country']);
        $this->assertEquals(5000, $result['data'][0]['sessions']);
        $this->assertEquals(1250.0, $result['data'][0]['reach']);

        $this->assertEquals('CA', $result['data'][1]['country']);
        $this->assertEquals(2000, $result['data'][1]['sessions']);
        $this->assertEquals(750.0, $result['data'][1]['reach']);
    }

    public function testTryResolveLiveWindowExecutesHybridSummaryCardMerge(): void
    {
        $singleDriver = new class implements LiveWindowProviderInterface {
            public static function supportsLiveWindow(string $metric): bool { return true; }
            public function fetchLiveWindow(array $config, string $platformId, array $metrics, string $startDate, string $endDate, array $dimensions = [], array $filters = []): array {
                return [['reach' => 3400.0]];
            }
        };
        $this->controller->mockDriver = $singleDriver;

        $mockRepo = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['aggregate'])
            ->getMock();

        $mockRepo->expects($this->once())
            ->method('aggregate')
            ->willReturn([['sessions' => 12000]]);

        $this->controller->mockRepository = $mockRepo;

        $result = $this->controller->invokeTryResolveLiveWindowExecution(
            channelKey: 'google_analytics',
            entity: 'metric',
            aggregations: ['sessions' => 'sum', 'reach' => 'sum'],
            groupBy: [],
            filters: ['channeledAccount' => 'properties/987654'],
            startDate: '2026-01-01',
            endDate: '2026-01-31'
        );

        $this->assertNotNull($result);
        $this->assertSame('hybrid_live_window', $result['meta']['engine']);
        $this->assertCount(1, $result['data']);
        $this->assertEquals(12000, $result['data'][0]['sessions']);
        $this->assertEquals(3400.0, $result['data'][0]['reach']);
    }
}
