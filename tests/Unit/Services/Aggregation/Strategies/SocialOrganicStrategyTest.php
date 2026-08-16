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
            $this->assertStringContainsString("'likes'", (string)$capturedSql);
            $this->assertStringContainsString("'comments'", (string)$capturedSql);
        }

        public function testPageSummaryExcludesPostLevelRows(): void
        {
            $capturedSql = null;

            $connection = $this->createMock(Connection::class);
            $connection->expects($this->once())
                ->method('fetchAllAssociative')
                ->willReturnCallback(static function (string $sql, array $params = []) use (&$capturedSql): array {
                    $capturedSql = $sql;

                    return [['page' => 'https://www.facebook.com/123', 'page_id' => 1, 'page_title' => 'Demo', 'reach' => 1353]];
                });

            $repository = $this->createMock(BaseRepository::class);
            $repository->expects($this->once())->method('appendOptimizedStrategyMeta');

            $plan = new AggregationPlan(
                aggregations: ['reach' => 'reach'],
                groupBy: ['page', 'page_id', 'page_title'],
                filters: (object)[
                    'channel' => 'facebook_organic',
                    'account_type' => 'facebook_page',
                    'page_platform_id' => '147613761768682',
                ],
                startDate: '2026-06-01',
                endDate: '2026-06-06',
                context: [
                    'repository' => $repository,
                ],
                stages: [
                    'grouping' => ['normalized_pattern' => 'page+page_id+page_title'],
                ],
                candidateOptimizedStrategies: ['social_organic_page_summary']
            );

            $strategy = new SocialOrganicStrategy(new CanonicalMetricSqlResolver());
            $rows = $strategy->execute($connection, $plan, true);

            $this->assertIsArray($rows);
            $this->assertNotNull($capturedSql);
            $this->assertStringContainsString('mc.post_id IS NULL', (string)$capturedSql);
            $this->assertStringContainsString('mc.dimension_set_id IS NULL', (string)$capturedSql);
        }

        public function testLinkedPagesExcludesPostLevelRows(): void
        {
            $capturedSql = null;

            $connection = $this->createMock(Connection::class);
            $connection->expects($this->once())
                ->method('fetchAllAssociative')
                ->willReturnCallback(static function (string $sql, array $params = []) use (&$capturedSql): array {
                    $capturedSql = $sql;

                    return [[
                        'channeled_account_id' => 177,
                        'channeledaccount' => 'Demo IG',
                        'linked_platform_entity_id' => '178',
                        'page_platform_id' => '112975583443266',
                        'reach' => 1353,
                    ]];
                });

            $repository = $this->createMock(BaseRepository::class);
            $repository->expects($this->once())->method('appendOptimizedStrategyMeta');

            $plan = new AggregationPlan(
                aggregations: ['reach' => 'reach'],
                groupBy: ['channeled_account_id', 'channeledaccount', 'linked_platform_entity_id', 'page_platform_id'],
                filters: (object)[
                    'channel' => 'facebook_organic',
                    'account_type' => 'instagram_account',
                    'channeledAccount' => '177',
                ],
                startDate: '2026-06-01',
                endDate: '2026-06-06',
                context: [
                    'repository' => $repository,
                ],
                stages: [
                    'grouping' => ['normalized_pattern' => 'channeled_account_id+channeledaccount+linked_platform_entity_id+page_platform_id'],
                ],
                candidateOptimizedStrategies: ['social_organic_linked_pages']
            );

            $strategy = new SocialOrganicStrategy(new CanonicalMetricSqlResolver());
            $rows = $strategy->execute($connection, $plan, true);

            $this->assertIsArray($rows);
            $this->assertNotNull($capturedSql);
            $this->assertStringContainsString('mc.post_id IS NULL', (string)$capturedSql);
            $this->assertStringContainsString('mc.dimension_set_id IS NULL', (string)$capturedSql);
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

        public function testPostSnapshotReturnsEmptyForSentinelChanneledAccount(): void
        {
            $connection = $this->createMock(Connection::class);
            $connection->expects($this->never())->method('fetchAllAssociative');

            $repository = $this->createMock(BaseRepository::class);
            $repository->expects($this->once())->method('appendOptimizedStrategyMeta');

            $plan = new AggregationPlan(
                aggregations: ['reach' => 'reach', 'likes' => 'likes'],
                groupBy: ['caption', 'created_time', 'media_type', 'message', 'permalink', 'permalink_url', 'post', 'post_id', 'timestamp'],
                filters: (object)[
                    'channel' => 'facebook_organic',
                    'account_type' => 'instagram_account',
                    'channeledAccount' => (object)['operator' => 'in', 'value' => ['__NONE__']],
                    'post' => 'NOT_NULL',
                    'period' => 'lifetime',
                    'latest_snapshot' => true,
                ],
                startDate: '2026-05-24',
                endDate: '2026-06-23',
                context: [
                    'repository' => $repository,
                ],
                stages: [
                    'grouping' => ['normalized_pattern' => 'caption+created_time+media_type+message+permalink+permalink_url+post+post_id+timestamp'],
                ],
                candidateOptimizedStrategies: ['social_organic_post_snapshot']
            );

            $strategy = new SocialOrganicStrategy(new CanonicalMetricSqlResolver());
            $rows = $strategy->execute($connection, $plan, false);

            $this->assertSame([], $rows);
        }

        public function testLinkedPagesReturnsEmptyForSentinelChanneledAccount(): void
        {
            $connection = $this->createMock(Connection::class);
            $connection->expects($this->never())->method('fetchAllAssociative');

            $repository = $this->createMock(BaseRepository::class);
            $repository->expects($this->once())->method('appendOptimizedStrategyMeta');

            $plan = new AggregationPlan(
                aggregations: ['reach' => 'reach'],
                groupBy: ['channeled_account_id', 'channeledaccount', 'linked_platform_entity_id', 'page_platform_id'],
                filters: (object)[
                    'channel' => 'facebook_organic',
                    'account_type' => 'instagram_account',
                    'channeledAccount' => (object)['operator' => 'in', 'value' => ['__NONE__']],
                ],
                startDate: '2026-06-01',
                endDate: '2026-06-06',
                context: [
                    'repository' => $repository,
                ],
                stages: [
                    'grouping' => ['normalized_pattern' => 'channeled_account_id+channeledaccount+linked_platform_entity_id+page_platform_id'],
                ],
                candidateOptimizedStrategies: ['social_organic_linked_pages']
            );

            $strategy = new SocialOrganicStrategy(new CanonicalMetricSqlResolver());
            $rows = $strategy->execute($connection, $plan, false);

            $this->assertSame([], $rows);
        }

        public function testPostSnapshotUsesPlatformPostIdFilterForStringPostIds(): void
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
                aggregations: ['trend_total_reach' => 'reach'],
                groupBy: ['daily'],
                filters: (object)[
                    'channel' => 'facebook_organic',
                    'account_type' => 'facebook_page',
                    'post' => '147613761768682_122205341918074498',
                    'page' => '119',
                    'period' => 'lifetime',
                ],
                startDate: '2026-05-08',
                endDate: '2026-06-07',
                context: [
                    'repository' => $repository,
                ],
                stages: [
                    'grouping' => ['normalized_pattern' => 'daily'],
                ],
                candidateOptimizedStrategies: ['social_organic_post_snapshot']
            );

            $strategy = new SocialOrganicStrategy(new CanonicalMetricSqlResolver());
            $rows = $strategy->execute($connection, $plan, true);

            $this->assertIsArray($rows);
            $this->assertNotNull($capturedSql);
            $this->assertStringContainsString('ps.post_id = :postId', (string)$capturedSql);
            $this->assertSame('147613761768682_122205341918074498', $capturedParams['postId'] ?? null);
        }

        public function testPostSnapshotUsesPlatformPostIdFilterForOversizedNumericPostIds(): void
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
                aggregations: ['trend_total_reach' => 'reach'],
                groupBy: ['daily'],
                filters: (object)[
                    'channel' => 'facebook_organic',
                    'account_type' => 'instagram_account',
                    'post' => '18121430740577061',
                    'channeledAccount' => '124',
                    'period' => 'daily',
                ],
                startDate: '2026-05-08',
                endDate: '2026-06-07',
                context: [
                    'repository' => $repository,
                ],
                stages: [
                    'grouping' => ['normalized_pattern' => 'daily'],
                ],
                candidateOptimizedStrategies: ['social_organic_post_snapshot']
            );

            $strategy = new SocialOrganicStrategy(new CanonicalMetricSqlResolver());
            $rows = $strategy->execute($connection, $plan, true);

            $this->assertIsArray($rows);
            $this->assertNotNull($capturedSql);
            $this->assertStringContainsString('ps.post_id = :postId', (string)$capturedSql);
            $this->assertSame('18121430740577061', $capturedParams['postId'] ?? null);
        }
    }
