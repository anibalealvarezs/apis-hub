<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation\Stages;

use Doctrine\DBAL\Query\QueryBuilder;
use Services\Aggregation\AggregationPlan;
use Services\Aggregation\LegacyAggregateExecutionContext;
use Services\Aggregation\Stages\LegacyAggregateScopeStage;
use Tests\Unit\BaseUnitTestCase;

final class LegacyAggregateScopeStageSmokeTest extends BaseUnitTestCase
{
    public function testAppliesChanneledMetricScopeJoins(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $joinCalls = 0;
        $qb->expects($this->exactly(2))
            ->method('join')
            ->willReturnCallback(function (string $fromAlias, string $joinTable, string $joinAlias, string $condition) use (&$joinCalls, $qb): QueryBuilder {
                $expected = [
                    ['e', 'metrics', 'm', 'e.metric_id = m.id'],
                    ['m', 'metric_configs', 'mc', 'm.metric_config_id = mc.id'],
                ];

                $this->assertSame($expected[$joinCalls], [$fromAlias, $joinTable, $joinAlias, $condition]);
                $joinCalls++;

                return $qb;
            });

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
            entityName: 'Entities\\Analytics\\Channeled\\ChanneledMetric',
            isMetric: false,
            isPostgres: true,
        );

        $activeAggregateJoins = [];

        (new LegacyAggregateScopeStage())->apply(
            context: $context,
            isChanneledMetric: true,
            activeAggregateJoins: $activeAggregateJoins,
        );

        $this->assertArrayHasKey('m', $activeAggregateJoins);
        $this->assertArrayHasKey('mc', $activeAggregateJoins);
        $this->assertTrue($activeAggregateJoins['m']);
        $this->assertTrue($activeAggregateJoins['mc']);
    }

    public function testAppliesMetricScopeJoin(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects($this->once())
            ->method('join')
            ->with('e', 'metric_configs', 'mc', 'e.metric_config_id = mc.id')
            ->willReturnSelf();

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

        $activeAggregateJoins = [];

        (new LegacyAggregateScopeStage())->apply(
            context: $context,
            isChanneledMetric: false,
            activeAggregateJoins: $activeAggregateJoins,
        );

        $this->assertArrayHasKey('mc', $activeAggregateJoins);
        $this->assertTrue($activeAggregateJoins['mc']);
    }
}

