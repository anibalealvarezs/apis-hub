<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation\Stages;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Services\Aggregation\AggregationPlan;
use Services\Aggregation\LegacyAggregateExecutionContext;
use Services\Aggregation\Stages\LegacyAggregateFinalizeStage;
use Tests\Unit\BaseUnitTestCase;

final class LegacyAggregateFinalizeStageSmokeTest extends BaseUnitTestCase
{
    public function testRunsSnapshotExtractionWithoutTemporalFill(): void
    {
        $result = $this->createMock(Result::class);
        $result->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['clicks' => 10],
            ]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects($this->once())
            ->method('executeQuery')
            ->willReturn($result);

        $plan = new AggregationPlan(
            aggregations: ['clicks' => 'clicks'],
            stages: [
                'grouping' => [
                    'group_by' => [],
                    'has_temporal_grouping' => false,
                ],
            ],
        );

        $context = new LegacyAggregateExecutionContext(
            queryBuilder: $qb,
            plan: $plan,
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

        $extractCalled = false;
        $fillCalled = false;

        $rows = (new LegacyAggregateFinalizeStage())->apply(
            $context,
            function (array &$results, ?string $startDate, ?string $endDate) use (&$extractCalled): void {
                $extractCalled = true;
                $this->assertSame([['clicks' => 10]], $results);
                $this->assertNull($startDate);
                $this->assertNull($endDate);
            },
            function (
                array $results,
                string $temporalField,
                string $temporalType,
                string $startDate,
                string $endDate,
                array $aggregations,
                array $groupBy
            ) use (&$fillCalled): array {
                $fillCalled = true;

                return $results;
            },
        );

        $this->assertTrue($extractCalled);
        $this->assertFalse($fillCalled);
        $this->assertSame([['clicks' => 10]], $rows);
    }

    public function testFillsTemporalGapsWhenTemporalGroupingIsPlanned(): void
    {
        $result = $this->createMock(Result::class);
        $result->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['daily' => '2026-04-01', 'clicks' => 10],
            ]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects($this->once())
            ->method('executeQuery')
            ->willReturn($result);

        $plan = new AggregationPlan(
            aggregations: ['clicks' => 'clicks'],
            groupBy: ['daily'],
            stages: [
                'grouping' => [
                    'group_by' => ['daily'],
                    'has_temporal_grouping' => true,
                ],
            ],
        );

        $context = new LegacyAggregateExecutionContext(
            queryBuilder: $qb,
            plan: $plan,
            aggregations: ['clicks' => 'clicks'],
            filters: null,
            startDate: '2026-04-01',
            endDate: '2026-04-03',
            groupBy: ['daily'],
            orderBy: null,
            orderDir: null,
            entityName: 'Entities\\Analytics\\Metric',
            isMetric: true,
            isPostgres: true,
        );

        $fillCalled = false;

        $rows = (new LegacyAggregateFinalizeStage())->apply(
            $context,
            static function (array &$results, ?string $startDate, ?string $endDate): void {
            },
            function (
                array $results,
                string $temporalField,
                string $temporalType,
                string $startDate,
                string $endDate,
                array $aggregations,
                array $groupBy
            ) use (&$fillCalled): array {
                $fillCalled = true;
                $this->assertSame('daily', $temporalField);
                $this->assertSame('daily', $temporalType);
                $this->assertSame('2026-04-01', $startDate);
                $this->assertSame('2026-04-03', $endDate);
                $this->assertSame(['clicks' => 'clicks'], $aggregations);
                $this->assertSame(['daily'], $groupBy);

                $results[] = ['daily' => '2026-04-02', 'clicks' => 0];

                return $results;
            },
        );

        $this->assertTrue($fillCalled);
        $this->assertCount(2, $rows);
    }
}

