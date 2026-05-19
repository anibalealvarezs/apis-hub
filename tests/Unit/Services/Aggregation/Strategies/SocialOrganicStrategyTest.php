<?php

    declare(strict_types=1);

    namespace Tests\Unit\Services\Aggregation\Strategies;

    use Doctrine\DBAL\Connection;
    use Repositories\BaseRepository;
    use Services\Aggregation\AggregationPlan;
    use Services\Aggregation\CanonicalMetricSqlResolver;
    use Services\Aggregation\Strategies\SocialOrganicStrategy;
    use Tests\Unit\BaseUnitTestCase;

    final class SocialOrganicStrategyTest extends BaseUnitTestCase
    {
        public function testUsesCanonicalResolverForOrganicMetrics(): void
        {
            $capturedSql = null;

            $connection = $this->createMock(Connection::class);
            $connection->expects($this->once())
                ->method('fetchAllAssociative')
                ->willReturnCallback(static function (string $sql, array $params = []) use (&$capturedSql): array {
                    $capturedSql = $sql;

                    return [['daily' => '2026-04-01', 'likes' => 100]];
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
                        if (($resolvedMetric['requested_metric'] ?? null) === 'post_reactions_by_type_total') {
                            return ($resolvedMetric['canonical_metric'] ?? null) === 'likes'
                                && ($resolvedMetric['input_type'] ?? null) === 'legacy_alias'
                                && ($resolvedMetric['legacy_alias_of'] ?? null) === 'likes';
                        }
                    }

                    return false;
                }));

            $plan = new AggregationPlan(
                aggregations: [
                    'likes' => 'post_reactions_by_type_total',
                    'comments' => 'comments',
                ],
                groupBy: ['daily'],
                filters: (object)['channel' => 'facebook_organic'],
                startDate: '2026-04-01',
                endDate: '2026-04-30',
                context: [
                    'repository' => $repository,
                ],
                stages: [
                    'grouping' => ['normalized_pattern' => 'daily'],
                ],
                candidateOptimizedStrategies: ['social_organic_page_summary']
            );

            $strategy = new SocialOrganicStrategy(new CanonicalMetricSqlResolver());
            $rows = $strategy->execute($connection, $plan, true);

            $this->assertIsArray($rows);
            $this->assertNotNull($capturedSql);
            $this->assertStringContainsString("'post_reactions_by_type_total'", (string)$capturedSql);
            $this->assertStringContainsString("'comments'", (string)$capturedSql);
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
                aggregations: ['mystery' => 'mystery_organic_metric'],
                groupBy: ['daily'],
                filters: (object)['channel' => 'facebook_organic'],
                startDate: '2026-04-01',
                endDate: '2026-04-30',
                context: [
                    'repository' => $repository,
                ],
                stages: [
                    'grouping' => ['normalized_pattern' => 'daily'],
                ],
                candidateOptimizedStrategies: ['social_organic_page_summary']
            );

            $strategy = new SocialOrganicStrategy(new CanonicalMetricSqlResolver());
            $rows = $strategy->execute($connection, $plan, true);

            $this->assertNull($rows);
        }
    }
