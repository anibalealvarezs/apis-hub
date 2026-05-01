<?php

namespace Tests\Unit\Repositories;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Entities\Analytics\Metric;
use PHPUnit\Framework\TestCase;
use Repositories\MetricRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Helpers\Helpers;

class AggregateIntegrityTest extends TestCase
{
    private $entityManager;
    private $connection;
    private $repository;
    private $queryBuilder;

    protected function setUp(): void
    {
        // 1. Force isPostgres = true via Environment Variable
        putenv("DB_DRIVER=pdo_pgsql");
        
        // CRITICAL: Reset Helpers to pick up the new DB_DRIVER
        Helpers::resetConfigs();

        $this->connection = $this->createMock(Connection::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        
        $this->entityManager->method('getConnection')->willReturn($this->connection);
        $this->connection->method('createQueryBuilder')->willReturn($this->queryBuilder);
        
        // Mock QueryBuilder fluent interface
        $fluentMethods = [
            'select', 'addSelect', 'from', 'where', 'andWhere', 'orWhere', 
            'groupBy', 'addGroupBy', 'orderBy', 'addOrderBy', 'setParameter', 
            'setParameters', 'join', 'innerJoin', 'leftJoin', 'rightJoin', 
            'setFirstResult', 'setMaxResults'
        ];

        foreach ($fluentMethods as $method) {
            $this->queryBuilder->method($method)->willReturn($this->queryBuilder);
        }

        $this->queryBuilder->method('getQueryPart')->willReturn([]);

        $metadata = new ClassMetadata(Metric::class);
        $metadata->setPrimaryTable(['name' => 'metrics']);
        $this->repository = new MetricRepository($this->entityManager, $metadata);
    }

    protected function tearDown(): void
    {
        putenv("DB_DRIVER"); // Unset
        Helpers::resetConfigs();
    }

    /**
     * Test that position aggregation generates the correct weighted formula.
     */
    public function testPositionAggregationSqlGeneration(): void
    {
        // 1. Expectation: Capture the SQL
        $capturedSql = '';
        $this->connection->method('fetchAllAssociative')
            ->will($this->returnCallback(function($sql, $params = []) use (&$capturedSql) {
                $capturedSql = $sql;
                return [['position' => 99.0, 'dimensions.country' => 'Spain']];
            }));

        // 2. Run Aggregation
        $results = $this->repository->aggregate(
            aggregations: ['position' => 'position'],
            groupBy: ['dimensions.country'],
            startDate: '2026-05-01',
            endDate: '2026-05-01',
            filters: (object) ['country' => 117, 'channel' => 1]
        );

        // 3. Assertions
        $this->assertNotEmpty($capturedSql, "SQL should have been captured from optimized path.");
        $this->assertCount(1, $results);
        $this->assertEquals(99.0, $results[0]['position']);

        // IMPORTANT: Verify the weighted formula exists in the SQL
        $this->assertStringContainsString('SUM(COALESCE(p.wm_0_metric, 0) * COALESCE(p.wm_0_weight, 0)) / NULLIF(SUM(COALESCE(p.wm_0_weight, 0)), 0)', $capturedSql);
        
        // Verify filters are in the CTE
        $this->assertStringContainsString('mc.country_id = :countryId', $capturedSql);
        $this->assertStringContainsString('mc.channel = :channel', $capturedSql);
    }

    /**
     * Test that the optimized path is triggered for supported dimension patterns.
     */
    public function testOptimizedPathTriggering(): void
    {
        $capturedSql = '';
        $this->connection->method('fetchAllAssociative')
            ->will($this->returnCallback(function($sql, $params = []) use (&$capturedSql) {
                $capturedSql = $sql;
                return [['position' => 99.0, 'dimensions.country' => 'Spain', 'dimensions.device' => 'mobile']];
            }));

        // Pattern: [country, device] is supported by optimized path
        $this->repository->aggregate(
            aggregations: ['position' => 'position'],
            groupBy: ['dimensions.country', 'dimensions.device'],
            startDate: '2026-05-01',
            endDate: '2026-05-01',
            filters: (object) ['channel' => 1]
        );

        // Verify it used the WITH base AS (...) structure
        $this->assertNotEmpty($capturedSql, "SQL should have been captured from optimized path.");
        $this->assertStringContainsString('WITH base AS', $capturedSql);
        $this->assertStringContainsString('GROUP BY b.metric_date', $capturedSql);
        $this->assertStringContainsString('b.country_id', $capturedSql);
        $this->assertStringContainsString('b.device_id', $capturedSql);
    }
}
