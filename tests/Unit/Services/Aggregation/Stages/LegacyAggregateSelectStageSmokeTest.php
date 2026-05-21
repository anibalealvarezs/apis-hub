<?php

    declare(strict_types=1);

    namespace Tests\Unit\Services\Aggregation\Stages;

    use Doctrine\DBAL\Query\QueryBuilder;
    use InvalidArgumentException;
    use Services\Aggregation\AggregationPlan;
    use Services\Aggregation\LegacyAggregateExecutionContext;
    use Services\Aggregation\Stages\LegacyAggregateSelectStage;
    use Tests\Unit\BaseUnitTestCase;

    final class LegacyAggregateSelectStageSmokeTest extends BaseUnitTestCase
    {
        public function testAppliesSelectsWithoutWeightedStrategiesWhenReducerStageIsMissing(): void
        {
            $qb = $this->createMock(QueryBuilder::class);
            $qb->expects($this->once())
                ->method('addSelect')
                ->with('SUM(e.clicks) AS clicks');

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

            $resolveCalled = false;

            $needsImpressionsJoin = (new LegacyAggregateSelectStage())->apply(
                context: $context,
                isChanneledMetric: false,
                mapFieldToSql: static fn(string $expr, bool $isAggregate = false): string => 'SUM(e.'.$expr.')',
                resolveWeightedAggregationStrategies: function (array $aggregations) use (&$resolveCalled): array {
                    $resolveCalled = true;

                    return ['clicks' => ['prefix' => 'wm_0']];
                },
            );

            $this->assertFalse($resolveCalled);
            $this->assertFalse($needsImpressionsJoin);
        }

        public function testMarksNeedsImpressionsJoinWhenWeightedExpressionsExist(): void
        {
            $qb = $this->createMock(QueryBuilder::class);
            $qb->expects($this->once())
                ->method('addSelect')
                ->with("SUM(CASE WHEN mc.name = 'ctr' THEN m.value ELSE 0 END) AS ctr");

            $plan = new AggregationPlan(
                aggregations: ['ctr' => 'ctr'],
                stages: [
                    'reducers' => [
                        'weighted_metric_expressions' => ['ctr' => 'ctr'],
                    ],
                ],
            );

            $context = new LegacyAggregateExecutionContext(
                queryBuilder: $qb,
                plan: $plan,
                aggregations: ['ctr' => 'ctr'],
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

            $needsImpressionsJoin = (new LegacyAggregateSelectStage())->apply(
                context: $context,
                isChanneledMetric: true,
                mapFieldToSql: static fn(string $expr, bool $isAggregate = false): string => 'SUM(CASE WHEN mc.name = \''.$expr.'\' THEN m.value ELSE 0 END)',
                resolveWeightedAggregationStrategies: static fn(array $aggregations): array => ['ctr' => ['prefix' => 'wm_0']],
            );

            $this->assertTrue($needsImpressionsJoin);
        }

        public function testThrowsWhenUnsafeChanneledValueAggregationIsRequested(): void
        {
            $qb = $this->createMock(QueryBuilder::class);

            $context = new LegacyAggregateExecutionContext(
                queryBuilder: $qb,
                plan: new AggregationPlan(aggregations: ['bad' => 'value']),
                aggregations: ['bad' => 'value'],
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

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage("Direct aggregation of 'value' field is restricted for ChanneledMetrics");

            (new LegacyAggregateSelectStage())->apply(
                context: $context,
                isChanneledMetric: true,
                mapFieldToSql: static fn(string $expr, bool $isAggregate = false): string => 'SUM(m.value)',
                resolveWeightedAggregationStrategies: static fn(array $aggregations): array => [],
            );
        }
    }

