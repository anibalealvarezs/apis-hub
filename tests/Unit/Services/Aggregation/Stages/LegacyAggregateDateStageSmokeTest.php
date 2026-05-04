<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation\Stages;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use Services\Aggregation\AggregationPlan;
use Services\Aggregation\LegacyAggregateExecutionContext;
use Services\Aggregation\Stages\LegacyAggregateDateStage;
use Tests\Unit\BaseUnitTestCase;

final class LegacyAggregateDateStageSmokeTest extends BaseUnitTestCase
{
    public function testReturnsWhenNoDateConstraintsAndNoLatestSnapshot(): void
    {
        $connection = $this->createMock(Connection::class);
        $qb = new QueryBuilder($connection);

        $context = new LegacyAggregateExecutionContext(
            queryBuilder: $qb,
            plan: new AggregationPlan(aggregations: ['clicks' => 'clicks']),
            aggregations: ['clicks' => 'clicks'],
            filters: null,
            startDate: null,
            endDate: null,
            groupBy: [],
            orderBy: null,
            orderDir: null,
            entityName: 'Entities\\Analytics\\Metric',
            isMetric: true,
            isPostgres: true,
        );

        (new LegacyAggregateDateStage())->apply(
            context: $context,
            isChanneledMetric: false,
            aggregateUseSnapshotDelta: false,
            aggregateSnapshotFallbackMode: 'resilient',
            mapFieldToSql: static fn(string $expr): string => $expr,
            hasEntityField: static fn(string $field): bool => false,
        );

        $this->assertTrue(true);
    }

    public function testThrowsWhenLatestSnapshotAndSnapshotDeltaAreCombined(): void
    {
        $connection = $this->createMock(Connection::class);
        $qb = new QueryBuilder($connection);

        $context = new LegacyAggregateExecutionContext(
            queryBuilder: $qb,
            plan: new AggregationPlan(aggregations: ['clicks' => 'clicks']),
            aggregations: ['clicks' => 'clicks'],
            filters: (object)['latest_snapshot' => true],
            startDate: '2026-04-01',
            endDate: '2026-04-30',
            groupBy: [],
            orderBy: null,
            orderDir: null,
            entityName: 'Entities\\Analytics\\Channeled\\ChanneledMetric',
            isMetric: false,
            isPostgres: true,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('latest_snapshot and snapshot_delta cannot be used together.');

        (new LegacyAggregateDateStage())->apply(
            context: $context,
            isChanneledMetric: true,
            aggregateUseSnapshotDelta: true,
            aggregateSnapshotFallbackMode: 'resilient',
            mapFieldToSql: static fn(string $expr): string => $expr,
            hasEntityField: static fn(string $field): bool => false,
        );
    }
}

