<?php

    declare(strict_types=1);

    namespace Tests\Unit\Services\Aggregation;

    use Doctrine\DBAL\Connection;
    use Repositories\BaseRepository;
    use Services\Aggregation\AggregationPlan;
    use Services\Aggregation\CanonicalMetricSqlResolver;
    use Services\Aggregation\Strategies\MarketingHierarchyStrategy;
    use Tests\Unit\BaseUnitTestCase;

    final class MarketingHierarchyStrategyTest extends BaseUnitTestCase
    {
        public function testUsesCanonicalResolverForLegacyAndCanonicalMetrics(): void
        {
            $capturedSql = null;

            $connection = $this->createMock(Connection::class);
            $connection->expects($this->once())
                ->method('fetchAllAssociative')
                ->willReturnCallback(static function (string $sql, array $params = []) use (&$capturedSql): array {
                    $capturedSql = $sql;

                    return [['daily' => '2026-04-01', 'conversions' => 10]];
                });

            $repository = $this->createMock(BaseRepository::class);
            $repository->expects($this->once())
                ->method('appendOptimizedStrategyMeta')
                ->with($this->callback(static function (array $meta): bool {
                    $resolvedMetrics = $meta['metric_resolution']['resolved_metrics'] ?? null;
                    if (!is_array($resolvedMetrics)) {
                        return false;
                    }

                    foreach ($resolvedMetrics as $resolvedMetric) {
                        if (($resolvedMetric['requested_metric'] ?? null) === 'cost_per_result') {
                            return ($resolvedMetric['canonical_metric'] ?? null) === 'cost_per_conversion'
                                && ($resolvedMetric['input_type'] ?? null) === 'legacy_alias'
                                && ($resolvedMetric['legacy_alias_of'] ?? null) === 'cost_per_conversion';
                        }
                    }

                    return false;
                }));

            $plan = new AggregationPlan(
                aggregations: [
                    'conversions' => 'conversions',
                    'cost'        => 'cost_per_result',
                    'roas'        => 'purchase_roas',
                ],
                groupBy: ['daily'],
                filters: (object)['channel' => 'facebook_marketing'],
                startDate: '2026-04-01',
                endDate: '2026-04-30',
                context: [
                    'repository' => $repository,
                ],
                stages: [
                    'grouping' => ['normalized_pattern' => 'daily'],
                ],
            );

            $strategy = new MarketingHierarchyStrategy(new CanonicalMetricSqlResolver());
            $rows = $strategy->execute($connection, $plan, true);

            $this->assertIsArray($rows);
            $this->assertNotNull($capturedSql);
            $this->assertStringContainsString("'results'", (string)$capturedSql);
            $this->assertStringContainsString("'results_daily'", (string)$capturedSql);
            $this->assertStringContainsString("'purchase_roas'", (string)$capturedSql);
        }

        public function testAppendsDeprecationMetadataForAmbiguousLegacyMetric(): void
        {
            $connection = $this->createMock(Connection::class);
            $connection->expects($this->once())
                ->method('fetchAllAssociative')
                ->willReturn([['actions' => 5]]);

            $repository = $this->createMock(BaseRepository::class);
            $repository->expects($this->once())
                ->method('appendOptimizedStrategyMeta')
                ->with($this->callback(static function (array $meta): bool {
                    $resolvedMetrics = $meta['metric_resolution']['resolved_metrics'] ?? null;
                    if (!is_array($resolvedMetrics) || !isset($resolvedMetrics[0])) {
                        return false;
                    }

                    $hasCanonicalKey = array_key_exists('canonical_metric', $resolvedMetrics[0]);

                    return ($resolvedMetrics[0]['requested_metric'] ?? null) === 'actions'
                        && $hasCanonicalKey
                        && $resolvedMetrics[0]['canonical_metric'] === null
                        && ($resolvedMetrics[0]['input_type'] ?? null) === 'deprecated_legacy_metric'
                        && ($resolvedMetrics[0]['deprecation']['reason'] ?? null) === 'ambiguous_metric_alias';
                }));

            $plan = new AggregationPlan(
                aggregations: [
                    'actions' => 'actions',
                ],
                groupBy: ['daily'],
                filters: (object)['channel' => 'facebook_marketing'],
                startDate: '2026-04-01',
                endDate: '2026-04-30',
                context: [
                    'repository' => $repository,
                ],
                stages: [
                    'grouping' => ['normalized_pattern' => 'daily'],
                ],
            );

            $strategy = new MarketingHierarchyStrategy(new CanonicalMetricSqlResolver());
            $rows = $strategy->execute($connection, $plan, true);

            $this->assertSame([['actions' => 5]], $rows);
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
                aggregations: ['mystery' => 'mystery_metric'],
                groupBy: ['daily'],
                filters: (object)['channel' => 'facebook_marketing'],
                startDate: '2026-04-01',
                endDate: '2026-04-30',
                context: [
                    'repository' => $repository,
                ],
                stages: [
                    'grouping' => ['normalized_pattern' => 'daily'],
                ],
            );

            $strategy = new MarketingHierarchyStrategy(new CanonicalMetricSqlResolver());
            $rows = $strategy->execute($connection, $plan, true);

            $this->assertNull($rows);
        }
    }

