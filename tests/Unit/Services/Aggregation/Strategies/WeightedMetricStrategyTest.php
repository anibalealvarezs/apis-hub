<?php

    declare(strict_types=1);

    namespace Tests\Unit\Services\Aggregation\Strategies;

    use Doctrine\DBAL\Connection;
    use Repositories\BaseRepository;
    use Services\Aggregation\AggregationPlan;
    use Services\Aggregation\CanonicalMetricSqlResolver;
    use Services\Aggregation\Strategies\WeightedMetricStrategy;
    use Tests\Unit\BaseUnitTestCase;

    final class WeightedMetricStrategyTest extends BaseUnitTestCase
    {
        public function testAppliesChanneledAccountFilterWhenProvided(): void
        {
            $capturedSql = null;
            $capturedParams = [];

            $connection = $this->createMock(Connection::class);
            $connection->expects($this->once())
                ->method('fetchAllAssociative')
                ->willReturnCallback(static function (string $sql, array $params = []) use (&$capturedSql, &$capturedParams): array {
                    $capturedSql = $sql;
                    $capturedParams = $params;

                    return [];
                });

            $repository = $this->createMock(BaseRepository::class);
            $repository->expects($this->once())->method('appendOptimizedStrategyMeta');

            $plan = new AggregationPlan(
                aggregations: [
                    'position' => 'position',
                ],
                groupBy: ['query'],
                filters: (object)[
                    'channel' => 'google_search_console',
                    'channeledAccount' => '336',
                ],
                startDate: '2026-05-06',
                endDate: '2026-06-03',
                context: [
                    'repository' => $repository,
                ],
                stages: [
                    'grouping' => ['normalized_pattern' => 'query'],
                ],
            );

            $strategy = new WeightedMetricStrategy(new CanonicalMetricSqlResolver());
            $rows = $strategy->execute($connection, $plan, true);

            $this->assertIsArray($rows);
            $this->assertNotNull($capturedSql);
            $this->assertStringContainsString('mc.channeled_account_id = :channeledAccountVal', (string)$capturedSql);
            $this->assertArrayHasKey('channeledAccountVal', $capturedParams);
            $this->assertSame('336', (string)$capturedParams['channeledAccountVal']);
        }

        public function testUsesCanonicalResolverForWeightedMetrics(): void
        {
            $capturedSql = null;

            $connection = $this->createMock(Connection::class);
            $connection->expects($this->once())
                ->method('fetchAllAssociative')
                ->willReturnCallback(static function (string $sql, array $params = []) use (&$capturedSql): array {
                    $capturedSql = $sql;

                    return [['daily' => '2026-04-01', 'position' => 1.5]];
                });

            $repository = $this->createMock(BaseRepository::class);
            $repository->expects($this->once())
                ->method('appendOptimizedStrategyMeta')
                ->with($this->callback(static function (array $meta): bool {
                    $resolvedMetrics = $meta['metric_resolution']['resolved_metrics'] ?? null;
                    if (!is_array($resolvedMetrics)) {
                        return false;
                    }

                    $foundPosition = false;
                    foreach ($resolvedMetrics as $resolvedMetric) {
                        if (($resolvedMetric['requested_metric'] ?? null) === 'position') {
                            $foundPosition = true;
                            break;
                        }
                    }

                    return $foundPosition;
                }));

            $plan = new AggregationPlan(
                aggregations: [
                    'position' => 'position',
                ],
                groupBy: ['daily'],
                filters: (object)['channel' => 'google_search_console'],
                startDate: '2026-04-01',
                endDate: '2026-04-30',
                context: [
                    'repository' => $repository,
                ],
                stages: [
                    'grouping' => ['normalized_pattern' => 'daily'],
                ],
            );

            $strategy = new WeightedMetricStrategy(new CanonicalMetricSqlResolver());
            $rows = $strategy->execute($connection, $plan, true);

            $this->assertIsArray($rows);
            $this->assertNotNull($capturedSql);
            $this->assertStringContainsString("'position'", (string)$capturedSql);
            $this->assertStringContainsString("'impressions'", (string)$capturedSql);
            $this->assertStringContainsString("'clicks'", (string)$capturedSql);
        }

        public function testReturnsNullForUnsupportedMetric(): void
        {
            $connection = $this->createMock(Connection::class);
            $connection->expects($this->never())->method('fetchAllAssociative');

            $repository = $this->createMock(BaseRepository::class);
            $repository->expects($this->once())
                ->method('appendOptimizedStrategyMeta')
                ->with($this->callback(static function (array $meta): bool {
                    return ($meta['strategy_fallback_reason'] ?? null) === 'missing_metric_equivalence'
                        && ($meta['metric_resolution']['missing'] ?? false) === true;
                }));

            $plan = new AggregationPlan(
                aggregations: ['mystery' => 'mystery_weighted_metric'],
                groupBy: ['daily'],
                filters: (object)['channel' => 'google_search_console'],
                startDate: '2026-04-01',
                endDate: '2026-04-30',
                context: [
                    'repository' => $repository,
                ],
                stages: [
                    'grouping' => ['normalized_pattern' => 'daily'],
                ],
            );

            // Mock the strategy registry for a mystery metric, or we can just rely on the fallback mechanism.
            // WeightedMetricStrategy requires the metric to exist in MetricAggregationStrategyRegistry to be parsed as weighted.
            // But if it passes it, the resolver will fail and fallback.
            // We'll actually define a mock strategy in the test so it attempts to resolve it:
            \Anibalealvarezs\ApiDriverCore\Classes\MetricAggregationStrategyRegistry::register('mystery_weighted_metric', [
                'method' => \Anibalealvarezs\ApiDriverCore\Classes\MetricAggregationStrategyRegistry::METHOD_WEIGHTED_BY_METRIC,
                'source_metric_names' => ['mystery_weighted_metric'],
                'weight_metric_names' => ['impressions'],
            ]);

            $strategy = new WeightedMetricStrategy(new CanonicalMetricSqlResolver());
            $rows = $strategy->execute($connection, $plan, true);

            $this->assertNull($rows);
        }
    }
