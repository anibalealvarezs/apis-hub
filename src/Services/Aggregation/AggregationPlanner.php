<?php

    declare(strict_types=1);

    namespace Services\Aggregation;

    use Anibalealvarezs\ApiDriverCore\Classes\MetricAggregationStrategyRegistry;
    use Entities\Analytics\Channel;
    use Exceptions\ConfigurationException;
    use Helpers\Helpers;
    use Repositories\BaseRepository;

    final class AggregationPlanner
    {
        /** @var callable|null */
        private $channelKeyResolver;

        private AggregationProfileResolver $aggregationProfileResolver;

        public function __construct(
            ?callable $aggregationProfilesResolver = null,
            ?callable $channelKeyResolver = null,
            ?AggregationProfileResolver $aggregationProfileResolver = null
        )
        {
            $this->channelKeyResolver = $channelKeyResolver;
            $this->aggregationProfileResolver = $aggregationProfileResolver
                ?? new AggregationProfileResolver($aggregationProfilesResolver);
        }

        /**
         * @param array<string, string> $aggregations
         * @param array<int, string> $groupBy
         * @throws ConfigurationException
         */
        public function plan(
            BaseRepository $repository,
            array          $aggregations,
            array          $groupBy = [],
            ?object        $filters = null,
            ?string        $startDate = null,
            ?string        $endDate = null,
            ?string        $orderBy = null,
            ?string        $orderDir = 'ASC'
        ): AggregationPlan
        {
            $entityName = $repository->getClassName();
            $isChanneledMetric = str_ends_with($entityName, 'ChanneledMetric');
            $isMetric = str_ends_with($entityName, 'Analytics\Metric');
            $canUseOptimized = $isMetric || $isChanneledMetric;
            $filtersArr = $this->normalizeFilters($filters);
            $groupPattern = $this->resolveGroupPattern($groupBy);
            $filterOperators = $this->collectFilterOperators($filtersArr);
            $unsupportedFilterOperators = array_values(array_diff($filterOperators, ['eq', 'neq', 'is_null', 'is_not_null']));
            $reducerAnalysis = $this->analyzeReducers($aggregations, $isMetric || $isChanneledMetric);

            $requestedPeriod = null;
            if ($filters !== null && isset($filters->period) && is_string($filters->period) && trim($filters->period) !== '') {
                $requestedPeriod = strtolower(trim($filters->period));
            }

            $snapshotDelta = false;
            if ($filters !== null && isset($filters->snapshot_delta)) {
                $snapshotDelta = filter_var($filters->snapshot_delta, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($snapshotDelta === null) {
                    $snapshotDelta = (bool)$filters->snapshot_delta;
                }
            }

            $candidateOptimizedStrategies = $this->buildCandidateOptimizedStrategies(
                isChanneledMetric: $isChanneledMetric,
                isMetric: $isMetric,
                groupPattern: $groupPattern,
                filters: $filtersArr,
                aggregations: $aggregations,
                reducerAnalysis: $reducerAnalysis,
                requestedPeriod: $requestedPeriod,
                snapshotDelta: $snapshotDelta,
                startDate: $startDate,
                endDate: $endDate,
            );

            $context = [
                'entity_name'         => $entityName,
                'is_metric'           => $isMetric,
                'is_channeled_metric' => $isChanneledMetric,
                'is_postgres'         => Helpers::isPostgres(),
                'repository'          => $repository,
            ];

            $stages = [
                'scope'    => [
                    'entity_name'         => $entityName,
                    'is_metric'           => $isMetric,
                    'is_channeled_metric' => $isChanneledMetric,
                ],
                'facts'    => [
                    'aggregation_aliases'     => array_keys($aggregations),
                    'aggregation_expressions' => array_values($aggregations),
                    'requested_period'        => $requestedPeriod,
                    'uses_snapshot_delta'     => $snapshotDelta,
                    'reducer_analysis'        => $reducerAnalysis,
                ],
                'grouping' => [
                    'group_by'              => $groupBy,
                    'has_temporal_grouping' => $this->hasTemporalGrouping($groupBy),
                    'normalized_pattern'    => $groupPattern,
                ],
                'reducers' => [
                    'requested_aggregations'      => $aggregations,
                    'missing_reducer_expressions' => $reducerAnalysis['missing_reducer_expressions'],
                    'weighted_metric_expressions' => $reducerAnalysis['weighted_metric_expressions'],
                ],
                'filters'  => [
                    'operators'             => $filterOperators,
                    'unsupported_operators' => $unsupportedFilterOperators,
                ],
                'post'     => [
                    'order_by'  => $orderBy,
                    'order_dir' => $orderDir,
                ],
                'optimized' => [
                    'candidate_strategies' => $candidateOptimizedStrategies,
                ],
            ];

            $channel = $this->resolveRequestedChannelKey($filtersArr, $repository);
            $this->applyChannelSpecificFilters($filtersArr, $groupPattern, $channel);

            $fallbackReason = $this->resolveFallbackReason(
                canUseOptimized: $canUseOptimized,
                unsupportedFilterOperators: $unsupportedFilterOperators,
                reducerAnalysis: $reducerAnalysis,
                groupPattern: $groupPattern,
                groupBy: $groupBy,
            );

            $profileValidation = $this->evaluateProfileCapability(
                filters: $filtersArr,
                groupPattern: $groupPattern,
                aggregations: $aggregations,
                channel: $channel,
            );
            $stages['profiles'] = $profileValidation;

            if ($fallbackReason === null && !$profileValidation['supported']) {
                 error_log("[AggregateDebug] fallback=missing_profile_capability channel={$channel} groupPattern={$groupPattern} reason=".($profileValidation['failure_reason'] ?? 'unknown'));
            }

            if ($fallbackReason === null && $profileValidation['checked'] === true && $profileValidation['supported'] === false) {
                $fallbackReason = 'missing_profile_capability';
            }

            return new AggregationPlan(
                aggregations: $aggregations,
                groupBy: $groupBy,
                filters: $filters,
                startDate: $startDate,
                endDate: $endDate,
                orderBy: $orderBy,
                orderDir: $orderDir,
                preferredExecutionPath: 'optimized',
                canUseOptimized: $canUseOptimized,
                fallbackReason: $fallbackReason,
                context: $context,
                stages: $stages,
                candidateOptimizedStrategies: $candidateOptimizedStrategies,
            );
        }

        /**
         * @param array<string, mixed> $filters
         * @param array<string, string> $aggregations
         * @return array<string, mixed>
         */
        private function evaluateProfileCapability(array $filters, ?string $groupPattern, array $aggregations, ?string $channel): array
        {
            if ($channel === null) {
                return [
                    'checked' => false,
                    'supported' => true,
                    'channel' => null,
                    'profile_count' => 0,
                    'matched_profiles' => [],
                    'failure_reason' => null,
                ];
            }

            $profiles = $this->aggregationProfileResolver->resolve($channel);
            if ($profiles === []) {
                // During rollout, absence of declared profiles should not force legacy fallback.
                return [
                    'checked' => true,
                    'supported' => true,
                    'channel' => $channel,
                    'profile_count' => 0,
                    'matched_profiles' => [],
                    'failure_reason' => 'no_profiles_registered',
                ];
            }

            $matchedProfiles = [];
            foreach ($profiles as $profile) {
                if ($this->profileSupportsRequest($profile, $groupPattern, $filters, $aggregations)) {
                    $matchedProfiles[] = (string)($profile['key'] ?? 'unknown');
                }
            }

            $supported = $matchedProfiles !== [];

            return [
                'checked' => true,
                'supported' => $supported,
                'channel' => $channel,
                'profile_count' => count($profiles),
                'matched_profiles' => $matchedProfiles,
                'failure_reason' => $supported ? null : 'no_matching_profile',
            ];
        }

        private function applyChannelSpecificFilters(array &$filters, ?string $groupPattern, ?string $channel): void
        {
            if ($channel === 'google_search_console') {
                $isGroupingBySearchAppearance = $groupPattern !== null && (
                    str_contains($groupPattern, 'dimensions.searchAppearance') || 
                    str_contains($groupPattern, 'searchAppearance')
                );
                $hasSearchAppearanceFilter = isset($filters['dimensions.searchAppearance']) || isset($filters['searchAppearance']);

                if (!$hasSearchAppearanceFilter) {
                    if (!$isGroupingBySearchAppearance) {
                        // Default to standard search appearance for normal queries
                        $filters['dimensions.searchAppearance'] = 'standard';
                    } else {
                        // Exclude standard when specifically grouping by search appearance
                        $filters['dimensions.searchAppearance'] = [
                            'operator' => 'neq',
                            'value' => 'standard'
                        ];
                    }
                }
            }
        }

        /**
         * @param array<string, mixed> $filters
         */
        private function resolveRequestedChannelKey(array $filters, BaseRepository $repository): ?string
        {
            $channelId = null;

            foreach (['channel', 'channel_key', 'channel_name'] as $field) {
                if (!array_key_exists($field, $filters)) {
                    continue;
                }

                $value = $filters[$field];
                if (is_object($value) && property_exists($value, 'value')) {
                    $value = $value->value;
                }

                if (is_scalar($value)) {
                    $normalized = strtolower(trim((string)$value));
                    if ($normalized !== '' && !ctype_digit($normalized)) {
                        return $normalized;
                    }

                    if ($normalized !== '' && ctype_digit($normalized)) {
                        $channelId = (int)$normalized;
                    }
                }
            }

            if ($channelId !== null && $channelId > 0) {
                return $this->resolveChannelKeyById($channelId);
            }

            // Fallback to repository inference
            $entityName = $repository->getClassName();
            if (str_contains($entityName, 'SearchConsole')) {
                return 'google_search_console';
            }
            if (str_contains($entityName, 'FacebookMarketing')) {
                return 'facebook_marketing';
            }
            if (str_contains($entityName, 'FacebookOrganic')) {
                return 'facebook_organic';
            }

            return null;
        }

        private function resolveChannelKeyById(int $channelId): ?string
        {
            if ($this->channelKeyResolver !== null) {
                $resolved = call_user_func($this->channelKeyResolver, $channelId);
                if (is_string($resolved)) {
                    $normalized = strtolower(trim($resolved));
                    return $normalized !== '' ? $normalized : null;
                }

                return null;
            }

            $channel = Channel::tryFrom($channelId);
            if (!$channel instanceof Channel) {
                return null;
            }

            $name = strtolower(trim($channel->getName()));
            return $name !== '' ? $name : null;
        }

        /**
         * @param array<string, mixed> $profile
         * @param array<string, mixed> $filters
         * @param array<string, string> $aggregations
         */
        private function profileSupportsRequest(array $profile, ?string $groupPattern, array $filters, array $aggregations): bool
        {
            if (!$this->profileSupportsGrouping($profile, $groupPattern)) {
                return false;
            }

            if (!$this->profileSupportsReducers($profile, $aggregations)) {
                return false;
            }

            return $this->profileSupportsFilters($profile, $filters);
        }

        /**
         * @param array<string, mixed> $profile
         */
        private function profileSupportsGrouping(array $profile, ?string $groupPattern): bool
        {
            if ($groupPattern === null) {
                return false;
            }

            $patterns = $profile['group_patterns'] ?? [];
            if (!is_array($patterns)) {
                return false;
            }

            $normalizedNeedle = strtolower($groupPattern);
            foreach ($patterns as $pattern) {
                $candidate = $this->normalizeProfilePattern($pattern);
                if ($candidate !== null && $candidate === $normalizedNeedle) {
                    return true;
                }
            }

            return false;
        }

        private function normalizeProfilePattern(mixed $pattern): ?string
        {
            if (is_string($pattern)) {
                $value = strtolower(trim($pattern));
                return $value !== '' ? $value : null;
            }

            if (!is_array($pattern)) {
                return null;
            }

            if ($pattern === []) {
                return 'none';
            }

            $normalized = [];
            foreach ($pattern as $field) {
                $value = strtolower(trim((string)$field));
                if ($value !== '') {
                    $normalized[] = $value;
                }
            }

            if ($normalized === []) {
                return null;
            }

            sort($normalized);
            return implode('+', $normalized);
        }

        /**
         * @param array<string, mixed> $profile
         * @param array<string, string> $aggregations
         */
        private function profileSupportsReducers(array $profile, array $aggregations): bool
        {
            $strategies = $profile['reducer_strategies'] ?? [];
            if (!is_array($strategies) || $strategies === []) {
                return true;
            }

            $normalizedStrategies = [];
            foreach ($strategies as $metric => $strategy) {
                $metricKey = strtolower(trim((string)$metric));
                $strategyValue = trim((string)$strategy);
                if ($metricKey === '' || $strategyValue === '') {
                    continue;
                }
                $normalizedStrategies[$metricKey] = $strategyValue;
            }

            if ($normalizedStrategies === []) {
                return true;
            }

            $hasWildcard = array_key_exists('*', $normalizedStrategies);
            foreach ($aggregations as $alias => $expression) {
                $aliasKey = strtolower(trim((string)$alias));
                $expressionKey = strtolower(trim((string)$expression));
                if ($hasWildcard || isset($normalizedStrategies[$aliasKey]) || isset($normalizedStrategies[$expressionKey])) {
                    continue;
                }

                return false;
            }

            return true;
        }

        /**
         * @param array<string, mixed> $profile
         * @param array<string, mixed> $filters
         */
        private function profileSupportsFilters(array $profile, array $filters): bool
        {
            $contract = $profile['filter_contract'] ?? [];
            if (!is_array($contract) || $contract === []) {
                return true;
            }

            $normalizedContract = [];
            foreach ($contract as $field => $operators) {
                $fieldKey = strtolower(trim((string)$field));
                if ($fieldKey === '') {
                    continue;
                }

                $ops = is_array($operators) ? $operators : [$operators];
                $normalizedOps = [];
                foreach ($ops as $op) {
                    $opValue = strtolower(trim((string)$op));
                    if ($opValue !== '' && !in_array($opValue, $normalizedOps, true)) {
                        $normalizedOps[] = $opValue;
                    }
                }

                if ($normalizedOps !== []) {
                    $normalizedContract[$fieldKey] = $normalizedOps;
                }
            }

            foreach ($filters as $field => $value) {
                $fieldKey = strtolower(trim((string)$field));
                if (!isset($normalizedContract[$fieldKey])) {
                    continue;
                }

                $operator = $this->resolveOperator($value);
                if (!in_array($operator, $normalizedContract[$fieldKey], true)) {
                    return false;
                }
            }

            return true;
        }

        private function resolveOperator(mixed $value): string
        {
            if (is_object($value)) {
                $op = strtolower(trim((string)($value->operator ?? 'eq')));
                if (in_array($op, ['not_equal', 'not_eq', '!=', '<>'], true)) {
                    return 'neq';
                }
                if (in_array($op, ['equal', 'eq', '='], true)) {
                    return 'eq';
                }
                return $op !== '' ? $op : 'eq';
            }

            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed === 'N/A' || $trimmed === 'NULL') {
                    return 'is_null';
                }
                if ($trimmed === 'NOT_NULL') {
                    return 'is_not_null';
                }
                if (str_starts_with($trimmed, '!=')) {
                    return 'neq';
                }
            }

            return 'eq';
        }


        /**
         * @param array<string, mixed> $filters
         * @param array<string, string> $aggregations
         * @param array<string, mixed> $reducerAnalysis
         * @return array<int, string>
         */
        private function buildCandidateOptimizedStrategies(
            bool $isChanneledMetric,
            bool $isMetric,
            ?string $groupPattern,
            array $filters,
            array $aggregations,
            array $reducerAnalysis,
            ?string $requestedPeriod,
            bool $snapshotDelta,
            ?string $startDate,
            ?string $endDate,
        ): array {
            $candidates = [];

            if ($isChanneledMetric) {
                if ($this->matchesFacebookOrganicPageSummary($groupPattern, $filters, $aggregations, $startDate, $endDate)) {
                    $candidates[] = 'facebook_organic_page_summary';
                }

                if ($this->matchesFacebookOrganicLinkedPages($groupPattern, $filters, $aggregations, $startDate, $endDate)) {
                    $candidates[] = 'facebook_organic_linked_pages';
                }

                if ($this->matchesFacebookOrganicPostSnapshot($groupPattern, $filters, $aggregations, $requestedPeriod, $snapshotDelta, $startDate, $endDate)) {
                    $candidates[] = 'facebook_organic_post_snapshot';
                }

                if ($this->matchesMarketingHierarchy($groupPattern, $filters, $aggregations, $startDate, $endDate)) {
                    $candidates[] = 'marketing_hierarchy';
                }
            }

            if (($isMetric || $isChanneledMetric) && $this->matchesWeightedMetric($groupPattern, $reducerAnalysis, $startDate, $endDate)) {
                $candidates[] = 'weighted_metric';
            }

            return array_values(array_unique($candidates));
        }

        /**
         * @return array<string, mixed>
         */
        private function normalizeFilters(?object $filters): array
        {
            if ($filters === null) {
                return [];
            }

            $normalized = [];
            foreach ($filters as $key => $value) {
                $normalized[(string)$key] = $value;
            }

            return $normalized;
        }

        /**
         * @param array<string, mixed> $filters
         * @return array<int, string>
         */
        private function collectFilterOperators(array $filters): array
        {
            $operators = [];
            foreach ($filters as $value) {
                if (is_object($value)) {
                    $operators[] = strtolower(trim((string)($value->operator ?? 'eq')));
                    continue;
                }

                if (is_string($value)) {
                    $trimmed = trim($value);
                    if ($trimmed === 'N/A' || $trimmed === 'NULL') {
                        $operators[] = 'is_null';
                        continue;
                    }
                    if ($trimmed === 'NOT_NULL') {
                        $operators[] = 'is_not_null';
                        continue;
                    }
                    if (str_starts_with($trimmed, '!=')) {
                        $operators[] = 'neq';
                        continue;
                    }
                }

                $operators[] = 'eq';
            }

            return array_values(array_unique($operators));
        }

        /**
         * @param array<string, string> $aggregations
         * @return array<string, mixed>
         */
        private function analyzeReducers(array $aggregations, bool $isMetricContext): array
        {
            $basicExpressions = [
                'clicks', 'impressions', 'ctr',
                'likes', 'comments', 'shares', 'saves', 'saved',
                'reach', 'views', 'spend', 'frequency', 'cpc', 'cpm',
                'results', 'cost_per_result', 'result_rate', 'purchase_roas',
                'actions', 'profile_views', 'website_clicks', 'profile_links_taps',
                'follows_and_unfollows', 'replies', 'accounts_engaged',
                'total_interactions', 'engagement', 'video_views', 'page_fans',
                'follower_count', 'profile_activity', 'profile_visits', 'reposts',
                'ig_reels_avg_watch_time', 'ig_reels_video_view_total_time', 'follows',
            ];

            $weightedMetricExpressions = [];
            $missingReducerExpressions = [];
            foreach ($aggregations as $expression) {
                $normalizedExpr = strtolower(trim((string)$expression));
                $weightedStrategy = MetricAggregationStrategyRegistry::resolve($normalizedExpr);
                if ($weightedStrategy !== null) {
                    $weightedMetricExpressions[] = $normalizedExpr;
                    continue;
                }

                if ($isMetricContext && !in_array($normalizedExpr, $basicExpressions, true)) {
                    $missingReducerExpressions[] = $normalizedExpr;
                }
            }

            return [
                'weighted_metric_expressions' => array_values(array_unique($weightedMetricExpressions)),
                'missing_reducer_expressions' => array_values(array_unique($missingReducerExpressions)),
            ];
        }

        /**
         * @param array<string, mixed> $filters
         * @param array<string, string> $aggregations
         */
        private function matchesFacebookOrganicPageSummary(?string $groupPattern, array $filters, array $aggregations, ?string $startDate, ?string $endDate): bool
        {
            if ($groupPattern !== 'page+page_id+page_title' || $startDate === null || $endDate === null) {
                return false;
            }

            if (strtolower(trim((string)($filters['account_type'] ?? ''))) !== 'facebook_page') {
                return false;
            }

            if (trim((string)($filters['page_platform_id'] ?? '')) === '') {
                return false;
            }

            return $this->allAggregationExpressionsIn($aggregations, [
                'likes', 'comments', 'reach', 'views', 'profile_views', 'website_clicks',
                'profile_links_taps', 'follows_and_unfollows', 'saves', 'shares',
                'total_interactions', 'replies', 'accounts_engaged',
            ]);
        }

        /**
         * @param array<string, mixed> $filters
         * @param array<string, string> $aggregations
         */
        private function matchesFacebookOrganicLinkedPages(?string $groupPattern, array $filters, array $aggregations, ?string $startDate, ?string $endDate): bool
        {
            if ($groupPattern !== 'channeled_account_id+channeledaccount+linked_fb_page_id+page_platform_id' || $startDate === null || $endDate === null) {
                return false;
            }

            if (strtolower(trim((string)($filters['account_type'] ?? ''))) !== 'instagram_account') {
                return false;
            }

            return $this->allAggregationExpressionsIn($aggregations, [
                'likes', 'comments', 'reach', 'views', 'profile_views', 'website_clicks',
                'profile_links_taps', 'follows_and_unfollows', 'saves', 'shares',
                'total_interactions', 'replies', 'accounts_engaged',
            ]);
        }

        /**
         * @param array<string, mixed> $filters
         * @param array<string, string> $aggregations
         */
        private function matchesFacebookOrganicPostSnapshot(
            ?string $groupPattern,
            array $filters,
            array $aggregations,
            ?string $requestedPeriod,
            bool $snapshotDelta,
            ?string $startDate,
            ?string $endDate,
        ): bool {
            if ($groupPattern !== 'caption+created_time+media_type+message+permalink+permalink_url+post+post_id+timestamp' || $startDate === null || $endDate === null) {
                return false;
            }

            if ($snapshotDelta) {
                return false;
            }

            if (strtolower(trim((string)($filters['account_type'] ?? ''))) !== 'instagram_account') {
                return false;
            }

            if (!is_numeric((string)($filters['channeledAccount'] ?? null))) {
                return false;
            }

            if (strtoupper(trim((string)($filters['post'] ?? ''))) !== 'NOT_NULL') {
                return false;
            }

            if (($requestedPeriod ?? '') !== 'lifetime') {
                return false;
            }

            $latestSnapshot = filter_var($filters['latest_snapshot'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $latestSnapshot = $latestSnapshot ?? (bool)($filters['latest_snapshot'] ?? false);
            if (!$latestSnapshot) {
                return false;
            }

            return $this->allAggregationExpressionsIn($aggregations, [
                'comments', 'follows', 'ig_reels_avg_watch_time', 'ig_reels_video_view_total_time',
                'likes', 'profile_activity', 'profile_visits', 'reach', 'reposts', 'saved',
                'shares', 'total_interactions', 'views',
            ]);
        }

        /**
         * @param array<string, mixed> $filters
         * @param array<string, string> $aggregations
         */
        private function matchesMarketingHierarchy(?string $groupPattern, array $filters, array $aggregations, ?string $startDate, ?string $endDate): bool
        {
            if ($startDate === null || $endDate === null) {
                return false;
            }

            if (!in_array($groupPattern, [
                'gender', 'age', 'age+gender', 'ad+ad_id', 'adgroup+adgroup_id',
                'daily+gender', 'age+daily', 'age+daily+gender', 'ad+ad_id+daily', 'adgroup+adgroup_id+daily',
            ], true)) {
                return false;
            }

            if (!isset($filters['channeledCampaign']) && !isset($filters['adGroup']) && !isset($filters['ad'])) {
                return false;
            }

            return $this->allAggregationExpressionsIn($aggregations, [
                'spend', 'clicks', 'impressions', 'reach', 'frequency', 'ctr', 'cpc', 'cpm',
                'results', 'cost_per_result', 'result_rate', 'purchase_roas', 'actions',
            ]);
        }

        /**
         * @param array<string, mixed> $reducerAnalysis
         */
        private function matchesWeightedMetric(?string $groupPattern, array $reducerAnalysis, ?string $startDate, ?string $endDate): bool
        {
            if ($startDate === null || $endDate === null || $groupPattern === null) {
                return false;
            }

            if (($reducerAnalysis['weighted_metric_expressions'] ?? []) !== []) {
                return true;
            }

            return $groupPattern === 'daily+channeledCampaign';
        }

        /**
         * @param array<string, string> $aggregations
         * @param array<int, string> $allowedExpressions
         */
        private function allAggregationExpressionsIn(array $aggregations, array $allowedExpressions): bool
        {
            $allowed = array_map(static fn(string $value): string => strtolower(trim($value)), $allowedExpressions);

            foreach ($aggregations as $expression) {
                if (!in_array(strtolower(trim((string)$expression)), $allowed, true)) {
                    return false;
                }
            }

            return true;
        }

        /**
         * @param array<int, string> $unsupportedFilterOperators
         * @param array<string, mixed> $reducerAnalysis
         * @param array<int, string> $groupBy
         */
        private function resolveFallbackReason(
            bool    $canUseOptimized,
            array   $unsupportedFilterOperators,
            array   $reducerAnalysis,
            ?string $groupPattern,
            array   $groupBy,
        ): ?string
        {
            if (!$canUseOptimized) {
                return 'unsupported_entity_type';
            }

            if ($unsupportedFilterOperators !== []) {
                return 'unsupported_filter_operator';
            }

            if (($reducerAnalysis['missing_reducer_expressions'] ?? []) !== []) {
                return 'missing_reducer_strategy';
            }

            if ($groupBy !== [] && $groupPattern === null) {
                return 'unsupported_group_pattern';
            }

            return null;
        }

        /**
         * @param array<int, string> $groupBy
         */
        private function resolveGroupPattern(array $groupBy): ?string
        {
            if ($groupBy === []) {
                return 'none';
            }

            $rawFields = array_values(array_map(static fn($field) => trim((string)$field), $groupBy));
            $normalized = array_values(array_map(static fn($field) => strtolower($field), $rawFields));

            if (count($normalized) === 1) {
                $field = $normalized[0];
                $rawField = $rawFields[0];
                if (in_array($field, ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'], true)) {
                    return $field;
                }

                if (in_array($field, ['query', 'page', 'country', 'device', 'age', 'gender', 'account', 'campaign', 'ad', 'adgroup'], true)) {
                    return $field;
                }

                if (str_starts_with(strtolower($rawField), 'dimensions.') && strlen($rawField) > 11) {
                    return 'dimensions.'.substr($rawField, 11);
                }
            }

            $allDimensions = array_reduce($rawFields, static fn(bool $carry, string $field): bool => $carry && str_starts_with(strtolower($field), 'dimensions.'), true);
            if ($allDimensions) {
                $dimensionFields = array_map(static fn(string $field): string => 'dimensions.'.substr($field, 11), $rawFields);
                usort($dimensionFields, static fn(string $left, string $right): int => strcmp(strtolower($left), strtolower($right)));

                return implode('+', $dimensionFields);
            }

            $knownFields = [
                'query', 'page', 'page_id', 'page_title', 'page_platform_id',
                'country', 'device', 'daily', 'age', 'gender',
                'ad', 'ad_id', 'adgroup', 'adgroup_id', 'account', 'campaign',
                'channeledaccount', 'channeled_account_id', 'linked_fb_page_id',
                'caption', 'created_time', 'media_type', 'message', 'permalink',
                'permalink_url', 'post', 'post_id', 'timestamp',
            ];
            $allKnown = array_reduce($normalized, static fn(bool $carry, string $field): bool => $carry && in_array($field, $knownFields, true), true);
            if ($allKnown) {
                sort($normalized);

                return implode('+', $normalized);
            }

            return null;
        }

        /**
         * @param array<int, string> $groupBy
         */
        private function hasTemporalGrouping(array $groupBy): bool
        {
            foreach ($groupBy as $field) {
                if (in_array(strtolower($field), ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'], true)) {
                    return true;
                }
            }

            return false;
        }
    }

