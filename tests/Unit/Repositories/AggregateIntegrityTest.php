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
        private MetricRepository $repository;
        private $queryBuilder;
        private ?string $telemetryPath = null;

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

            $metadata = new ClassMetadata(Metric::class);
            $metadata->setPrimaryTable(['name' => 'metrics']);
            $this->repository = new MetricRepository($this->entityManager, $metadata);
        }

        protected function tearDown(): void
        {
            putenv("DB_DRIVER"); // Unset
            putenv("AGGREGATION_TELEMETRY_PATH");
            if ($this->telemetryPath !== null) {
                @unlink($this->telemetryPath);
            }
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
                ->will($this->returnCallback(function ($sql, $params = []) use (&$capturedSql) {
                    $capturedSql = $sql;

                    return [['position' => 99.0, 'dimensions.country' => 'Spain']];
                }));

            // 2. Run Aggregation
            $results = $this->repository->aggregate(
                aggregations: ['position' => 'position'],
                groupBy: ['dimensions.country'],
                startDate: '2026-05-01',
                endDate: '2026-05-01',
                filters: (object)['country' => 117, 'channel' => 1]
            );

            // 3. Assertions
            $this->assertNotEmpty($capturedSql, "SQL should have been captured from optimized path.");
            $this->assertCount(1, $results);
            $this->assertEquals(99.0, $results[0]['position']);

            // IMPORTANT: Verify the weighted formula path exists in the SQL
            $this->assertStringContainsString('SUM(COALESCE(b.wm_0_metric, 0) * COALESCE(b.wm_0_weight, 0)) AS wm_0_weighted_sum', $capturedSql);
            $this->assertStringContainsString('SUM(p.wm_0_weighted_sum) / NULLIF(SUM(p.wm_0_total_weight), 0) AS wm_0_value', $capturedSql);

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
                ->will($this->returnCallback(function ($sql, $params = []) use (&$capturedSql) {
                    $capturedSql = $sql;

                    return [['position' => 99.0, 'dimensions.country' => 'Spain', 'dimensions.device' => 'mobile']];
                }));

            // Pattern: [country, device] is supported by optimized path
            $this->repository->aggregate(
                aggregations: ['position' => 'position'],
                groupBy: ['dimensions.country', 'dimensions.device'],
                startDate: '2026-05-01',
                endDate: '2026-05-01',
                filters: (object)['channel' => 1]
            );

            // Verify it used the current optimized CTE pipeline and dimension joins
            $this->assertNotEmpty($capturedSql, "SQL should have been captured from optimized path.");
            $this->assertStringContainsString('WITH configs AS MATERIALIZED', $capturedSql);
            $this->assertStringContainsString('paired AS (', $capturedSql);
            $this->assertStringContainsString('LEFT JOIN dimension_set_items dsi_country', $capturedSql);
            $this->assertStringContainsString('LEFT JOIN dimension_set_items dsi_device', $capturedSql);
        }

        public function testAggregateWritesTelemetryEventWhenPathIsConfigured(): void
        {
            $this->telemetryPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'aggregate-telemetry-'.bin2hex(random_bytes(6)).'.jsonl';
            putenv('AGGREGATION_TELEMETRY_PATH='.$this->telemetryPath);

            $this->connection->method('fetchAllAssociative')
                ->willReturn([['position' => 99.0, 'dimensions.country' => 'Spain']]);

            $results = $this->repository->aggregate(
                aggregations: ['position' => 'position'],
                groupBy: ['dimensions.country'],
                startDate: '2026-05-01',
                endDate: '2026-05-01',
                filters: (object)['country' => 117, 'channel' => 1]
            );

            $this->assertCount(1, $results);
            $this->assertFileExists($this->telemetryPath);

            $lines = file($this->telemetryPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertIsArray($lines);
            $this->assertCount(1, $lines);

            $event = json_decode((string)$lines[0], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('Entities\Analytics\Metric', $event['entity_name']);
            $this->assertSame('optimized', $event['execution_path']);
            $this->assertSame(1, $event['row_count']);
            $this->assertSame(['channel', 'country'], $event['filter_keys']);
            $this->assertSame(['dimensions.country'], $event['group_by']);
            $this->assertSame(['position'], $event['aggregation_aliases']);
        }
    }
