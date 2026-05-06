<?php

    declare(strict_types=1);

    namespace Services\Aggregation\Strategies;

    use Doctrine\DBAL\Connection;
    use Entities\Analytics\Channel;
    use Repositories\BaseRepository;
    use Services\Aggregation\AggregationPlan;
    use Services\Aggregation\CanonicalMetricSqlResolver;
    use Interfaces\OptimizedAggregationStrategyInterface;
    use Traits\OptimizedAggregationHelpersTrait;

    final class MarketingHierarchyStrategy implements OptimizedAggregationStrategyInterface
    {
        use OptimizedAggregationHelpersTrait;

        public function __construct(
            private readonly ?CanonicalMetricSqlResolver $metricSqlResolver = null,
        )
        {
        }

        public function getKey(): string
        {
            return 'marketing_hierarchy';
        }

        public function execute(
            Connection      $connection,
            AggregationPlan $plan,
            bool            $isPostgres
        ): ?array
        {
            $aggregations = $plan->getAggregations();
            $groupBy = $plan->getGroupBy();
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

            $groupPattern = $plan->getStageValue('grouping', 'normalized_pattern');
            $quoteChar = $isPostgres ? '"' : '`';
            $nameCol = $isPostgres ? 'LOWER(mc.name)' : 'mc.name';
            $periodCol = $isPostgres ? 'LOWER(mc.period)' : 'mc.period';
            $metricSqlResolver = $this->metricSqlResolver ?? new CanonicalMetricSqlResolver();
            $channelKey = $this->resolveChannelKey($filtersArr, $plan);
            $resolvedMetrics = [];

            $selectFields = [];
            $groupByFields = [];
            $joins = [];
            $orderMap = [];

            $hasDaily = str_contains((string)$groupPattern, 'daily');
            if ($hasDaily) {
                $selectFields[] = "m.metric_date AS {$quoteChar}daily$quoteChar";
                $groupByFields[] = 'm.metric_date';
                $orderMap['daily'] = 'm.metric_date';
            }

            if (str_contains((string)$groupPattern, 'gender')) {
                $joins[] = "LEFT JOIN (
                SELECT dsi.dimension_set_id, dv.value
                FROM dimension_set_items dsi
                JOIN dimension_values dv ON dv.id = dsi.dimension_value_id
                JOIN dimension_keys dk ON dk.id = dv.dimension_key_id
                WHERE LOWER(dk.name) = 'gender'
            ) t_gender ON t_gender.dimension_set_id = mc.dimension_set_id";
                $selectFields[] = "COALESCE(t_gender.value, 'unknown') AS {$quoteChar}gender$quoteChar";
                $groupByFields[] = "COALESCE(t_gender.value, 'unknown')";
                $orderMap['gender'] = "COALESCE(t_gender.value, 'unknown')";
            }

            if (str_contains((string)$groupPattern, 'age')) {
                $joins[] = "LEFT JOIN (
                SELECT dsi.dimension_set_id, dv.value
                FROM dimension_set_items dsi
                JOIN dimension_values dv ON dv.id = dsi.dimension_value_id
                JOIN dimension_keys dk ON dk.id = dv.dimension_key_id
                WHERE LOWER(dk.name) = 'age'
            ) t_age ON t_age.dimension_set_id = mc.dimension_set_id";
                $selectFields[] = "COALESCE(t_age.value, 'unknown') AS {$quoteChar}age$quoteChar";
                $groupByFields[] = "COALESCE(t_age.value, 'unknown')";
                $orderMap['age'] = "COALESCE(t_age.value, 'unknown')";
            }

            if (str_contains((string)$groupPattern, 'ad+ad_id')) {
                $selectFields[] = "COALESCE(ca_ad.name, 'N/A') AS {$quoteChar}ad$quoteChar";
                $selectFields[] = "mc.channeled_ad_id AS {$quoteChar}ad_id$quoteChar";
                $groupByFields[] = 'mc.channeled_ad_id';
                $groupByFields[] = 'ca_ad.name';
                $orderMap['ad'] = "COALESCE(ca_ad.name, 'N/A')";
                $orderMap['ad_id'] = 'mc.channeled_ad_id';
            } elseif (str_contains((string)$groupPattern, 'adgroup+adgroup_id')) {
                $selectFields[] = "COALESCE(ca_ag.name, 'N/A') AS {$quoteChar}adgroup$quoteChar";
                $selectFields[] = "mc.channeled_ad_group_id AS {$quoteChar}adgroup_id$quoteChar";
                $groupByFields[] = 'mc.channeled_ad_group_id';
                $groupByFields[] = 'ca_ag.name';
                $orderMap['adgroup'] = "COALESCE(ca_ag.name, 'N/A')";
                $orderMap['adgroup_id'] = 'mc.channeled_ad_group_id';
            }

            foreach ($aggregations as $alias => $expr) {
                $normalizedExpr = strtolower(trim((string)$expr));
                $resolvedMetric = $metricSqlResolver->resolveMarketingMetric(
                    requestedMetric: $normalizedExpr,
                    channel: $channelKey,
                    nameCol: $nameCol,
                    periodCol: $periodCol,
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

                    return null;
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

            $sqlParams = [
                'startDate' => $startDate,
                'endDate'   => $endDate,
            ];

            $whereClauses = [
                'm.metric_date >= :startDate',
                'm.metric_date <= :endDate',
            ];

            if (isset($filtersArr['channeledCampaign'])) {
                $whereClauses[] = 'mc.channeled_campaign_id = :campaignId';
                $sqlParams['campaignId'] = (int)$filtersArr['channeledCampaign'];
            }
            if (isset($filtersArr['adGroup'])) {
                $whereClauses[] = 'mc.channeled_ad_group_id = :adGroupId';
                $sqlParams['adGroupId'] = (int)$filtersArr['adGroup'];
            }
            if (isset($filtersArr['ad'])) {
                $whereClauses[] = 'mc.channeled_ad_id = :adId';
                $sqlParams['adId'] = (int)$filtersArr['ad'];
            }
            if (isset($filtersArr['channel'])) {
                $whereClauses[] = 'mc.channel = :channel';
                $sqlParams['channel'] = (int)$filtersArr['channel'];
            }

            if (str_contains((string)$groupPattern, 'ad+ad_id')) {
                $joins[] = 'LEFT JOIN channeled_ads ca_ad ON ca_ad.id = mc.channeled_ad_id';
            }
            if (str_contains((string)$groupPattern, 'adgroup+adgroup_id')) {
                $joins[] = 'LEFT JOIN channeled_ad_groups ca_ag ON ca_ag.id = mc.channeled_ad_group_id';
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
                ".implode("\n        ", $joins)."
                WHERE ".implode("\n              AND ", $whereClauses)."
                GROUP BY
                    ".implode(",\n            ", $groupByFields)."
                $orderSql";

            return $connection->fetchAllAssociative($sql, $sqlParams);
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
