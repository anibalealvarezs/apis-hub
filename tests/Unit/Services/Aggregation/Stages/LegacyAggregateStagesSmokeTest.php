<?php

    declare(strict_types=1);

    namespace Tests\Unit\Services\Aggregation\Stages;

    use Doctrine\DBAL\Connection;
    use Doctrine\DBAL\Query\QueryBuilder;
    use Traits\AggregationPlan;
    use Traits\LegacyAggregateExecutionContext;
    use Traits\Stages\LegacyAggregateFilterStage;
    use Traits\Stages\LegacyAggregateGroupingStage;
    use Tests\Unit\BaseUnitTestCase;

    final class LegacyAggregateStagesSmokeTest extends BaseUnitTestCase
    {
        public function testGroupingStageReturnsWhenJoinCallbacksAreMissing(): void
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
                groupBy: ['daily'],
                orderBy: null,
                orderDir: null,
                entityName: 'Entities\\Analytics\\Metric',
                isMetric: true,
                isPostgres: true,
            );

            (new LegacyAggregateGroupingStage())->apply(
                context: $context,
                relationMap: [],
                isChanneledMetric: false,
                mapFieldToSql: static fn(string $expr): string => $expr,
                hasEntityField: static fn(string $field): bool => false,
            );

            $this->assertTrue(true);
        }

        public function testFilterStageReturnsWhenNoFiltersAreProvided(): void
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

            (new LegacyAggregateFilterStage())->apply(
                context: $context,
                relationMap: [],
                isChanneledMetric: false,
                mapFieldToSql: static fn(string $expr): string => $expr,
                resolveFilterCondition: static fn(mixed $rawValue): array => ['operator' => 'eq', 'value' => $rawValue],
                hasEntityField: static fn(string $field): bool => false,
            );

            $this->assertTrue(true);
        }
    }

