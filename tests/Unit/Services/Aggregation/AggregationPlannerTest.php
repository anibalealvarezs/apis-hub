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
                    'account_type'     => 'facebook_page',
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
                    'account_type'     => 'instagram_account',
                    'post'             => 'NOT_NULL',
                    'period'           => 'lifetime',
                    'latest_snapshot'  => true,
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

        public function testFlagsMissingProfileCapabilityWhenDeclaredProfilesDoNotMatch(): void
        {
            $repository = $this->createMock(BaseRepository::class);
            $repository->method('getClassName')->willReturn('Entities\\Analytics\\Channeled\\ChanneledMetric');

            $planner = new AggregationPlanner(
                aggregationProfilesResolver: static function (string $channel): array {
                    if ($channel !== 'facebook_organic') {
                        return [];
                    }

                    return [[
                                'key'                => 'fb_campaign_only',
                                'group_patterns'     => [['campaign']],
                                'filter_contract'    => [
                                    'channel' => ['='],
                                ],
                                'reducer_strategies' => [
                                    '*' => 'sum',
                                ],
                            ]];
                }
            );

            $plan = $planner->plan(
                repository: $repository,
                aggregations: ['clicks' => 'clicks'],
                groupBy: ['daily'],
                filters: (object)['channel' => 'facebook_organic'],
                startDate: '2026-04-01',
                endDate: '2026-04-30',
            );

            $this->assertTrue($plan->canUseOptimized());
            $this->assertSame('missing_profile_capability', $plan->getFallbackReason());
            $this->assertTrue($plan->getStages()['profiles']['checked']);
            $this->assertFalse($plan->getStages()['profiles']['supported']);
            $this->assertSame('facebook_organic', $plan->getStages()['profiles']['channel']);
            $this->assertSame(1, $plan->getStages()['profiles']['profile_count']);
            $this->assertSame('no_matching_profile', $plan->getStages()['profiles']['failure_reason']);
        }

        public function testDoesNotFallbackWhenNoProfilesRegisteredForRequestedChannel(): void
        {
            $repository = $this->createMock(BaseRepository::class);
            $repository->method('getClassName')->willReturn('Entities\\Analytics\\Channeled\\ChanneledMetric');

            $planner = new AggregationPlanner(
                aggregationProfilesResolver: static fn(string $channel): array => []
            );

            $plan = $planner->plan(
                repository: $repository,
                aggregations: ['clicks' => 'clicks'],
                groupBy: ['daily'],
                filters: (object)['channel' => 'facebook_organic'],
                startDate: '2026-04-01',
                endDate: '2026-04-30',
            );

            $this->assertNull($plan->getFallbackReason());
            $this->assertTrue($plan->getStages()['profiles']['checked']);
            $this->assertTrue($plan->getStages()['profiles']['supported']);
            $this->assertSame(0, $plan->getStages()['profiles']['profile_count']);
            $this->assertSame('no_profiles_registered', $plan->getStages()['profiles']['failure_reason']);
        }

        public function testLoadsRealDriverAggregationProfilesForGoogleSearchConsoleChannel(): void
        {
            $repository = $this->createMock(BaseRepository::class);
            $repository->method('getClassName')->willReturn('Entities\\Analytics\\Channeled\\ChanneledMetric');

            $planner = new AggregationPlanner();
            $plan = $planner->plan(
                repository: $repository,
                aggregations: ['position' => 'position', 'clicks' => 'clicks'],
                groupBy: ['dimensions.country', 'dimensions.device'],
                filters: (object)['channel' => 'google_search_console'],
                startDate: '2026-04-01',
                endDate: '2026-04-30',
            );

            $profilesStage = $plan->getStages()['profiles'];

            $this->assertTrue($profilesStage['checked']);
            $this->assertTrue($profilesStage['supported']);
            $this->assertSame('google_search_console', $profilesStage['channel']);
            $this->assertGreaterThan(0, $profilesStage['profile_count']);
            $this->assertContains('gsc_search_cube', $profilesStage['matched_profiles']);
            $this->assertNull($plan->getFallbackReason());
        }

        public function testLoadsRealDriverAggregationProfilesForFacebookOrganicChannel(): void
        {
            $repository = $this->createMock(BaseRepository::class);
            $repository->method('getClassName')->willReturn('Entities\\Analytics\\Channeled\\ChanneledMetric');

            $planner = new AggregationPlanner();
            $plan = $planner->plan(
                repository: $repository,
                aggregations: ['reach' => 'reach', 'views' => 'views'],
                groupBy: ['page', 'page_id', 'page_title'],
                filters: (object)[
                    'channel'          => 'facebook_organic',
                    'account_type'     => 'facebook_page',
                    'page_platform_id' => '906151479251268',
                ],
                startDate: '2026-04-01',
                endDate: '2026-04-30',
            );

            $profilesStage = $plan->getStages()['profiles'];

            $this->assertTrue($profilesStage['checked']);
            $this->assertTrue($profilesStage['supported']);
            $this->assertSame('facebook_organic', $profilesStage['channel']);
            $this->assertGreaterThan(0, $profilesStage['profile_count']);
            $this->assertContains('facebook_organic_page_flow', $profilesStage['matched_profiles']);
            $this->assertNull($plan->getFallbackReason());
        }

        public function testResolvesNumericChannelFilterToProfileChannelKey(): void
        {
            $repository = $this->createMock(BaseRepository::class);
            $repository->method('getClassName')->willReturn('Entities\\Analytics\\Channeled\\ChanneledMetric');

            $planner = new AggregationPlanner(
                aggregationProfilesResolver: static function (string $channel): array {
                    if ($channel !== 'google_search_console') {
                        return [];
                    }

                    return [[
                                'key'                => 'gsc_search_cube',
                                'group_patterns'     => [['dimensions.country', 'dimensions.device']],
                                'filter_contract'    => [
                                    'channel' => ['eq'],
                                ],
                                'reducer_strategies' => [
                                    '*' => 'weighted_average',
                                ],
                            ]];
                },
                channelKeyResolver: static fn(int $channelId): ?string => $channelId === 7 ? 'google_search_console' : null,
            );

            $plan = $planner->plan(
                repository: $repository,
                aggregations: ['position' => 'position', 'clicks' => 'clicks'],
                groupBy: ['dimensions.country', 'dimensions.device'],
                filters: (object)['channel' => 7],
                startDate: '2026-04-01',
                endDate: '2026-04-30',
            );

            $profilesStage = $plan->getStages()['profiles'];

            $this->assertTrue($profilesStage['checked']);
            $this->assertTrue($profilesStage['supported']);
            $this->assertSame('google_search_console', $profilesStage['channel']);
            $this->assertContains('gsc_search_cube', $profilesStage['matched_profiles']);
            $this->assertNull($plan->getFallbackReason());
        }

        public function testSkipsProfileCheckWhenNumericChannelCannotBeResolved(): void
        {
            $repository = $this->createMock(BaseRepository::class);
            $repository->method('getClassName')->willReturn('Entities\\Analytics\\Channeled\\ChanneledMetric');

            $planner = new AggregationPlanner(
                aggregationProfilesResolver: static fn(string $channel): array => [],
                channelKeyResolver: static fn(int $channelId): ?string => null,
            );

            $plan = $planner->plan(
                repository: $repository,
                aggregations: ['clicks' => 'clicks'],
                groupBy: ['daily'],
                filters: (object)['channel' => 9999],
                startDate: '2026-04-01',
                endDate: '2026-04-30',
            );

            $profilesStage = $plan->getStages()['profiles'];

            $this->assertFalse($profilesStage['checked']);
            $this->assertTrue($profilesStage['supported']);
            $this->assertNull($profilesStage['channel']);
            $this->assertNull($profilesStage['failure_reason']);
            $this->assertNull($plan->getFallbackReason());
        }
    }

