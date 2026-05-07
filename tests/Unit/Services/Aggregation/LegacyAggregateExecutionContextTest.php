<?php

    declare(strict_types=1);

    namespace Tests\Unit\Services\Aggregation;

    use Doctrine\DBAL\Connection;
    use Doctrine\DBAL\Query\QueryBuilder;
    use Traits\AggregationPlan;
    use Traits\LegacyAggregateExecutionContext;
    use Tests\Unit\BaseUnitTestCase;

    final class LegacyAggregateExecutionContextTest extends BaseUnitTestCase
    {
        public function testStoresExecutionMetadataAndSupportsRelationContextForking(): void
        {
            $connection = $this->createMock(Connection::class);
            $qb = new QueryBuilder($connection);
            $plan = new AggregationPlan(
                aggregations: ['clicks' => 'clicks'],
                groupBy: ['daily'],
            );

            $context = new LegacyAggregateExecutionContext(
                queryBuilder: $qb,
                plan: $plan,
                aggregations: ['clicks' => 'clicks'],
                filters: (object)['page' => 1],
                startDate: '2026-04-01',
                endDate: '2026-04-30',
                groupBy: ['daily'],
                orderBy: 'clicks',
                orderDir: 'DESC',
                entityName: 'Entities\\Analytics\\Channeled\\ChanneledMetric',
                isMetric: false,
                isPostgres: true,
            );

            $updated = $context->withRelationContext([
                'rootAlias'         => 'mc',
                'standardRelations' => ['page'],
            ]);

            $this->assertSame($qb, $context->getQueryBuilder());
            $this->assertSame($plan, $context->getPlan());
            $this->assertSame(['clicks' => 'clicks'], $context->getAggregations());
            $this->assertSame(['daily'], $context->getGroupBy());
            $this->assertSame('clicks', $context->getOrderBy());
            $this->assertSame('DESC', $context->getOrderDir());
            $this->assertTrue($context->isPostgres());
            $this->assertFalse($context->isMetric());
            $this->assertSame([], $context->getRelationContext());
            $this->assertNotSame($context, $updated);
            $this->assertSame('mc', $updated->getRelationContextValue('rootAlias'));
            $this->assertSame(['page'], $updated->getRelationContextValue('standardRelations'));
            $this->assertNull($updated->getRelationContextValue('missing_key'));
        }
    }

