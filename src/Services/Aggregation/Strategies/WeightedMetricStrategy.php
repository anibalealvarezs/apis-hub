<?php

    declare(strict_types=1);

    namespace Services\Aggregation\Strategies;

    use Doctrine\DBAL\Connection;
    use Doctrine\DBAL\Exception;
    use Exceptions\ConfigurationException;
    use Services\Aggregation\AggregationGroupingResolver;
    use Services\Aggregation\AggregationPlan;
    use Interfaces\OptimizedAggregationStrategyInterface;
    use Traits\OptimizedAggregationHelpersTrait;
    use Services\Aggregation\FilterConditionResolver;
    use Repositories\BaseRepository;
    use Entities\Analytics\Channel;
    use Services\Aggregation\CanonicalMetricSqlResolver;

    final class WeightedMetricStrategy implements OptimizedAggregationStrategyInterface
    {
        use OptimizedAggregationHelpersTrait;

        public function __construct(
            private readonly ?CanonicalMetricSqlResolver $metricSqlResolver = null,
        ) {
        }

        public function getKey(): string
        {
            return 'weighted_metric';
        }

        /**
         * @throws ConfigurationException
         * @throws Exception
         */
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
            $debugSqlEnabled = !empty($filtersArr['debug_sql']);

            $weightedStrategies = $this->resolveWeightedAggregationStrategies($aggregations);
            if ($weightedStrategies === []) {
                return null;
            }

            $groupPattern = $plan->getStageValue('grouping', 'normalized_pattern');
            if ($groupPattern === null) {
                return null;
            }

            $quoteChar = $isPostgres ? '"' : '`';
            $sqlParams = [
                'startDate' => $startDate,
                'endDate'   => $endDate,
            ];
            $sqlTypes = [];

            $metricSqlResolver = $this->metricSqlResolver ?? new CanonicalMetricSqlResolver();
            $channelKey = $this->resolveChannelKey($filtersArr);
            $resolvedMetrics = [];
            $missingMetrics = [];

            $baseMetricNames = [];
            // We need to resolve base metrics (clicks, spend, impressions) and their raw names
            $coreMetrics = ['clicks', 'spend', 'impressions'];
            $resolvedCore = [];
            foreach ($coreMetrics as $coreMetric) {
                $res = $metricSqlResolver->resolveSearchMetric($coreMetric, $channelKey);
                if ($res['raw_names'] !== []) {
                    $baseMetricNames = array_merge($baseMetricNames, $res['raw_names']);
                }
                $resolvedCore[$coreMetric] = $res;
                
                $resolvedMetrics[] = [
                    'alias' => $coreMetric,
                    'requested_metric' => $res['requested_metric'],
                    'canonical_metric' => $res['canonical_metric'],
                    'input_type' => $res['input_type'],
                    'legacy_alias_of' => $res['legacy_alias_of'],
                    'deprecation' => $res['deprecation'],
                    'raw_names' => $res['raw_names'],
                    'source' => $res['source'],
                ];
            }

            foreach ($weightedStrategies as &$strategy) {
                $resolvedSources = [];
                foreach ($strategy['source_metric_names'] as $src) {
                    $res = $metricSqlResolver->resolveSearchMetric($src, $channelKey);
                    if ($res['raw_names'] === []) {
                        $missingMetrics[] = $src;
                    } else {
                        $resolvedSources = array_merge($resolvedSources, $res['raw_names']);
                        $baseMetricNames = array_merge($baseMetricNames, $res['raw_names']);
                    }
                    $resolvedMetrics[] = [
                        'alias' => $src,
                        'requested_metric' => $res['requested_metric'],
                        'canonical_metric' => $res['canonical_metric'],
                        'input_type' => $res['input_type'],
                        'legacy_alias_of' => $res['legacy_alias_of'],
                        'deprecation' => $res['deprecation'],
                        'raw_names' => $res['raw_names'],
                        'source' => $res['source'],
                    ];
                }
                $strategy['resolved_source_names'] = array_values(array_unique($resolvedSources));

                $resolvedWeights = [];
                foreach ($strategy['weight_metric_names'] as $wgt) {
                    $res = $metricSqlResolver->resolveSearchMetric($wgt, $channelKey);
                    if ($res['raw_names'] !== []) {
                        $resolvedWeights = array_merge($resolvedWeights, $res['raw_names']);
                        $baseMetricNames = array_merge($baseMetricNames, $res['raw_names']);
                    }
                }
                $strategy['resolved_weight_names'] = array_values(array_unique($resolvedWeights));
                if ($strategy['resolved_weight_names'] === []) {
                    $missingMetrics[] = implode(', ', $strategy['weight_metric_names']);
                }
            }
            unset($strategy);

            if ($missingMetrics !== []) {
                $repository = $plan->getContextValue('repository');
                if ($repository instanceof BaseRepository) {
                    $repository->appendOptimizedStrategyMeta([
                        'strategy_fallback_reason' => 'missing_metric_equivalence',
                        'missing_metrics' => $missingMetrics,
                        'metric_resolution' => [
                            'channel' => $channelKey,
                            'strategy' => $this->getKey(),
                            'resolved_metrics' => $resolvedMetrics,
                            'missing' => true,
                        ],
                    ]);
                }
                return null;
            }

            $repository = $plan->getContextValue('repository');
            if ($repository instanceof BaseRepository) {
                $repository->appendOptimizedStrategyMeta([
                    'metric_resolution' => [
                        'channel' => $channelKey,
                        'strategy' => $this->getKey(),
                        'resolved_metrics' => $resolvedMetrics,
                    ],
                ]);
            }

            $baseMetricNames = array_values(array_unique($baseMetricNames));
            $metricNameListSql = $this->toSqlStringList($baseMetricNames);

            $configWhere = [
                "mc.period = 'daily'",
                "mc.name IN ($metricNameListSql)",
            ];
            $configParams = [];

            $filterResolver = new FilterConditionResolver();
            foreach (['page' => 'page_id', 'channel' => 'channel', 'country' => 'country_id', 'device' => 'device_id', 'query' => 'query_id'] as $filterKey => $col) {
                if (!isset($filtersArr[$filterKey])) continue;

                $condition = $filterResolver->resolve($filtersArr[$filterKey]);
                $alias = $filterKey."Val";

                switch ($condition['operator']) {
                    case 'neq':
                        $configWhere[] = "mc.$col <> :$alias";
                        $configParams[$alias] = $condition['value'];
                        break;
                    case 'is_null':
                        $configWhere[] = "mc.$col IS NULL";
                        break;
                    case 'is_not_null':
                        $configWhere[] = "mc.$col IS NOT NULL";
                        break;
                    case 'in':
                        $configWhere[] = "mc.$col IN (:$alias)";
                        $configParams[$alias] = $condition['value'];
                        $sqlTypes[$alias] = \Doctrine\DBAL\ArrayParameterType::STRING;
                        break;
                    case 'not_in':
                        $configWhere[] = "mc.$col NOT IN (:$alias)";
                        $configParams[$alias] = $condition['value'];
                        $sqlTypes[$alias] = \Doctrine\DBAL\ArrayParameterType::STRING;
                        break;
                    case 'eq':
                    default:
                        $configWhere[] = "mc.$col = :$alias";
                        $configParams[$alias] = $condition['value'];
                        break;
                }
            }

            $filterResolver = new FilterConditionResolver();
            $dimWhereSql = "";
            foreach ($filtersArr as $key => $value) {
                if (str_starts_with($key, 'dimensions.')) {
                    $dk = trim((string)str_replace('dimensions.', '', $key));
                    $alias = "dim_".preg_replace('/[^a-z0-9]/i', '_', $dk);
                    $condition = $filterResolver->resolve($value);

                    $valuePredicate = match ($condition['operator']) {
                        'neq' => "dv_$alias.value <> :{$alias}_val",
                        'is_null' => "dv_$alias.value IS NULL",
                        'is_not_null' => "dv_$alias.value IS NOT NULL",
                        'eq' => "dv_$alias.value = :{$alias}_val",
                        'in' => "dv_$alias.value IN (:{$alias}_val)",
                        'not_in' => "dv_$alias.value NOT IN (:{$alias}_val)",
                        default => null
                    };

                    if ($valuePredicate === null) {
                        return null;
                    }

                    $dimWhereSql .= "\n                    AND EXISTS (
                    SELECT 1
                    FROM dimension_set_items dsi_$alias
                    JOIN dimension_values dv_$alias ON dsi_$alias.dimension_value_id = dv_$alias.id
                    JOIN dimension_keys dk_$alias ON dv_$alias.dimension_key_id = dk_$alias.id
                    WHERE dsi_$alias.dimension_set_id = mc.dimension_set_id
                    AND LOWER(dk_$alias.name) = LOWER(:{$alias}_key)
                    AND {$valuePredicate}
                )";
                    $sqlParams["{$alias}_key"] = $dk;
                    $sqlParams["{$alias}_val"] = $condition['value'];
                    if (in_array($condition['operator'], ['in', 'not_in'], true)) {
                        $sqlTypes["{$alias}_val"] = \Doctrine\DBAL\ArrayParameterType::STRING;
                    }
                }
            }

            $configWhereSql = implode(' AND ', $configWhere).$dimWhereSql;

            $repository = $plan->getContextValue('repository');
            if (!$repository instanceof BaseRepository) {
                return null;
            }

            $resolver = new AggregationGroupingResolver();
            $relationMap = BaseRepository::getRelationMap();

            $requestedDimensionKeys = $resolver->resolveOptimizedDimensionKeys($groupPattern, $filtersArr, $relationMap);
            $dsWhere = $resolver->buildOptimizedDimensionSetWhereSql($requestedDimensionKeys);

            $sqlParams = array_merge($sqlParams, $configParams);

            $grouping = $resolver->buildWeightedGroupingConfig($groupPattern, $isPostgres, $quoteChar, $relationMap);
            if ($grouping === null) {
                return null;
            }

            $dynamicSimpleMetrics = [];
            $knownComposites = ['ctr', 'cpc', 'cpm', 'cost_per_result', 'result_rate', 'roas', 'purchase_roas', 'website_purchase_roas'];
            foreach ($aggregations as $alias => $expr) {
                $lowerExpr = strtolower(trim($expr));
                $safeAlias = preg_replace('/[^a-z0-9_]/i', '_', (string)$alias) ?: (string)$alias;

                if (!isset($weightedStrategies[$safeAlias])
                    && !in_array($lowerExpr, ['clicks', 'impressions', 'spend', 'ctr'], true)
                    && !in_array($lowerExpr, $knownComposites, true)) {
                    $dynamicSimpleMetrics[$lowerExpr] = $lowerExpr;
                }
            }

            $selectMetrics = [];
            foreach ($aggregations as $alias => $expr) {
                $lowerExpr = strtolower(trim($expr));
                $safeAlias = preg_replace('/[^a-z0-9_]/i', '_', (string)$alias) ?: (string)$alias;
                $quotedAlias = $quoteChar.$safeAlias.$quoteChar;
                $prefix = $weightedStrategies[$safeAlias]['prefix'] ?? null;

                if (isset($dynamicSimpleMetrics[$lowerExpr])) {
                    $selectMetrics[] = "f.$lowerExpr AS $quotedAlias";
                    continue;
                }

                $selectMetrics[] = match ($lowerExpr) {
                    'clicks' => "f.clicks AS $quotedAlias",
                    'impressions' => "f.impressions AS $quotedAlias",
                    'spend' => "f.spend AS $quotedAlias",
                    'ctr' => "f.ctr AS $quotedAlias",
                    default => "f.{$prefix}_value AS $quotedAlias"
                };
            }
            if ($grouping['outer_select'] !== []) {
                $prefixedGrouping = array_map(static fn($f) => "f.$f", $grouping['outer_select']);
                $selectMetrics = array_merge($prefixedGrouping, $selectMetrics);
            }

            $orderSql = '';
            if ($orderBy !== null && $orderBy !== '') {
                $direction = strtoupper((string)$orderDir) === 'DESC' ? 'DESC' : 'ASC';
                $safeOrderBy = preg_replace('/[^a-z0-9_.]/i', '', $orderBy);
                $orderField = $grouping['order_map'][strtolower($safeOrderBy)] ?? (
                isset($aggregations[$safeOrderBy])
                    ? $quoteChar.$safeOrderBy.$quoteChar
                    : $safeOrderBy
                );
                $orderSql = " ORDER BY $orderField $direction";
            }

            $firstWeightedStrategy = count($weightedStrategies) > 0 ? array_values($weightedStrategies)[0] : null;
            $firstWeightNameList = $firstWeightedStrategy ? $this->toSqlStringList($firstWeightedStrategy['weight_metric_names']) : "'impressions','impressions_daily','page_media_view','post_media_view'";
            $finalSelectFields = $grouping['final_select'] !== [] ? implode(",\n                ", $grouping['final_select'])."," : "";
            $finalGroupByFields = $grouping['group_by'] !== [] ? "GROUP BY ".implode(', ', $grouping['group_by']) : "";

            $configsCteModifier = $isPostgres ? 'MATERIALIZED ' : '';
            $configsGroupingSelect = $grouping['configs_select'] !== [] ? ", ".implode(', ', $grouping['configs_select']) : "";
            $configsGroupingJoins = $grouping['configs_joins'] !== [] ? implode("\n            ", $grouping['configs_joins']) : "";
            $baseSelectFields = $grouping['final_select'] !== [] ? ", ".implode(', ', $grouping['final_select']) : "";
            $baseGroupByFields = $grouping['group_by'] !== [] ? ", ".implode(', ', $grouping['group_by']) : "";

            $sql = "WITH configs AS $configsCteModifier(
        SELECT 
            mc.id, mc.page_id, mc.query_id, mc.country_id, mc.device_id, mc.dimension_set_id, mc.name
            $configsGroupingSelect
        FROM metric_configs mc
        $configsGroupingJoins
        WHERE ".str_replace('mc.', 'mc.', $configWhereSql)." $dsWhere
    ),
    base AS (
        SELECT
            m.metric_date, mc.dimension_set_id, mc.page_id, mc.query_id, mc.country_id, mc.device_id
            $baseSelectFields,
            SUM(CASE WHEN mc.name IN (" . ($resolvedCore['spend']['raw_names'] !== [] ? $this->toSqlStringList($resolvedCore['spend']['raw_names']) : "'__none__'") . ") THEN m.value ELSE 0 END) AS spend,
            SUM(CASE WHEN mc.name IN (" . ($resolvedCore['clicks']['raw_names'] !== [] ? $this->toSqlStringList($resolvedCore['clicks']['raw_names']) : "'__none__'") . ") THEN m.value ELSE 0 END) AS clicks,
            SUM(CASE WHEN mc.name IN (" . ($resolvedCore['impressions']['raw_names'] !== [] ? $this->toSqlStringList($resolvedCore['impressions']['raw_names']) : "'__none__'") . ") THEN m.value ELSE 0 END) AS impressions,
            ".($dynamicSimpleMetrics !== [] ? implode(",\n            ", array_map(function ($metric) {
                        return "SUM(CASE WHEN mc.name IN ('$metric', '{$metric}_daily') THEN m.value ELSE 0 END) AS $metric";
                    }, $dynamicSimpleMetrics))."," : "")."
            ".implode(",\n                ", array_map(function ($strategy) {
                    $prefix = $strategy['prefix'];
                    $sourceList = $this->toSqlStringList($strategy['resolved_source_names']);
                    $weightList = $this->toSqlStringList($strategy['resolved_weight_names']);

                    return "MAX(CASE WHEN mc.name IN ($sourceList) THEN m.value END) AS {$prefix}_metric,
            MAX(CASE WHEN mc.name IN ($weightList) THEN m.value END) AS {$prefix}_weight";
                }, $weightedStrategies))."
        FROM metrics m
        JOIN configs mc ON m.metric_config_id = mc.id
        WHERE m.metric_date >= :startDate
        AND m.metric_date <= :endDate
        GROUP BY m.metric_date, mc.dimension_set_id, mc.page_id, mc.query_id, mc.country_id, mc.device_id $baseGroupByFields
    ),
    paired AS (
        SELECT
            b.metric_date, 
            ".($grouping['group_by'] !== [] ? implode(", ", $grouping['group_by']).", " : "")."
            SUM(b.spend) AS spend_value,
            SUM(b.clicks) AS clicks_value,
            SUM(b.impressions) AS impressions_value,
            ".($dynamicSimpleMetrics !== [] ? implode(",\n            ", array_map(function ($metric) {
                        return "SUM(b.$metric) AS {$metric}_value";
                    }, $dynamicSimpleMetrics))."," : "")."
            ".implode(",\n                ", array_map(function ($strategy) {
                    $prefix = $strategy['prefix'];

                    return "SUM(COALESCE(b.{$prefix}_metric, 0) * COALESCE(b.{$prefix}_weight, 0)) AS {$prefix}_weighted_sum,
            SUM(COALESCE(b.{$prefix}_weight, 0)) AS {$prefix}_total_weight";
                }, $weightedStrategies))."
        FROM base b
        GROUP BY b.metric_date ".($grouping['group_by'] !== [] ? ", ".implode(", ", $grouping['group_by']) : "")."
    ),
    finalized AS (
        SELECT
            ".($grouping['outer_select'] !== [] ? implode(",\n                ", array_map(static fn($f) => "p.$f", $grouping['outer_select']))."," : "")."
            SUM(p.spend_value) AS spend,
            SUM(p.clicks_value) AS clicks,
            SUM(p.impressions_value) AS impressions,
            ".($dynamicSimpleMetrics !== [] ? implode(",\n            ", array_map(function ($metric) {
                        return "SUM(p.{$metric}_value) AS $metric";
                    }, $dynamicSimpleMetrics))."," : "")."
            SUM(p.clicks_value) / NULLIF(SUM(p.impressions_value), 0) AS ctr".(count($weightedStrategies) > 0 ? "," : "")."
            ".implode(",\n                ", array_map(function ($strategy) {
                    $prefix = $strategy['prefix'];

                    return "SUM(p.{$prefix}_weighted_sum) / NULLIF(SUM(p.{$prefix}_total_weight), 0) AS {$prefix}_value";
                }, $weightedStrategies))."
        FROM paired p
        $finalGroupByFields
    )
    SELECT ".implode(', ', $selectMetrics)." FROM finalized f
    $orderSql";

            $queryStart = $debugSqlEnabled ? microtime(true) : null;
            $rows = $connection->fetchAllAssociative($sql, $sqlParams, $sqlTypes);

            if ($debugSqlEnabled) {
                $elapsedMs = $queryStart !== null ? (int)round((microtime(true) - $queryStart) * 1000) : -1;
                error_log("[AggregateDebug] path=optimized_weighted strategy=WeightedMetricStrategy groupPattern=$groupPattern rows=".count($rows)." elapsed_ms=$elapsedMs");
            }

            return $rows;
        }

        /**
         * @param array<string, mixed> $filtersArr
         */
        private function resolveChannelKey(array $filtersArr): ?string
        {
            if (!array_key_exists('channel', $filtersArr)) {
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
