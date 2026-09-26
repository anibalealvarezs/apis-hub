<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Anibalealvarezs\ApiDriverCore\Interfaces\PreAggregationProviderInterface;
use DateTime;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Services\Aggregation\AgnosticPreAggregationEngine;

final class AgnosticPreAggregationEngineTest extends TestCase
{
    private AgnosticPreAggregationEngine $engine;
    private Connection $mockConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockConnection = $this->createMock(Connection::class);
        $this->engine = new AgnosticPreAggregationEngine($this->mockConnection);
    }

    public function testComputesMetricsFromAtomicPayloadsUsingDeclaredReducers(): void
    {
        $atomicRecords = [
            [
                'id' => 1,
                'channel' => 'mock_channel',
                'action' => 'open',
                'is_proxy' => false,
                'email_id' => 'hash_a',
            ],
            [
                'id' => 2,
                'channel' => 'mock_channel',
                'action' => 'open',
                'is_proxy' => true,
                'email_id' => 'hash_b',
            ],
            [
                'id' => 3,
                'channel' => 'mock_channel',
                'action' => 'click',
                'email_id' => 'hash_a',
            ],
            [
                'id' => 4,
                'channel' => 'mock_channel',
                'action' => 'click',
                'email_id' => 'hash_a', // Duplicate clicker
            ],
            [
                'id' => 5,
                'channel' => 'mock_channel',
                'action' => 'click',
                'email_id' => 'hash_c',
            ],
        ];

        $metricDefinitions = [
            'opens_standard' => [
                'condition' => ['action' => 'open', 'is_proxy' => false],
                'reducer' => 'count',
            ],
            'opens_proxy' => [
                'condition' => ['action' => 'open', 'is_proxy' => true],
                'reducer' => 'count',
            ],
            'clicks_total' => [
                'condition' => ['action' => 'click'],
                'reducer' => 'count',
            ],
            'clicks_unique' => [
                'condition' => ['action' => 'click'],
                'field' => 'email_id',
                'reducer' => 'count_distinct',
            ],
        ];

        $results = $this->engine->computeMetricsFromPayloads($atomicRecords, $metricDefinitions);

        $this->assertSame(1, $results['opens_standard']);
        $this->assertSame(1, $results['opens_proxy']);
        $this->assertSame(3, $results['clicks_total']);
        $this->assertSame(2, $results['clicks_unique']); // hash_a and hash_c
    }

    public function testComputesMonetarySumReduction(): void
    {
        $orders = [
            ['id' => 1, 'platform_id' => 'ord_1', 'total_amount' => 100.50],
            ['id' => 2, 'platform_id' => 'ord_2', 'total_amount' => 49.50],
            ['id' => 3, 'platform_id' => 'ord_3', 'total_amount' => 50.00],
        ];

        $definitions = [
            'orders_count' => [
                'field' => 'platform_id',
                'reducer' => 'count_distinct',
            ],
            'revenue' => [
                'field' => 'total_amount',
                'reducer' => 'sum',
            ],
        ];

        $results = $this->engine->computeMetricsFromPayloads($orders, $definitions);

        $this->assertSame(3, $results['orders_count']);
        $this->assertEqualsWithDelta(200.00, $results['revenue'], 0.001);
    }

    public function testAttributionWindowMatchingWithConformedIdentityHashes(): void
    {
        $event = [
            'identity_hash' => md5('shopper@example.com'),
            'timestamp' => '2026-06-01 10:00:00',
        ];

        // Valid order within 30-day window
        $orderWithinWindow = [
            'identity_hash' => md5('shopper@example.com'),
            'timestamp' => '2026-06-05 15:30:00',
        ];

        $this->assertTrue(
            $this->engine->matchesAttributionWindow($event, $orderWithinWindow, 30),
            'Order 4 days later with matching identity hash must be attributed.'
        );

        // Order outside 30-day window
        $orderOutsideWindow = [
            'identity_hash' => md5('shopper@example.com'),
            'timestamp' => '2026-07-15 10:00:00',
        ];

        $this->assertFalse(
            $this->engine->matchesAttributionWindow($event, $orderOutsideWindow, 30),
            'Order 44 days later must NOT be attributed under a 30-day window.'
        );

        // Order occurred before the event (causality check)
        $orderBeforeEvent = [
            'identity_hash' => md5('shopper@example.com'),
            'timestamp' => '2026-05-30 09:00:00',
        ];

        $this->assertFalse(
            $this->engine->matchesAttributionWindow($event, $orderBeforeEvent, 30),
            'Order placed BEFORE the engagement event cannot be attributed.'
        );

        // Mismatched identity hash
        $differentCustomerOrder = [
            'identity_hash' => md5('other@example.com'),
            'timestamp' => '2026-06-02 12:00:00',
        ];

        $this->assertFalse(
            $this->engine->matchesAttributionWindow($event, $differentCustomerOrder, 30),
            'Order from different customer hash must NOT match.'
        );
    }

    public function testExecutesDateRangeRollupAgainstDriverContract(): void
    {
        $mockDriver = new class implements PreAggregationProviderInterface {
            public static function getPreAggregationRules(): array
            {
                return [
                    'engagement' => [
                        'source_entity' => 'channeled_events',
                        'scope_field' => 'campaign_id',
                        'metrics' => [
                            'opens_standard' => ['condition' => ['action' => 'open'], 'reducer' => 'count'],
                        ],
                    ],
                ];
            }

            public static function getDefaultAttributionWindowDays(): int
            {
                return 14;
            }
        };

        $this->mockConnection->expects($this->exactly(3))
            ->method('fetchAllAssociative')
            ->willReturn([]); // No records for clean iteration check

        $result = $this->engine->preAggregateDateRange(
            driverClass: get_class($mockDriver),
            channel: 'mock_channel',
            startDate: new DateTime('2026-06-01'),
            endDate: new DateTime('2026-06-03')
        );

        $this->assertSame(3, $result['processed_days']);
        $this->assertSame(0, $result['metrics_emitted']);
        $this->assertSame(0, $result['records_evaluated']);
    }
}
