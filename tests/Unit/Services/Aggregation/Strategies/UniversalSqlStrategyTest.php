<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation\Strategies;

use Doctrine\DBAL\Connection;
use Repositories\BaseRepository;
use Services\Aggregation\AggregationPlan;
use Services\Aggregation\Strategies\UniversalSqlStrategy;
use Tests\Unit\BaseUnitTestCase;

final class UniversalSqlStrategyTest extends BaseUnitTestCase
{
    public function testFacebookOrganicPageLevelQueriesExcludePostRows(): void
    {
        $capturedSql = null;

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [[
                    'daily' => '2026-06-06',
                    'reach' => 1353,
                ]];
            });

        $repository = $this->createMock(BaseRepository::class);
        $repository->expects($this->once())->method('appendOptimizedStrategyMeta');

        $plan = new AggregationPlan(
            aggregations: ['reach' => 'reach'],
            groupBy: ['daily'],
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
                'grouping' => ['normalized_pattern' => 'daily'],
            ],
            candidateOptimizedStrategies: ['universal_sql']
        );

        $strategy = new UniversalSqlStrategy();
        $rows = $strategy->execute($connection, $plan, true);

        $this->assertIsArray($rows);
        $this->assertNotNull($capturedSql);
        $this->assertStringContainsString('mc.post_id IS NULL', (string)$capturedSql);
        $this->assertStringContainsString('mc.dimension_set_id IS NULL', (string)$capturedSql);
    }

    public function testFacebookOrganicAccountLevelQueriesExcludePostRows(): void
    {
        $capturedSql = null;

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [[
                    'daily' => '2026-06-06',
                    'reach' => 1353,
                ]];
            });

        $repository = $this->createMock(BaseRepository::class);
        $repository->expects($this->once())->method('appendOptimizedStrategyMeta');

        $plan = new AggregationPlan(
            aggregations: ['reach' => 'reach'],
            groupBy: ['daily'],
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
                'grouping' => ['normalized_pattern' => 'daily'],
            ],
            candidateOptimizedStrategies: ['universal_sql']
        );

        $strategy = new UniversalSqlStrategy();
        $rows = $strategy->execute($connection, $plan, true);

        $this->assertIsArray($rows);
        $this->assertNotNull($capturedSql);
        $this->assertStringContainsString('mc.post_id IS NULL', (string)$capturedSql);
        $this->assertStringContainsString('mc.dimension_set_id IS NULL', (string)$capturedSql);
    }

    public function testFacebookOrganicBreakdownQueriesKeepDimensionRows(): void
    {
        $capturedSql = null;

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [[
                    'daily' => '2026-06-06',
                    'reaction_type' => 'like',
                    'reach' => 321,
                ]];
            });

        $repository = $this->createMock(BaseRepository::class);
        $repository->expects($this->once())->method('appendOptimizedStrategyMeta');

        $plan = new AggregationPlan(
            aggregations: ['reach' => 'reach'],
            groupBy: ['daily', 'reaction_type'],
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
                'grouping' => ['normalized_pattern' => 'daily+reaction_type'],
            ],
            candidateOptimizedStrategies: ['universal_sql']
        );

        $strategy = new UniversalSqlStrategy();
        $rows = $strategy->execute($connection, $plan, true);

        $this->assertIsArray($rows);
        $this->assertNotNull($capturedSql);
        $this->assertStringContainsString('mc.post_id IS NULL', (string)$capturedSql);
        $this->assertStringNotContainsString('mc.dimension_set_id IS NULL', (string)$capturedSql);
    }

    public function testFacebookOrganicPostGranularQueriesKeepPostRows(): void
    {
        $capturedSql = null;

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [[
                    'post' => '123_abc',
                    'post_id' => '123_abc',
                    'caption' => 'Demo',
                    'message' => 'Demo',
                    'media_type' => 'photo',
                    'permalink' => 'https://facebook.com/post/123_abc',
                    'permalink_url' => 'https://facebook.com/post/123_abc',
                    'timestamp' => '2026-06-06T00:00:00+0000',
                    'created_time' => '2026-06-06T00:00:00+0000',
                    'reach' => 42,
                ]];
            });

        $repository = $this->createMock(BaseRepository::class);
        $repository->expects($this->once())->method('appendOptimizedStrategyMeta');

        $plan = new AggregationPlan(
            aggregations: ['reach' => 'reach'],
            groupBy: ['post', 'post_id', 'caption', 'message', 'media_type', 'permalink', 'permalink_url', 'timestamp', 'created_time'],
            filters: (object)[
                'channel' => 'facebook_organic',
                'account_type' => 'facebook_page',
                'post' => 'NOT_NULL',
            ],
            startDate: '2026-06-01',
            endDate: '2026-06-06',
            context: [
                'repository' => $repository,
            ],
            stages: [
                'grouping' => ['normalized_pattern' => 'caption+created_time+media_type+message+permalink+permalink_url+post+post_id+timestamp'],
            ],
            candidateOptimizedStrategies: ['universal_sql']
        );

        $strategy = new UniversalSqlStrategy();
        $rows = $strategy->execute($connection, $plan, true);

        $this->assertIsArray($rows);
        $this->assertNotNull($capturedSql);
        $this->assertStringNotContainsString('mc.post_id IS NULL', (string)$capturedSql);
        $this->assertStringNotContainsString('mc.dimension_set_id IS NULL', (string)$capturedSql);
    }

    public function testFacebookOrganicChartFallbackUsesDailyOrganicPeriod(): void
    {
        $capturedSql = null;

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [[
                    'daily' => '2026-06-06',
                    'reach' => 17,
                ]];
            });

        $repository = $this->createMock(BaseRepository::class);
        $repository->expects($this->once())->method('appendOptimizedStrategyMeta');

        $plan = new AggregationPlan(
            aggregations: ['reach' => 'reach'],
            groupBy: ['daily'],
            filters: (object)[
                'channel' => 'facebook_organic',
                'account_type' => 'facebook_page',
                'page_platform_id' => '147613761768682',
            ],
            startDate: '2026-05-08',
            endDate: '2026-06-06',
            context: [
                'repository' => $repository,
            ],
            stages: [
                'grouping' => ['normalized_pattern' => 'daily'],
            ],
            candidateOptimizedStrategies: ['universal_sql']
        );

        $strategy = new UniversalSqlStrategy();
        $rows = $strategy->execute($connection, $plan, true);

        $this->assertIsArray($rows);
        $this->assertNotNull($capturedSql);
        $this->assertStringContainsString("LOWER(mc.period) = 'daily'", (string)$capturedSql);
        $this->assertStringNotContainsString("LOWER(mc.period) = 'lifetime'", (string)$capturedSql);
    }

    public function testFacebookOrganicPostFallbackUsesLifetimeOrganicPeriod(): void
    {
        $capturedSql = null;

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [[
                    'post' => '123_abc',
                    'reach' => 42,
                ]];
            });

        $repository = $this->createMock(BaseRepository::class);
        $repository->expects($this->once())->method('appendOptimizedStrategyMeta');

        $plan = new AggregationPlan(
            aggregations: ['reach' => 'reach'],
            groupBy: ['post', 'post_id', 'caption', 'message', 'media_type', 'permalink', 'permalink_url', 'timestamp', 'created_time'],
            filters: (object)[
                'channel' => 'facebook_organic',
                'account_type' => 'facebook_page',
                'post' => 'NOT_NULL',
            ],
            startDate: '2026-05-08',
            endDate: '2026-06-06',
            context: [
                'repository' => $repository,
            ],
            stages: [
                'grouping' => ['normalized_pattern' => 'caption+created_time+media_type+message+permalink+permalink_url+post+post_id+timestamp'],
            ],
            candidateOptimizedStrategies: ['universal_sql']
        );

        $strategy = new UniversalSqlStrategy();
        $rows = $strategy->execute($connection, $plan, true);

        $this->assertIsArray($rows);
        $this->assertNotNull($capturedSql);
        $this->assertStringContainsString("LOWER(mc.period) = 'daily'", (string)$capturedSql);
        $this->assertStringNotContainsString("LOWER(mc.period) = 'lifetime'", (string)$capturedSql);
    }

    public function testFacebookOrganicStringPostFiltersUsePlatformPostIdColumn(): void
    {
        $capturedSql = null;
        $capturedParams = [];

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$capturedSql, &$capturedParams): array {
                $capturedSql = $sql;
                $capturedParams = $params;

                return [[
                    'daily' => '2026-06-06',
                    'reach' => 17,
                ]];
            });

        $repository = $this->createMock(BaseRepository::class);
        $repository->expects($this->once())->method('appendOptimizedStrategyMeta');

        $plan = new AggregationPlan(
            aggregations: ['reach' => 'reach'],
            groupBy: ['daily'],
            filters: (object)[
                'channel' => 'facebook_organic',
                'account_type' => 'facebook_page',
                'post' => '147613761768682_122268816536074498',
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
            candidateOptimizedStrategies: ['universal_sql']
        );

        $strategy = new UniversalSqlStrategy();
        $rows = $strategy->execute($connection, $plan, true);

        $this->assertIsArray($rows);
        $this->assertNotNull($capturedSql);
        $this->assertStringContainsString('LEFT JOIN posts fpost ON fpost.id = mc.post_id', (string)$capturedSql);
        $this->assertStringContainsString('fpost.post_id = :filter_post', (string)$capturedSql);
        $this->assertSame('147613761768682_122268816536074498', $capturedParams['filter_post'] ?? null);
    }

    public function testFacebookOrganicOversizedNumericPostFiltersUsePlatformPostIdColumn(): void
    {
        $capturedSql = null;
        $capturedParams = [];

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$capturedSql, &$capturedParams): array {
                $capturedSql = $sql;
                $capturedParams = $params;

                return [[
                    'daily' => '2026-06-06',
                    'reach' => 17,
                ]];
            });

        $repository = $this->createMock(BaseRepository::class);
        $repository->expects($this->once())->method('appendOptimizedStrategyMeta');

        $plan = new AggregationPlan(
            aggregations: ['reach' => 'reach'],
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
            candidateOptimizedStrategies: ['universal_sql']
        );

        $strategy = new UniversalSqlStrategy();
        $rows = $strategy->execute($connection, $plan, true);

        $this->assertIsArray($rows);
        $this->assertNotNull($capturedSql);
        $this->assertStringContainsString('LEFT JOIN posts fpost ON fpost.id = mc.post_id', (string)$capturedSql);
        $this->assertStringContainsString('fpost.post_id = :filter_post', (string)$capturedSql);
        $this->assertSame('18121430740577061', $capturedParams['filter_post'] ?? null);
    }
}
