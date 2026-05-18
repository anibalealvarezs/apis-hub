<?php

    declare(strict_types=1);

    namespace Services\Aggregation\Strategies;

    use Doctrine\DBAL\Connection;
    use Doctrine\DBAL\Exception;
    use Entities\Analytics\Channel;
    use Repositories\BaseRepository;
    use Services\Aggregation\AggregationEntityFieldResolver;
    use Services\Aggregation\AggregationPlan;
    use Services\Aggregation\CanonicalMetricSqlResolver;
    use Interfaces\OptimizedAggregationStrategyInterface;
    use Services\Aggregation\FilterConditionResolver;
    use Traits\OptimizedAggregationHelpersTrait;

    final class SocialOrganicStrategy implements OptimizedAggregationStrategyInterface
    {
        private const string KEY = 'social_organic';

        use OptimizedAggregationHelpersTrait;

        public function __construct(
            private readonly ?CanonicalMetricSqlResolver     $metricSqlResolver = null,
            private readonly ?AggregationEntityFieldResolver $entityFieldResolver = null,
        )
        {
        }

        public function getKey(): string
        {
            return self::KEY;
        }

        public function execute(
            Connection      $connection,
            AggregationPlan $plan,
            bool            $isPostgres
        ): ?array
        {
            $strategies = $plan->getCandidateOptimizedStrategies();

            if (in_array(self::KEY.'_page_summary', $strategies, true)) {
                return $this->executePageSummary($connection, $plan, $isPostgres);
            }

            if (in_array(self::KEY.'_linked_pages', $strategies, true)) {
                return $this->executeLinkedPages($connection, $plan, $isPostgres);
            }

            if (in_array(self::KEY.'_post_snapshot', $strategies, true)) {
                return $this->executePostSnapshot($connection, $plan, $isPostgres);
            }

            return null;
        }

        private function executePageSummary(Connection $connection, AggregationPlan $plan, bool $isPostgres): ?array
        {
            $aggregations = $plan->getAggregations();
            $filters = $plan->getFilters();
            $startDate = $plan->getStartDate();
            $endDate = $plan->getEndDate();
            $orderBy = $plan->getOrderBy();
            $orderDir = $plan->getOrderDir();

            $filtersArr = [];
            if ($filters !== null) {
                foreach ($filters as $key => $value) {
                    $filtersArr[(string)$key] = $value;
                }
            }

            $accountType = strtolower(trim((string)($filtersArr['account_type'] ?? '')));
            $pagePlatformId = trim((string)($filtersArr['page_platform_id'] ?? ''));

            $quoteChar = $isPostgres ? '"' : '`';
            $nameCol = $isPostgres ? 'LOWER(mc.name)' : 'mc.name';
            $periodCol = $isPostgres ? 'LOWER(mc.period)' : 'mc.period';

            $metricSqlResolver = $this->metricSqlResolver ?? new CanonicalMetricSqlResolver();
            $entityFieldResolver = $this->entityFieldResolver ?? new AggregationEntityFieldResolver();
            $channelKey = $this->resolveChannelKey($filtersArr, $plan);

            $pagePlatformExpr = $entityFieldResolver->getPagePlatformIdExpr($channelKey, $isPostgres);

            $selectFields = [
                "COALESCE(p.url, 'N/A') AS {$quoteChar}page$quoteChar",
                "mc.page_id AS {$quoteChar}page_id$quoteChar",
                "COALESCE(p.title, 'N/A') AS {$quoteChar}page_title$quoteChar",
            ];
            $orderMap = [
                'page'       => "COALESCE(p.url, 'N/A')",
                'page_id'    => 'mc.page_id',
                'page_title' => "COALESCE(p.title, 'N/A')",
            ];

            if (!$this->resolveAndAppendAggregations($aggregations, $metricSqlResolver, $channelKey, $nameCol, $periodCol, 'daily', $plan, $selectFields, $orderMap, $quoteChar)) {
                return null;
            }

            $sqlParams = [
                'startDate'      => $startDate,
                'endDate'        => $endDate,
                'accountType'    => $accountType,
                'pagePlatformId' => $pagePlatformId,
            ];

            $whereClauses = [
                'm.metric_date >= :startDate',
                'm.metric_date <= :endDate',
                'LOWER(ca.type) = LOWER(:accountType)',
                "{$pagePlatformExpr} = :pagePlatformId",
            ];
            if (isset($filtersArr['channel'])) {
                $whereClauses[] = 'mc.channel = :channel';
                $sqlParams['channel'] = $filtersArr['channel'];
            }

            $orderSql = '';
            if ($orderBy !== null && trim($orderBy) !== '') {
                $direction = strtoupper((string)$orderDir) === 'DESC' ? 'DESC' : 'ASC';
                $safeOrderBy = strtolower((string)preg_replace('/[^a-z0-9_]/i', '', $orderBy));
                $orderField = $orderMap[$safeOrderBy] ?? null;
                if ($orderField !== null) {
                    $orderSql = " ORDER BY $orderField $direction";
                }
            }

            $sql = "SELECT
            ".implode(",\n                ", $selectFields)."
        FROM metrics m
        JOIN metric_configs mc ON m.metric_config_id = mc.id
        LEFT JOIN channeled_accounts ca ON ca.id = mc.channeled_account_id
        LEFT JOIN pages p ON p.id = mc.page_id
        WHERE ".implode("\n              AND ", $whereClauses)."
        GROUP BY
            mc.page_id,
            COALESCE(p.url, 'N/A'),
            COALESCE(p.title, 'N/A')
        $orderSql";

            return $connection->fetchAllAssociative($sql, $sqlParams);
        }

        /**
         * @throws Exception
         */
        private function executeLinkedPages(Connection $connection, AggregationPlan $plan, bool $isPostgres): ?array
        {
            $aggregations = $plan->getAggregations();
            $filters = $plan->getFilters();
            $startDate = $plan->getStartDate();
            $endDate = $plan->getEndDate();
            $orderBy = $plan->getOrderBy();
            $orderDir = $plan->getOrderDir();

            $filtersArr = [];
            if ($filters !== null) {
                foreach ($filters as $key => $value) {
                    $filtersArr[(string)$key] = $value;
                }
            }

            $accountType = strtolower(trim((string)($filtersArr['account_type'] ?? '')));
            $quoteChar = $isPostgres ? '"' : '`';
            $nameCol = $isPostgres ? 'LOWER(mc.name)' : 'mc.name';
            $periodCol = $isPostgres ? 'LOWER(mc.period)' : 'mc.period';

            $metricSqlResolver = $this->metricSqlResolver ?? new CanonicalMetricSqlResolver();
            $entityFieldResolver = $this->entityFieldResolver ?? new AggregationEntityFieldResolver();
            $channelKey = $this->resolveChannelKey($filtersArr, $plan);

            $linkedEntityExpr = $entityFieldResolver->getChanneledAccountEntityIdExpr($channelKey, $isPostgres);

            $selectFields = [
                "mc.channeled_account_id AS {$quoteChar}channeled_account_id$quoteChar",
                "COALESCE(ca.name, 'N/A') AS {$quoteChar}channeledaccount$quoteChar",
                "{$linkedEntityExpr} AS {$quoteChar}linked_platform_entity_id$quoteChar",
                "COALESCE(p.platform_id, 'N/A') AS {$quoteChar}page_platform_id$quoteChar",
            ];
            $orderMap = [
                'channeled_account_id'      => 'mc.channeled_account_id',
                'channeledaccount'          => "COALESCE(ca.name, 'N/A')",
                'linked_platform_entity_id' => $linkedEntityExpr,
                'page_platform_id'          => "COALESCE(p.platform_id, 'N/A')",
            ];

            if (!$this->resolveAndAppendAggregations($aggregations, $metricSqlResolver, $channelKey, $nameCol, $periodCol, 'daily', $plan, $selectFields, $orderMap, $quoteChar)) {
                return null;
            }

            $sqlParams = [
                'startDate'   => $startDate,
                'endDate'     => $endDate,
                'accountType' => $accountType,
            ];

            $whereClauses = [
                'm.metric_date >= :startDate',
                'm.metric_date <= :endDate',
            ];

            $filterResolver = new FilterConditionResolver();

            // Handle Account/Page filters
            if (!empty($filtersArr['channeledAccount'])) {
                $condition = $filterResolver->resolve($filtersArr['channeledAccount']);
                $whereClauses[] = $this->buildFilterClause('mc.channeled_account_id', $condition, 'channeledAccount');
                if ($condition['value'] !== null) $sqlParams['channeledAccount'] = (int)$condition['value'];
            } elseif (!empty($filtersArr['page'])) {
                $condition = $filterResolver->resolve($filtersArr['page']);
                $whereClauses[] = $this->buildFilterClause('mc.page_id', $condition, 'pageId');
                if ($condition['value'] !== null) $sqlParams['pageId'] = (int)$condition['value'];
            } else {
                return null;
            }

            // Handle Channel
            if (isset($filtersArr['channel'])) {
                $condition = $filterResolver->resolve($filtersArr['channel']);
                $whereClauses[] = $this->buildFilterClause('mc.channel', $condition, 'channel');
                if ($condition['value'] !== null) $sqlParams['channel'] = $condition['value'];
            }

            // Account Type
            $whereClauses[] = 'LOWER(ca.type) = LOWER(:accountType)';
            $sqlParams['accountType'] = $accountType;

            $orderSql = '';
            if ($orderBy !== null && trim($orderBy) !== '') {
                $direction = strtoupper((string)$orderDir) === 'DESC' ? 'DESC' : 'ASC';
                $safeOrderBy = strtolower((string)preg_replace('/[^a-z0-9_.]/i', '', $orderBy));
                $orderField = $orderMap[$safeOrderBy] ?? null;
                if ($orderField !== null) {
                    $orderSql = " ORDER BY $orderField $direction";
                }
            }

            $sql = "SELECT
            ".implode(",\n                ", $selectFields)."
        FROM metrics m
        JOIN metric_configs mc ON m.metric_config_id = mc.id
        LEFT JOIN channeled_accounts ca ON ca.id = mc.channeled_account_id
        LEFT JOIN pages p ON p.id = mc.page_id
        WHERE ".implode("\n              AND ", $whereClauses)."
        GROUP BY
            mc.channeled_account_id,
            COALESCE(ca.name, 'N/A'),
            $linkedEntityExpr,
            COALESCE(p.platform_id, 'N/A')
        $orderSql";

            return $connection->fetchAllAssociative($sql, $sqlParams);
        }

        private function executePostSnapshot(Connection $connection, AggregationPlan $plan, bool $isPostgres): ?array
        {
            $aggregations = $plan->getAggregations();
            $filters = $plan->getFilters();
            $startDate = $plan->getStartDate();
            $endDate = $plan->getEndDate();
            $orderBy = $plan->getOrderBy();
            $orderDir = $plan->getOrderDir();

            $filtersArr = [];
            if ($filters !== null) {
                foreach ($filters as $key => $value) {
                    $filtersArr[(string)$key] = $value;
                }
            }

            $quoteChar = $isPostgres ? '"' : '`';
            $nameCol = $isPostgres ? 'LOWER(mc.name)' : 'mc.name';
            $periodCol = $isPostgres ? 'LOWER(mc.period)' : 'mc.period';

            $metricSqlResolver = $this->metricSqlResolver ?? new CanonicalMetricSqlResolver();
            $channelKey = $this->resolveChannelKey($filtersArr, $plan);

            // Post snapshot typically uses common post fields in JSON
            $captionExpr = $isPostgres ? "ps.data->>'caption'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.caption'))";
            $timestampExpr = $isPostgres ? "ps.data->>'timestamp'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.timestamp'))";
            $mediaTypeExpr = $isPostgres ? "ps.data->>'media_type'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.media_type'))";
            $permalinkExpr = $isPostgres ? "ps.data->>'permalink'" : "JSON_UNQUOTE(JSON_EXTRACT(ps.data, '$.permalink'))";

            $selectFields = [
                "$captionExpr AS {$quoteChar}caption$quoteChar",
                "$timestampExpr AS {$quoteChar}created_time$quoteChar",
                "$mediaTypeExpr AS {$quoteChar}media_type$quoteChar",
                "$captionExpr AS {$quoteChar}message$quoteChar",
                "$permalinkExpr AS {$quoteChar}permalink$quoteChar",
                "$permalinkExpr AS {$quoteChar}permalink_url$quoteChar",
                "ps.id AS {$quoteChar}post$quoteChar",
                "ps.post_id AS {$quoteChar}post_id$quoteChar",
                "$timestampExpr AS {$quoteChar}timestamp$quoteChar",
            ];
            $orderMap = [
                'caption'       => $captionExpr,
                'created_time'  => $timestampExpr,
                'media_type'    => $mediaTypeExpr,
                'message'       => $captionExpr,
                'permalink'     => $permalinkExpr,
                'permalink_url' => $permalinkExpr,
                'post'          => 'ps.id',
                'post_id'       => 'ps.post_id',
                'timestamp'     => $timestampExpr,
            ];

            if (!$this->resolveAndAppendAggregations($aggregations, $metricSqlResolver, $channelKey, $nameCol, $periodCol, 'lifetime', $plan, $selectFields, $orderMap, $quoteChar)) {
                return null;
            }

            $sqlParams = [
                'startDate' => $startDate,
                'endDate'   => $endDate,
            ];

            $whereClauses = [
                'm.metric_date >= :startDate',
                'm.metric_date <= :endDate',
            ];

            $filterResolver = new FilterConditionResolver();

            if (!empty($filtersArr['channeledAccount'])) {
                $condition = $filterResolver->resolve($filtersArr['channeledAccount']);
                $whereClauses[] = $this->buildFilterClause('mc.channeled_account_id', $condition, 'channeledAccount');
                if ($condition['value'] !== null) $sqlParams['channeledAccount'] = (int)$condition['value'];
            } elseif (!empty($filtersArr['page'])) {
                $condition = $filterResolver->resolve($filtersArr['page']);
                $whereClauses[] = $this->buildFilterClause('mc.page_id', $condition, 'pageId');
                if ($condition['value'] !== null) $sqlParams['pageId'] = (int)$condition['value'];
            } else {
                return null;
            }

            if (isset($filtersArr['channel'])) {
                $condition = $filterResolver->resolve($filtersArr['channel']);
                $whereClauses[] = $this->buildFilterClause('mc.channel', $condition, 'channel');
                if ($condition['value'] !== null) $sqlParams['channel'] = $condition['value'];
            }

            if (isset($filtersArr['post'])) {
                $condition = $filterResolver->resolve($filtersArr['post']);
                $whereClauses[] = $this->buildFilterClause('mc.post_id', $condition, 'postId');
                if ($condition['value'] !== null) $sqlParams['postId'] = (int)$condition['value'];
            }

            $orderSql = '';
            if ($orderBy !== null && trim($orderBy) !== '') {
                $direction = strtoupper((string)$orderDir) === 'DESC' ? 'DESC' : 'ASC';
                $safeOrderBy = strtolower((string)preg_replace('/[^a-z0-9_.]/i', '', $orderBy));
                $orderField = $orderMap[$safeOrderBy] ?? null;
                if ($orderField !== null) {
                    $orderSql = " ORDER BY $orderField $direction";
                }
            }

            $sql = "SELECT
            ".implode(",\n                ", $selectFields)."
        FROM metrics m
        JOIN metric_configs mc ON m.metric_config_id = mc.id
        LEFT JOIN posts ps ON ps.id = mc.post_id
        WHERE ".implode("\n              AND ", $whereClauses)."
        GROUP BY
            ps.id,
            ps.post_id,
            $captionExpr,
            $timestampExpr,
            $mediaTypeExpr,
            $permalinkExpr
        $orderSql";

            return $connection->fetchAllAssociative($sql, $sqlParams);
        }

        private function resolveAndAppendAggregations(
            array                      $aggregations,
            CanonicalMetricSqlResolver $metricSqlResolver,
            string                     $channelKey,
            string                     $nameCol,
            string                     $periodCol,
            string                     $period,
            AggregationPlan            $plan,
            array                      &$selectFields,
            array                      &$orderMap,
            string                     $quoteChar
        ): bool
        {
            $resolvedMetrics = [];
            foreach ($aggregations as $alias => $expr) {
                $normalizedExpr = strtolower(trim((string)$expr));
                $resolvedMetric = $metricSqlResolver->resolveOrganicMetric(
                    requestedMetric: $normalizedExpr,
                    channel: $channelKey,
                    nameCol: $nameCol,
                    periodCol: $periodCol,
                    period: $period
                );
                $metricSql = is_string($resolvedMetric['sql_expression']) ? $resolvedMetric['sql_expression'] : null;
                if ($metricSql === null) {
                    $repository = $plan->getContextValue('repository');
                    if ($repository instanceof BaseRepository) {
                        $repository->appendOptimizedStrategyMeta([
                            'strategy_fallback_reason' => 'missing_metric_equivalence',
                            'metric_resolution'        => [
                                'channel'          => $channelKey,
                                'strategy'         => $this->getKey(),
                                'requested_metric' => $resolvedMetric['requested_metric'] ?? $normalizedExpr,
                                'canonical_metric' => $resolvedMetric['canonical_metric'] ?? null,
                                'input_type'       => $resolvedMetric['input_type'] ?? 'unknown',
                                'legacy_alias_of'  => $resolvedMetric['legacy_alias_of'] ?? null,
                                'deprecation'      => $resolvedMetric['deprecation'] ?? null,
                                'source'           => $resolvedMetric['source'] ?? 'none',
                                'raw_names'        => $resolvedMetric['raw_names'] ?? [],
                                'missing'          => true,
                            ],
                        ]);
                    }

                    return false;
                }

                $safeAlias = preg_replace('/[^a-z0-9_]/i', '_', (string)$alias) ?: (string)$alias;
                $quotedAlias = $quoteChar.$safeAlias.$quoteChar;
                $selectFields[] = $metricSql.' AS '.$quotedAlias;
                $orderMap[strtolower($safeAlias)] = $quotedAlias;

                $resolvedMetrics[] = [
                    'alias'            => $safeAlias,
                    'requested_metric' => $resolvedMetric['requested_metric'] ?? $normalizedExpr,
                    'canonical_metric' => $resolvedMetric['canonical_metric'] ?? null,
                    'input_type'       => $resolvedMetric['input_type'] ?? 'unknown',
                    'legacy_alias_of'  => $resolvedMetric['legacy_alias_of'] ?? null,
                    'deprecation'      => $resolvedMetric['deprecation'] ?? null,
                    'raw_names'        => $resolvedMetric['raw_names'] ?? [],
                    'source'           => $resolvedMetric['source'] ?? 'none',
                ];
            }

            $repository = $plan->getContextValue('repository');
            if ($repository instanceof BaseRepository) {
                $repository->appendOptimizedStrategyMeta([
                    'metric_resolution' => [
                        'channel'          => $channelKey,
                        'strategy'         => $this->getKey(),
                        'resolved_metrics' => $resolvedMetrics,
                    ],
                ]);
            }

            return true;
        }

        private function buildFilterClause(string $col, array $condition, string $alias): string
        {
            return match ($condition['operator']) {
                'neq'         => "$col <> :$alias",
                'is_null'     => "$col IS NULL",
                'is_not_null' => "$col IS NOT NULL",
                'in'          => "$col IN (:$alias)",
                'eq'          => "$col = :$alias",
                default       => "$col = :$alias",
            };
        }

        /**
         * @param array<string, mixed> $filtersArr
         */
        private function resolveChannelKey(array $filtersArr, ?AggregationPlan $plan = null): ?string
        {
            if (!array_key_exists('channel', $filtersArr)) {
                if ($plan instanceof AggregationPlan) {
                    $ctxChannel = $plan->getContextValue('channel');
                    if (is_string($ctxChannel)) {
                        return $ctxChannel;
                    }
                }

                return null;
            }

            $value = $filtersArr['channel'];
            if (is_object($value) && property_exists($value, 'value')) {
                $value = $value->value;
            }

            if (!is_scalar($value)) {
                return null;
            }

            $normalized = strtolower(trim((string)$value));
            if ($normalized === '') {
                return null;
            }

            if (!ctype_digit($normalized)) {
                return $normalized;
            }

            $channel = Channel::tryFrom((int)$normalized);

            return $channel instanceof Channel ? strtolower(trim($channel->getName())) : null;
        }
    }
