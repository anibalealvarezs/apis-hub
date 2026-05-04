<?php

    declare(strict_types=1);

    namespace Services\Aggregation;

    use Anibalealvarezs\ApiDriverCore\Classes\MetricAggregationStrategyRegistry;
    use Exceptions\ConfigurationException;
    use Helpers\Helpers;
    use Repositories\BaseRepository;

    final class AggregationPlanner
    {
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

            $fallbackReason = $this->resolveFallbackReason(
                canUseOptimized: $canUseOptimized,
                unsupportedFilterOperators: $unsupportedFilterOperators,
                reducerAnalysis: $reducerAnalysis,
                groupPattern: $groupPattern,
                groupBy: $groupBy,
            );

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

            return ($reducerAnalysis['weighted_metric_expressions'] ?? []) !== [];
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

