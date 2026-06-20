<?php

    declare(strict_types=1);

    namespace Services\Aggregation;

    use Anibalealvarezs\ApiDriverCore\Classes\CanonicalMetricDefinitionRegistry;
    use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
    use Anibalealvarezs\ApiDriverCore\Interfaces\CanonicalMetricDictionaryProviderInterface;
    use Helpers\Helpers;
    use Symfony\Component\Yaml\Yaml;

    final class CanonicalMetricSqlResolver
    {
        /**
         * @var array<int, string>
         */
        private const array SUPPORTED_CANONICAL_METRICS = [
            'spend', 'clicks', 'impressions', 'reach', 'conversions',
            'sessions', 'new_users',
            'frequency', 'ctr', 'cpc', 'cpm', 'cost_per_conversion', 'conversion_rate', 'roas_purchase',
        ];

        /**
         * @var array<int, string>
         */
        private const array SUPPORTED_DEPRECATED_LEGACY_METRICS = [
            'actions',
        ];

        /**
         * @var array<int, string>
         */
        private const array SUPPORTED_ORGANIC_METRICS = [
            'likes', 'comments', 'content_views', 'views', 'profile_views', 'website_clicks',
            'profile_links_taps', 'follows_and_unfollows', 'saves', 'shares',
            'total_interactions', 'replies', 'accounts_engaged', 'post_clicks',
            'ig_reels_avg_watch_time', 'ig_reels_video_view_total_time',
            'profile_activity', 'profile_visits', 'reposts', 'follows',
            'reach', 'page_views_total', 'video_views', 'post_video_avg_time_watched'
        ];

        /**
         * @var array<int, string>
         */
        private const array SUPPORTED_SEARCH_METRICS = [
            'clicks', 'impressions', 'ctr', 'position'
        ];

        /** @var callable|null */
        private $projectConfigResolver;

        /** @var callable|null */
        private $driverDictionaryResolver;

        /** @var callable|null */
        private $driverRegistryResolver;

        /**
         * @var array<string, array<string, array<int, string>>>
         */
        private const array DEFAULT_MARKETING_DICTIONARY = [
            '__default__'        => [
                'spend'         => ['spend', 'spend_daily'],
                'clicks'        => ['clicks', 'clicks_daily'],
                'impressions'   => ['impressions', 'impressions_daily'],
                'reach'         => ['reach', 'reach_daily'],
                'frequency'     => ['frequency', 'frequency_daily'],
                'conversions'   => ['results', 'results_daily'],
                'roas_purchase' => ['purchase_roas', 'purchase_roas_daily'],
            ],
        ];

        /**
         * @var array<string, array<string, array<int, string>>>
         */
        private const array DEFAULT_ORGANIC_DICTIONARY = [
            '__default__'      => [
                'likes'                          => ['likes', 'likes_daily'],
                'comments'                       => ['comments', 'comments_daily'],
                'content_views'                  => ['content_views', 'content_views_daily'],
                'shares'                         => ['shares', 'shares_daily'],
                'reach'                          => ['reach', 'reach_daily'],
                'views'                          => ['views', 'views_daily'],
                'conversions'                    => ['results', 'results_daily'],
                'follows'                        => ['follows', 'follows_daily'],
                'follows_and_unfollows'          => ['follows_and_unfollows', 'follows_and_unfollows_daily'],
                'ig_reels_avg_watch_time'        => ['ig_reels_avg_watch_time', 'ig_reels_avg_watch_time_daily'],
                'ig_reels_video_view_total_time' => ['ig_reels_video_view_total_time', 'ig_reels_video_view_total_time_daily'],
                'profile_activity'               => ['profile_activity', 'profile_activity_daily'],
                'profile_visits'                 => ['profile_visits', 'profile_visits_daily'],
                'reposts'                        => ['reposts', 'reposts_daily'],
                'saved'                          => ['saved', 'saved_daily'],
                'total_interactions'             => ['total_interactions', 'total_interactions_daily'],
            ],
        ];

        /**
         * @var array<string, array<string, array<int, string>>>
         */
        private const array DEFAULT_SEARCH_DICTIONARY = [
            '__default__'           => [
                'clicks'      => ['clicks'],
                'impressions' => ['impressions'],
                'ctr'         => ['ctr'],
                'position'    => ['position'],
            ],
        ];

        public function __construct(
            ?callable $projectConfigResolver = null,
            ?callable $driverDictionaryResolver = null,
            ?callable $driverRegistryResolver = null,
        )
        {
            $this->projectConfigResolver = $projectConfigResolver;
            $this->driverDictionaryResolver = $driverDictionaryResolver;
            $this->driverRegistryResolver = $driverRegistryResolver;
        }

        /**
         * @return array<string, mixed>
         */
        public function resolveMarketingMetric(
            string  $requestedMetric,
            ?string $channel,
            string  $nameCol,
            string  $periodCol
        ): array
        {
            $normalizedRequested = strtolower(trim($requestedMetric));
            if ($normalizedRequested === '') {
                return [
                    'requested_metric' => '',
                    'canonical_metric' => null,
                    'input_type'       => 'unknown',
                    'legacy_alias_of'  => null,
                    'deprecation'      => null,
                    'raw_names'        => [],
                    'source'           => 'none',
                    'sql_expression'   => null,
                ];
            }

            $inputMeta = CanonicalMetricDefinitionRegistry::resolveInput($normalizedRequested);
            $canonicalMetric = $inputMeta['canonical_metric'];
            $resolutionMetric = $canonicalMetric;

            if ($resolutionMetric === null && in_array($normalizedRequested, self::SUPPORTED_DEPRECATED_LEGACY_METRICS, true)) {
                $resolutionMetric = $normalizedRequested;
            }

            if ($resolutionMetric === null || !in_array($resolutionMetric, array_merge(self::SUPPORTED_CANONICAL_METRICS, self::SUPPORTED_DEPRECATED_LEGACY_METRICS), true)) {
                return [
                    'requested_metric' => $normalizedRequested,
                    'canonical_metric' => $canonicalMetric,
                    'input_type'       => $this->resolveInputType($inputMeta, $normalizedRequested),
                    'legacy_alias_of'  => $inputMeta['alias_target'],
                    'deprecation'      => $inputMeta['deprecation'],
                    'raw_names'        => [],
                    'source'           => 'none',
                    'sql_expression'   => null,
                ];
            }

            $resolvedNames = $this->resolveRawMetricNames($resolutionMetric, $channel);

            $sqlExpression = match ($resolutionMetric) {
                'spend', 'clicks', 'impressions', 'reach', 'conversions', 'actions', 'sessions', 'new_users' =>
                $this->buildSumExpression($resolvedNames['raw_names'], $nameCol, $periodCol),
                'frequency', 'roas_purchase' =>
                $this->buildAverageExpression($resolvedNames['raw_names'], $nameCol, $periodCol),
                'ctr' => $this->buildCtrExpression($channel, $nameCol, $periodCol),
                'cpc' => $this->buildCpcExpression($channel, $nameCol, $periodCol),
                'cpm' => $this->buildCpmExpression($channel, $nameCol, $periodCol),
                'cost_per_conversion' => $this->buildCostPerConversionExpression($channel, $nameCol, $periodCol),
                'conversion_rate' => $this->buildConversionRateExpression($channel, $nameCol, $periodCol),
                default => null,
            };

            return [
                'requested_metric' => $normalizedRequested,
                'canonical_metric' => $canonicalMetric,
                'input_type'       => $this->resolveInputType($inputMeta, $normalizedRequested),
                'legacy_alias_of'  => $inputMeta['alias_target'],
                'deprecation'      => $inputMeta['deprecation'],
                'raw_names'        => $resolvedNames['raw_names'],
                'source'           => $resolvedNames['source'],
                'sql_expression'   => $sqlExpression,
            ];
        }

        /**
         * @return array<string, mixed>
         */
        public function resolveOrganicMetric(
            string  $requestedMetric,
            ?string $channel,
            string  $nameCol,
            string  $periodCol,
            string  $period = 'daily'
        ): array
        {
            $normalizedRequested = strtolower(trim($requestedMetric));
            if ($normalizedRequested === '') {
                return [
                    'requested_metric' => '',
                    'canonical_metric' => null,
                    'input_type'       => 'unknown',
                    'legacy_alias_of'  => null,
                    'deprecation'      => null,
                    'raw_names'        => [],
                    'source'           => 'none',
                    'sql_expression'   => null,
                ];
            }

            $inputMeta = CanonicalMetricDefinitionRegistry::resolveInput($normalizedRequested);
            $canonicalMetric = $inputMeta['canonical_metric'];
            $resolutionMetric = $canonicalMetric;

            if ($resolutionMetric === null && in_array($normalizedRequested, self::SUPPORTED_DEPRECATED_LEGACY_METRICS, true)) {
                $resolutionMetric = $normalizedRequested;
            }

            if ($resolutionMetric === null || !in_array($resolutionMetric, array_merge(self::SUPPORTED_ORGANIC_METRICS, self::SUPPORTED_DEPRECATED_LEGACY_METRICS), true)) {
                return [
                    'requested_metric' => $normalizedRequested,
                    'canonical_metric' => $canonicalMetric,
                    'input_type'       => $this->resolveInputType($inputMeta, $normalizedRequested),
                    'legacy_alias_of'  => $inputMeta['alias_target'],
                    'deprecation'      => $inputMeta['deprecation'],
                    'raw_names'        => [],
                    'source'           => 'none',
                    'sql_expression'   => null,
                ];
            }

            $resolvedNames = $this->resolveRawMetricNamesOrganic($resolutionMetric, $channel);

            $sqlExpression = match ($resolutionMetric) {
                'likes', 'comments', 'content_views', 'views', 'page_views_total', 'video_views', 'profile_views', 'website_clicks',
                'profile_links_taps', 'follows_and_unfollows', 'saves', 'shares',
                'total_interactions', 'replies', 'accounts_engaged', 'post_clicks',
                'ig_reels_avg_watch_time', 'ig_reels_video_view_total_time',
                'profile_activity', 'profile_visits', 'reposts', 'follows', 'reach', 'post_video_avg_time_watched' =>
                $this->buildSumExpression($resolvedNames['raw_names'], $nameCol, $periodCol, $period),
                default => null,
            };

            return [
                'requested_metric' => $normalizedRequested,
                'canonical_metric' => $canonicalMetric,
                'input_type'       => $this->resolveInputType($inputMeta, $normalizedRequested),
                'legacy_alias_of'  => $inputMeta['alias_target'],
                'deprecation'      => $inputMeta['deprecation'],
                'raw_names'        => $resolvedNames['raw_names'],
                'source'           => $resolvedNames['source'],
                'sql_expression'   => $sqlExpression,
            ];
        }

        /**
         * @return array<string, mixed>
         */
        public function resolveSearchMetric(
            string  $requestedMetric,
            ?string $channel
        ): array
        {
            $normalizedRequested = strtolower(trim($requestedMetric));
            if ($normalizedRequested === '') {
                return [
                    'requested_metric' => '',
                    'canonical_metric' => null,
                    'input_type'       => 'unknown',
                    'legacy_alias_of'  => null,
                    'deprecation'      => null,
                    'raw_names'        => [],
                    'source'           => 'none',
                    'sql_expression'   => null,
                ];
            }

            $inputMeta = CanonicalMetricDefinitionRegistry::resolveInput($normalizedRequested);
            $canonicalMetric = $inputMeta['canonical_metric'];
            $resolutionMetric = $canonicalMetric;

            if ($resolutionMetric === null && in_array($normalizedRequested, self::SUPPORTED_DEPRECATED_LEGACY_METRICS, true)) {
                $resolutionMetric = $normalizedRequested;
            }

            if ($resolutionMetric === null || !in_array($resolutionMetric, array_merge(self::SUPPORTED_SEARCH_METRICS, self::SUPPORTED_DEPRECATED_LEGACY_METRICS), true)) {
                return [
                    'requested_metric' => $normalizedRequested,
                    'canonical_metric' => $canonicalMetric,
                    'input_type'       => $this->resolveInputType($inputMeta, $normalizedRequested),
                    'legacy_alias_of'  => $inputMeta['alias_target'],
                    'deprecation'      => $inputMeta['deprecation'],
                    'raw_names'        => [],
                    'source'           => 'none',
                    'sql_expression'   => null,
                ];
            }

            $resolvedNames = $this->resolveRawMetricNamesSearch($resolutionMetric, $channel);

            return [
                'requested_metric' => $normalizedRequested,
                'canonical_metric' => $canonicalMetric,
                'input_type'       => $this->resolveInputType($inputMeta, $normalizedRequested),
                'legacy_alias_of'  => $inputMeta['alias_target'],
                'deprecation'      => $inputMeta['deprecation'],
                'raw_names'        => $resolvedNames['raw_names'],
                'source'           => $resolvedNames['source'],
                'sql_expression'   => null,
            ];
        }

        public function resolveMarketingMetricExpression(
            string  $requestedMetric,
            ?string $channel,
            string  $nameCol,
            string  $periodCol
        ): ?string
        {
            $resolved = $this->resolveMarketingMetric($requestedMetric, $channel, $nameCol, $periodCol);

            return is_string($resolved['sql_expression']) ? $resolved['sql_expression'] : null;
        }

        public function resolveOrganicMetricExpression(
            string  $requestedMetric,
            ?string $channel,
            string  $nameCol,
            string  $periodCol,
            string  $period = 'daily'
        ): ?string
        {
            $resolved = $this->resolveOrganicMetric($requestedMetric, $channel, $nameCol, $periodCol, $period);

            return is_string($resolved['sql_expression']) ? $resolved['sql_expression'] : null;
        }

        private function buildCtrExpression(?string $channel, string $nameCol, string $periodCol): ?string
        {
            $clicksSql = $this->buildSumExpression($this->resolveRawMetricNames('clicks', $channel)['raw_names'], $nameCol, $periodCol);
            $impressionsSql = $this->buildSumExpression($this->resolveRawMetricNames('impressions', $channel)['raw_names'], $nameCol, $periodCol);
            if ($clicksSql === null || $impressionsSql === null) {
                return null;
            }

            return "$clicksSql / NULLIF($impressionsSql, 0)";
        }

        private function buildCpcExpression(?string $channel, string $nameCol, string $periodCol): ?string
        {
            $spendSql = $this->buildSumExpression($this->resolveRawMetricNames('spend', $channel)['raw_names'], $nameCol, $periodCol);
            $clicksSql = $this->buildSumExpression($this->resolveRawMetricNames('clicks', $channel)['raw_names'], $nameCol, $periodCol);
            if ($spendSql === null || $clicksSql === null) {
                return null;
            }

            return "$spendSql / NULLIF($clicksSql, 0)";
        }

        private function buildCpmExpression(?string $channel, string $nameCol, string $periodCol): ?string
        {
            $spendSql = $this->buildSumExpression($this->resolveRawMetricNames('spend', $channel)['raw_names'], $nameCol, $periodCol);
            $impressionsSql = $this->buildSumExpression($this->resolveRawMetricNames('impressions', $channel)['raw_names'], $nameCol, $periodCol);
            if ($spendSql === null || $impressionsSql === null) {
                return null;
            }

            return "$spendSql / NULLIF($impressionsSql, 0) * 1000";
        }

        private function buildCostPerConversionExpression(?string $channel, string $nameCol, string $periodCol): ?string
        {
            $spendSql = $this->buildSumExpression($this->resolveRawMetricNames('spend', $channel)['raw_names'], $nameCol, $periodCol);
            $conversionSql = $this->buildSumExpression($this->resolveRawMetricNames('conversions', $channel)['raw_names'], $nameCol, $periodCol);
            if ($spendSql === null || $conversionSql === null) {
                return null;
            }

            return "$spendSql / NULLIF($conversionSql, 0)";
        }

        private function buildConversionRateExpression(?string $channel, string $nameCol, string $periodCol): ?string
        {
            $conversionSql = $this->buildSumExpression($this->resolveRawMetricNames('conversions', $channel)['raw_names'], $nameCol, $periodCol);
            $impressionsSql = $this->buildSumExpression($this->resolveRawMetricNames('impressions', $channel)['raw_names'], $nameCol, $periodCol);
            if ($conversionSql === null || $impressionsSql === null) {
                return null;
            }

            return "$conversionSql / NULLIF($impressionsSql, 0)";
        }

        /**
         * @param array<int, string> $rawNames
         */
        private function buildSumExpression(array $rawNames, string $nameCol, string $periodCol, string $period = 'daily'): ?string
        {
            if ($rawNames === []) {
                return null;
            }

            return "SUM(CASE WHEN $nameCol IN (".$this->toSqlStringList($rawNames).") AND $periodCol = '$period' THEN m.value ELSE 0 END)";
        }

        /**
         * @param array<int, string> $rawNames
         */
        private function buildAverageExpression(array $rawNames, string $nameCol, string $periodCol, string $period = 'daily'): ?string
        {
            if ($rawNames === []) {
                return null;
            }

            return "AVG(CASE WHEN $nameCol IN (".$this->toSqlStringList($rawNames).") AND $periodCol = '$period' THEN m.value END)";
        }

        /**
         * @return array{raw_names:array<int,string>, source:string}
         */
        private function resolveRawMetricNames(string $canonicalMetric, ?string $channel): array
        {
            $normalizedChannel = strtolower(trim((string)$channel));
            $normalizedMetric = strtolower(trim($canonicalMetric));
            if ($normalizedMetric === '') {
                return ['raw_names' => [], 'source' => 'none'];
            }

            $overrides = $this->resolveMarketingOverrides();
            $driverDictionary = $this->resolveDriverMarketingDictionary($normalizedChannel);

            $candidates = [];
            if ($normalizedChannel !== '') {
                $candidates[] = ['source' => 'override', 'dictionary' => $overrides[$normalizedChannel] ?? null];
                $candidates[] = ['source' => 'driver', 'dictionary' => $driverDictionary[$normalizedChannel] ?? null];
                $candidates[] = ['source' => 'default', 'dictionary' => self::DEFAULT_MARKETING_DICTIONARY[$normalizedChannel] ?? null];
            }
            $candidates[] = ['source' => 'override', 'dictionary' => $overrides['__default__'] ?? null];
            $candidates[] = ['source' => 'driver', 'dictionary' => $driverDictionary['__default__'] ?? null];
            $candidates[] = ['source' => 'default', 'dictionary' => self::DEFAULT_MARKETING_DICTIONARY['__default__'] ?? null];

            foreach ($candidates as $dictionary) {
                $rawDictionary = $dictionary['dictionary'] ?? null;
                if (!is_array($rawDictionary)) {
                    continue;
                }

                $rawNames = $rawDictionary[$normalizedMetric] ?? null;
                if ($rawNames === null) {
                    continue;
                }

                $list = is_array($rawNames) ? $rawNames : [$rawNames];
                $normalized = [];
                foreach ($list as $name) {
                    $value = strtolower(trim((string)$name));
                    if ($value !== '' && !in_array($value, $normalized, true)) {
                        $normalized[] = $value;
                    }
                }

                if ($normalized !== []) {
                    return ['raw_names' => $normalized, 'source' => (string)$dictionary['source']];
                }
            }

            return ['raw_names' => [], 'source' => 'none'];
        }

        /**
         * @return array{raw_names:array<int,string>, source:string}
         */
        private function resolveRawMetricNamesOrganic(string $canonicalMetric, ?string $channel): array
        {
            $normalizedChannel = strtolower(trim((string)$channel));
            $normalizedMetric = strtolower(trim($canonicalMetric));
            if ($normalizedMetric === '') {
                return ['raw_names' => [], 'source' => 'none'];
            }

            $overrides = $this->resolveOrganicOverrides();
            $driverDictionary = $this->resolveDriverMarketingDictionary($normalizedChannel);

            $candidates = [];
            if ($normalizedChannel !== '') {
                $candidates[] = ['source' => 'override', 'dictionary' => $overrides[$normalizedChannel] ?? null];
                $candidates[] = ['source' => 'driver', 'dictionary' => $driverDictionary[$normalizedChannel] ?? null];
                $candidates[] = ['source' => 'default', 'dictionary' => self::DEFAULT_ORGANIC_DICTIONARY[$normalizedChannel] ?? null];
            }
            $candidates[] = ['source' => 'override', 'dictionary' => $overrides['__default__'] ?? null];
            $candidates[] = ['source' => 'driver', 'dictionary' => $driverDictionary['__default__'] ?? null];
            $candidates[] = ['source' => 'default', 'dictionary' => self::DEFAULT_ORGANIC_DICTIONARY['__default__'] ?? null];

            foreach ($candidates as $dictionary) {
                $rawDictionary = $dictionary['dictionary'] ?? null;
                if (!is_array($rawDictionary)) {
                    continue;
                }

                $rawNames = $rawDictionary[$normalizedMetric] ?? null;
                if ($rawNames === null) {
                    continue;
                }

                $list = is_array($rawNames) ? $rawNames : [$rawNames];
                $normalized = [];
                foreach ($list as $name) {
                    $value = strtolower(trim((string)$name));
                    if ($value !== '' && !in_array($value, $normalized, true)) {
                        $normalized[] = $value;
                    }
                }

                if ($normalized !== []) {
                    return ['raw_names' => $normalized, 'source' => (string)$dictionary['source']];
                }
            }

            return ['raw_names' => [], 'source' => 'none'];
        }

        /**
         * @return array{raw_names:array<int,string>, source:string}
         */
        private function resolveRawMetricNamesSearch(string $canonicalMetric, ?string $channel): array
        {
            $normalizedChannel = strtolower(trim((string)$channel));
            $normalizedMetric = strtolower(trim($canonicalMetric));
            if ($normalizedMetric === '') {
                return ['raw_names' => [], 'source' => 'none'];
            }

            // Fallback to marketing overrides for now or we could add search_hierarchy later
            $overrides = $this->resolveMarketingOverrides();
            $driverDictionary = $this->resolveDriverMarketingDictionary($normalizedChannel);

            $candidates = [];
            if ($normalizedChannel !== '') {
                $candidates[] = ['source' => 'override', 'dictionary' => $overrides[$normalizedChannel] ?? null];
                $candidates[] = ['source' => 'driver', 'dictionary' => $driverDictionary[$normalizedChannel] ?? null];
                $candidates[] = ['source' => 'default', 'dictionary' => self::DEFAULT_SEARCH_DICTIONARY[$normalizedChannel] ?? null];
            }
            $candidates[] = ['source' => 'override', 'dictionary' => $overrides['__default__'] ?? null];
            $candidates[] = ['source' => 'driver', 'dictionary' => $driverDictionary['__default__'] ?? null];
            $candidates[] = ['source' => 'default', 'dictionary' => self::DEFAULT_SEARCH_DICTIONARY['__default__'] ?? null];

            foreach ($candidates as $dictionary) {
                $rawDictionary = $dictionary['dictionary'] ?? null;
                if (!is_array($rawDictionary)) {
                    continue;
                }

                $rawNames = $rawDictionary[$normalizedMetric] ?? null;
                if ($rawNames === null) {
                    continue;
                }

                $list = is_array($rawNames) ? $rawNames : [$rawNames];
                $normalized = [];
                foreach ($list as $name) {
                    $value = strtolower(trim((string)$name));
                    if ($value !== '' && !in_array($value, $normalized, true)) {
                        $normalized[] = $value;
                    }
                }

                if ($normalized !== []) {
                    return ['raw_names' => $normalized, 'source' => (string)$dictionary['source']];
                }
            }

            return ['raw_names' => [], 'source' => 'none'];
        }

        /**
         * @return array<string, array<string, array<int, string>|string>>
         */
        private function resolveDriverMarketingDictionary(string $channel): array
        {
            if ($channel === '') {
                return [];
            }

            if ($this->driverDictionaryResolver !== null) {
                $resolved = call_user_func($this->driverDictionaryResolver, $channel);
                if (is_array($resolved)) {
                    return $this->normalizeDictionary([$channel => $resolved]);
                }
            }

            // Use injected registry resolver first (allows test overrides), then DriverFactory cache,
            // then fall back to local YAML file
            if ($this->driverRegistryResolver !== null) {
                $registry = call_user_func($this->driverRegistryResolver);
                $registry = is_array($registry) ? $registry : [];
            } else {
                $registry = DriverFactory::getRegistry();
                if ($registry === []) {
                    $registry = $this->resolveLocalDriverRegistry();
                }
            }

            $driverClass = $registry[$channel]['driver'] ?? null;
            if (!is_string($driverClass) || !class_exists($driverClass)) {
                return [];
            }

            if (!is_subclass_of($driverClass, CanonicalMetricDictionaryProviderInterface::class)) {
                return [];
            }

            return $this->normalizeDictionary([
                $channel => $driverClass::getCanonicalMetricDictionary(),
            ]);
        }

        /**
         * @return array<string, array<string, array<int, string>|string>>
         */
        private function resolveMarketingOverrides(): array
        {
            $projectConfig = $this->resolveProjectConfig();

            $section = $projectConfig['aggregation']['metric_equivalences']['marketing_hierarchy'] ?? [];
            if (!is_array($section)) {
                return [];
            }

            $normalized = [];
            foreach ($section as $channel => $dictionary) {
                $channelKey = strtolower(trim((string)$channel));
                if ($channelKey === '' || !is_array($dictionary)) {
                    continue;
                }

                $normalized[$channelKey] = [];
                foreach ($dictionary as $metric => $rawNames) {
                    $metricKey = strtolower(trim((string)$metric));
                    if ($metricKey === '') {
                        continue;
                    }
                    $normalized[$channelKey][$metricKey] = $rawNames;
                }
            }

            return $normalized;
        }

        /**
         * @return array<string, array<string, array<int, string>|string>>
         */
        private function resolveOrganicOverrides(): array
        {
            $projectConfig = $this->resolveProjectConfig();

            $section = $projectConfig['aggregation']['metric_equivalences']['organic_hierarchy'] ?? [];
            if (!is_array($section)) {
                return [];
            }

            $normalized = [];
            foreach ($section as $channel => $dictionary) {
                $channelKey = strtolower(trim((string)$channel));
                if ($channelKey === '' || !is_array($dictionary)) {
                    continue;
                }

                $normalized[$channelKey] = [];
                foreach ($dictionary as $metric => $rawNames) {
                    $metricKey = strtolower(trim((string)$metric));
                    if ($metricKey === '') {
                        continue;
                    }
                    $normalized[$channelKey][$metricKey] = $rawNames;
                }
            }

            return $normalized;
        }

        /**
         * @param array<string, mixed> $dictionaryByChannel
         * @return array<string, array<string, array<int, string>|string>>
         */
        private function normalizeDictionary(array $dictionaryByChannel): array
        {
            $normalized = [];
            foreach ($dictionaryByChannel as $channel => $dictionary) {
                $channelKey = strtolower(trim((string)$channel));
                if ($channelKey === '' || !is_array($dictionary)) {
                    continue;
                }

                $normalized[$channelKey] = [];
                foreach ($dictionary as $metric => $rawNames) {
                    $metricKey = CanonicalMetricDefinitionRegistry::normalize((string)$metric) ?? strtolower(trim((string)$metric));
                    if ($metricKey === '') {
                        continue;
                    }

                    $existing = $normalized[$channelKey][$metricKey] ?? [];
                    $rawNamesArray = is_array($rawNames) ? $rawNames : [$rawNames];
                    $normalized[$channelKey][$metricKey] = array_unique(array_merge($existing, $rawNamesArray));
                }
            }

            return $normalized;
        }

        /**
         * @return array<string, array<string, mixed>>
         */
        private function resolveLocalDriverRegistry(): array
        {
            if ($this->driverRegistryResolver !== null) {
                $resolved = call_user_func($this->driverRegistryResolver);

                return is_array($resolved) ? $resolved : [];
            }

            $file = dirname(__DIR__, 3).'/config/drivers.yaml';
            if (!is_file($file)) {
                return [];
            }

            $parsed = Yaml::parseFile($file);

            return is_array($parsed) ? $parsed : [];
        }

        /**
         * @return array<string, mixed>
         */
        private function resolveProjectConfig(): array
        {
            try {
                if ($this->projectConfigResolver !== null) {
                    $resolved = call_user_func($this->projectConfigResolver);

                    return is_array($resolved) ? $resolved : [];
                }

                return Helpers::getProjectConfig();
            } catch (\Throwable) {
                return [];
            }
        }

        /**
         * @param array<int, string> $values
         */
        private function toSqlStringList(array $values): string
        {
            $quoted = [];
            foreach ($values as $value) {
                $quoted[] = "'".str_replace("'", "''", $value)."'";
            }

            return implode(', ', $quoted);
        }

        /**
         * @param array{
         *     is_canonical:bool,
         *     is_legacy_alias:bool,
         *     deprecation
         * } $inputMeta
         */
        private function resolveInputType(array $inputMeta, string $requestedMetric): string
        {
            if ($inputMeta['is_canonical']) {
                return 'canonical';
            }

            if ($inputMeta['is_legacy_alias']) {
                return 'legacy_alias';
            }

            if ($inputMeta['deprecation'] !== null && in_array($requestedMetric, self::SUPPORTED_DEPRECATED_LEGACY_METRICS, true)) {
                return 'deprecated_legacy_metric';
            }

            return 'unknown';
        }
    }

