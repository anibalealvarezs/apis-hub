<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Repositories\BaseRepository;
use Services\Aggregation\AggregationPlanner;
use Tests\Unit\BaseUnitTestCase;

final class AggregationPlannerTest extends BaseUnitTestCase
{
    public function testPlansOptimizedFirstForChanneledMetrics(): void
    {
        $repository = $this->createMock(BaseRepository::class);
        $repository->method('getClassName')->willReturn('Entities\\Analytics\\Channeled\\ChanneledMetric');

        $planner = new AggregationPlanner();
        $plan = $planner->plan(
            repository: $repository,
            aggregations: ['position' => 'position', 'clicks' => 'clicks'],
            groupBy: ['daily'],
            filters: (object)['period' => 'daily'],
            startDate: '2026-04-01',
            endDate: '2026-04-30',
        );

        $this->assertSame('optimized', $plan->getPreferredExecutionPath());
        $this->assertTrue($plan->canUseOptimized());
        $this->assertNull($plan->getFallbackReason());
        $this->assertContains('weighted_metric', $plan->getCandidateOptimizedStrategies());
        $this->assertSame('daily', $plan->getStages()['facts']['requested_period']);
        $this->assertTrue($plan->getStages()['grouping']['has_temporal_grouping']);
    }

    public function testMarksUnsupportedEntityTypesForLegacyFallback(): void
    {
        $repository = $this->createMock(BaseRepository::class);
        $repository->method('getClassName')->willReturn('Entities\\Misc\\AuditLog');

        $planner = new AggregationPlanner();
        $plan = $planner->plan(
            repository: $repository,
            aggregations: ['count' => 'COUNT(id)'],
        );

        $this->assertFalse($plan->canUseOptimized());
        $this->assertSame('unsupported_entity_type', $plan->getFallbackReason());
        $this->assertSame([], $plan->getCandidateOptimizedStrategies());
    }

    public function testFlagsUnsupportedFilterOperators(): void
    {
        $repository = $this->createMock(BaseRepository::class);
        $repository->method('getClassName')->willReturn('Entities\\Analytics\\Channeled\\ChanneledMetric');

        $planner = new AggregationPlanner();
        $plan = $planner->plan(
            repository: $repository,
            aggregations: ['clicks' => 'clicks'],
            filters: (object)[
                'dimensions.country' => (object)['operator' => 'gt', 'value' => 'VE'],
            ],
        );

        $this->assertTrue($plan->canUseOptimized());
        $this->assertSame('unsupported_filter_operator', $plan->getFallbackReason());
        $this->assertSame(['gt'], $plan->getStages()['filters']['unsupported_operators']);
    }

    public function testFlagsMissingReducerStrategyForUnknownMetricExpression(): void
    {
        $repository = $this->createMock(BaseRepository::class);
        $repository->method('getClassName')->willReturn('Entities\\Analytics\\Metric');

        $planner = new AggregationPlanner();
        $plan = $planner->plan(
            repository: $repository,
            aggregations: ['mystery' => 'mystery_ratio'],
        );

        $this->assertTrue($plan->canUseOptimized());
        $this->assertSame('missing_reducer_strategy', $plan->getFallbackReason());
        $this->assertSame(['mystery_ratio'], $plan->getStages()['reducers']['missing_reducer_expressions']);
    }

    public function testFlagsUnsupportedGroupPatternWhenPlannerCannotNormalizeGrouping(): void
    {
        $repository = $this->createMock(BaseRepository::class);
        $repository->method('getClassName')->willReturn('Entities\\Analytics\\Metric');

        $planner = new AggregationPlanner();
        $plan = $planner->plan(
            repository: $repository,
            aggregations: ['clicks' => 'clicks'],
            groupBy: ['daily', 'dimensions.country'],
        );

        $this->assertTrue($plan->canUseOptimized());
        $this->assertSame('unsupported_group_pattern', $plan->getFallbackReason());
        $this->assertNull($plan->getStages()['grouping']['normalized_pattern']);
    }

    public function testNarrowsCandidatesToFacebookOrganicPageSummaryStrategy(): void
    {
        $repository = $this->createMock(BaseRepository::class);
        $repository->method('getClassName')->willReturn('Entities\\Analytics\\Channeled\\ChanneledMetric');

        $planner = new AggregationPlanner();
        $plan = $planner->plan(
            repository: $repository,
            aggregations: ['reach' => 'reach', 'views' => 'views'],
            groupBy: ['page', 'page_id', 'page_title'],
            filters: (object)[
                'account_type' => 'facebook_page',
                'page_platform_id' => '906151479251268',
            ],
            startDate: '2026-04-01',
            endDate: '2026-04-30',
        );

        $this->assertSame(['facebook_organic_page_summary'], $plan->getCandidateOptimizedStrategies());
        $this->assertSame(['facebook_organic_page_summary'], $plan->getStages()['optimized']['candidate_strategies']);
    }

    public function testNarrowsCandidatesToFacebookOrganicPostSnapshotStrategy(): void
    {
        $repository = $this->createMock(BaseRepository::class);
        $repository->method('getClassName')->willReturn('Entities\\Analytics\\Channeled\\ChanneledMetric');

        $planner = new AggregationPlanner();
        $plan = $planner->plan(
            repository: $repository,
            aggregations: ['views' => 'views', 'likes' => 'likes'],
            groupBy: ['caption', 'created_time', 'media_type', 'message', 'permalink', 'permalink_url', 'post', 'post_id', 'timestamp'],
            filters: (object)[
                'channeledAccount' => 17,
                'account_type' => 'instagram_account',
                'post' => 'NOT_NULL',
                'period' => 'lifetime',
                'latest_snapshot' => true,
            ],
            startDate: '2026-04-01',
            endDate: '2026-04-30',
        );

        $this->assertSame(['facebook_organic_post_snapshot'], $plan->getCandidateOptimizedStrategies());
    }

    public function testNarrowsCandidatesToWeightedMetricStrategy(): void
    {
        $repository = $this->createMock(BaseRepository::class);
        $repository->method('getClassName')->willReturn('Entities\\Analytics\\Channeled\\ChanneledMetric');

        $planner = new AggregationPlanner();
        $plan = $planner->plan(
            repository: $repository,
            aggregations: ['position' => 'position', 'clicks' => 'clicks'],
            groupBy: ['daily'],
            filters: (object)['page' => 1],
            startDate: '2026-04-01',
            endDate: '2026-04-30',
        );

        $this->assertSame(['weighted_metric'], $plan->getCandidateOptimizedStrategies());
    }
}

