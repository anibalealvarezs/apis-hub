<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation\Stages;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Services\Aggregation\AggregationPlan;
use Services\Aggregation\LegacyAggregateExecutionContext;
use Services\Aggregation\Stages\LegacyAggregateRelationContextStage;
use Tests\Unit\BaseUnitTestCase;

final class LegacyAggregateRelationContextStageSmokeTest extends BaseUnitTestCase
{
    public function testBuildsExpectedContextShape(): void
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

        $activeAggregateJoins = ['mc' => true];
        $relationMap = [
            'page' => ['table' => 'pages', 'fk' => 'page_id', 'field' => 'url', 'alias' => 'rpa'],
        ];

        $relationContext = (new LegacyAggregateRelationContextStage())->apply(
            context: $context,
            qb: $qb,
            relationMap: $relationMap,
            isChanneledMetric: false,
            activeAggregateJoins: $activeAggregateJoins,
        );

        $this->assertArrayHasKey('standardRelations', $relationContext);
        $this->assertArrayHasKey('dateFields', $relationContext);
        $this->assertArrayHasKey('rootAlias', $relationContext);
        $this->assertArrayHasKey('safeLeftJoin', $relationContext);
        $this->assertArrayHasKey('joinRelation', $relationContext);
        $this->assertSame('mc', $relationContext['rootAlias']);
        $this->assertContains('page', $relationContext['standardRelations']);
        $this->assertContains('daily', $relationContext['dateFields']);
        $this->assertIsCallable($relationContext['safeLeftJoin']);
        $this->assertIsCallable($relationContext['joinRelation']);
    }

    public function testJoinRelationTracksActivatedAliases(): void
    {
        $connection = $this->createMock(Connection::class);
        $qb = new QueryBuilder($connection);
        $qb->from('metrics', 'e')
            ->join('e', 'metric_configs', 'mc', 'e.metric_config_id = mc.id');

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

        $activeAggregateJoins = ['mc' => true];
        $relationMap = [
            'page' => ['table' => 'pages', 'fk' => 'page_id', 'field' => 'url', 'alias' => 'rpa'],
        ];

        $relationContext = (new LegacyAggregateRelationContextStage())->apply(
            context: $context,
            qb: $qb,
            relationMap: $relationMap,
            isChanneledMetric: false,
            activeAggregateJoins: $activeAggregateJoins,
        );

        $joinRelation = $relationContext['joinRelation'];
        $joinRelation('page');

        $this->assertArrayHasKey('rpa', $activeAggregateJoins);
        $this->assertTrue($activeAggregateJoins['rpa']);
    }
}

