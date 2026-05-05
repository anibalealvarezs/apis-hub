<?php

declare(strict_types=1);

namespace Services\Aggregation\Strategies;

use Doctrine\DBAL\Connection;
use Services\Aggregation\AggregationPlan;
use Services\Aggregation\OptimizedAggregationStrategyInterface;
use Services\Aggregation\OptimizedAggregationHelpersTrait;
use Services\Aggregation\FilterConditionResolver;
use Repositories\BaseRepository;

final class WeightedMetricStrategy implements OptimizedAggregationStrategyInterface
{
    use OptimizedAggregationHelpersTrait;

    public function getKey(): string
    {
        return 'weighted_metric';
    }

    public function execute(
        Connection $connection,
        AggregationPlan $plan,
        bool $isPostgres
    ): ?array {
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

        $baseMetricNames = ['clicks', 'clicks_daily'];
        foreach ($weightedStrategies as $strategy) {
            $baseMetricNames = array_merge($baseMetricNames, $strategy['source_metric_names'], $strategy['weight_metric_names']);
        }
        $baseMetricNames = array_values(array_unique($baseMetricNames));
        $metricNameListSql = $this->toSqlStringList($baseMetricNames);

        $configWhere = [
            "mc.period = 'daily'",
            "mc.name IN ($metricNameListSql)",
        ];
        $configParams = [];

        if (($filtersArr['page'] ?? null) !== null) {
            $configWhere[] = 'mc.page_id = :pageId';
            $configParams['pageId'] = (int)$filtersArr['page'];
        }
        if (($filtersArr['channel'] ?? null) !== null) {
            $configWhere[] = 'mc.channel = :channel';
            $configParams['channel'] = (int)$filtersArr['channel'];
        }
        if (($filtersArr['country'] ?? null) !== null) {
            $configWhere[] = 'mc.country_id = :countryId';
            $configParams['countryId'] = (int)$filtersArr['country'];
        }
        if (($filtersArr['device'] ?? null) !== null) {
            $configWhere[] = 'mc.device_id = :deviceId';
            $configParams['deviceId'] = (int)$filtersArr['device'];
        }
        if (($filtersArr['query'] ?? null) !== null) {
            $configWhere[] = 'mc.query_id = :queryId';
            $configParams['queryId'] = (int)$filtersArr['query'];
        }

        $filterResolver = new FilterConditionResolver();
        $dimWhereSql = "";
        foreach ($filtersArr as $key => $value) {
            if (str_starts_with($key, 'dimensions.')) {
                $dk = trim((string)str_replace('dimensions.', '', $key));
                $alias = "dim_".preg_replace('/[^a-z0-9]/i', '_', $dk);
                $condition = $filterResolver->resolve($value);

                if (!in_array($condition['operator'], ['eq', 'neq'], true)) {
                    return null;
                }

                $valuePredicate = $condition['operator'] === 'neq'
                    ? "dv_$alias.value <> :{$alias}_val"
                    : "dv_$alias.value = :{$alias}_val";

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
            }
        }

        $configWhereSql = implode(' AND ', $configWhere).$dimWhereSql;

        // Note: The following helpers are still in BaseRepository for now.
        // We'll move them to a GroupingResolver soon.
        $repository = $plan->getContextValue('repository');
        if (!$repository instanceof BaseRepository) {
             return null;
        }

        $requestedDimensionKeys = $repository->resolveOptimizedDimensionKeys($groupPattern, $filtersArr);
        $dsWhere = $repository->buildOptimizedDimensionSetWhereSql($requestedDimensionKeys);

        $sqlParams = array_merge($sqlParams, $configParams);

        $grouping = $repository->buildWeightedGroupingConfig($groupPattern, $isPostgres, $quoteChar);
        if ($grouping === null) {
            return null;
        }

        $selectMetrics = [];
        foreach ($aggregations as $alias => $expr) {
            $lowerExpr = strtolower(trim((string)$expr));
            $safeAlias = preg_replace('/[^a-z0-9_]/i', '_', (string)$alias) ?: (string)$alias;
            $quotedAlias = $quoteChar.$safeAlias.$quoteChar;
            $prefix = $weightedStrategies[$safeAlias]['prefix'] ?? null;

            $selectMetrics[] = match ($lowerExpr) {
                'clicks' => "f.clicks AS $quotedAlias",
                'impressions' => "f.impressions AS $quotedAlias",
                'ctr' => "f.ctr AS $quotedAlias",
                default => "f.{$prefix}_value AS $quotedAlias"
            };
        }
        if ($grouping['outer_select'] !== []) $selectMetrics = array_merge($grouping['outer_select'], $selectMetrics);

        $orderSql = '';
        if ($orderBy !== null && $orderBy !== '') {
            $direction = strtoupper((string)$orderDir) === 'DESC' ? 'DESC' : 'ASC';
            $safeOrderBy = preg_replace('/[^a-z0-9_.]/i', '', $orderBy);
            $orderField = $grouping['order_map'][strtolower($safeOrderBy)] ?? $safeOrderBy;
            $orderSql = " ORDER BY $orderField $direction";
        }

        $firstWeightNameList = $this->toSqlStringList(array_values($weightedStrategies)[0]['weight_metric_names']);
        $finalSelectFields = $grouping['final_select'] !== [] ? implode(",\n                ", $grouping['final_select'])."," : "";
        $finalGroupByFields = $grouping['group_by'] !== [] ? "GROUP BY ".implode(', ', $grouping['group_by']) : "";

        $configsCteModifier = $isPostgres ? 'MATERIALIZED ' : '';

        $sql = "WITH configs AS {$configsCteModifier}(
        SELECT 
            mc.id, mc.page_id, mc.query_id, mc.country_id, mc.device_id, mc.dimension_set_id, mc.name
        FROM metric_configs mc
        WHERE ".str_replace('mc.', 'mc.', $configWhereSql)." $dsWhere
    ),
    base AS (
        SELECT
            m.metric_date, mc.dimension_set_id, mc.page_id, mc.query_id, mc.country_id, mc.device_id,
            SUM(CASE WHEN mc.name IN ('clicks', 'clicks_daily') THEN m.value ELSE 0 END) AS clicks,
            SUM(CASE WHEN mc.name IN ($firstWeightNameList) THEN m.value ELSE 0 END) AS impressions,
            ".implode(",\n                ", array_map(function ($strategy) {
                $prefix = $strategy['prefix'];
                $sourceList = $this->toSqlStringList($strategy['source_metric_names']);
                $weightList = $this->toSqlStringList($strategy['weight_metric_names']);

                return "MAX(CASE WHEN mc.name IN ($sourceList) THEN m.value END) AS {$prefix}_metric,
            MAX(CASE WHEN mc.name IN ($weightList) THEN m.value END) AS {$prefix}_weight";
            }, $weightedStrategies))."
        FROM metrics m
        JOIN configs mc ON m.metric_config_id = mc.id
        WHERE m.metric_date >= :startDate
        AND m.metric_date <= :endDate
        AND mc.dimension_set_id IN (SELECT DISTINCT dimension_set_id FROM configs)
        GROUP BY m.metric_date, mc.dimension_set_id, mc.page_id, mc.query_id, mc.country_id, mc.device_id
    ),
    paired AS (
        SELECT
            b.metric_date, 
            ".($grouping['final_select'] !== [] ? ($cols = array_unique(array_filter(array_map(fn($s) => str_replace('p.', 'b.', explode(' AS ', $s)[0]), $grouping['final_select']), fn($col) => $col !== 'b.metric_date'))) ? implode(", ", $cols).", " : "" : "")."
            SUM(b.clicks) AS clicks_value,
            SUM(b.impressions) AS impressions_value,
            ".implode(",\n                ", array_map(function ($strategy) {
                $prefix = $strategy['prefix'];

                return "SUM(COALESCE(b.{$prefix}_metric, 0) * COALESCE(b.{$prefix}_weight, 0)) AS {$prefix}_weighted_sum,
            SUM(COALESCE(b.{$prefix}_weight, 0)) AS {$prefix}_total_weight";
            }, $weightedStrategies))."
        FROM base b
        GROUP BY b.metric_date".($grouping['final_select'] !== [] ? ($cols = array_unique(array_filter(array_map(fn($s) => str_replace('p.', 'b.', explode(' AS ', $s)[0]), $grouping['final_select']), fn($col) => $col !== 'b.metric_date'))) ? ", ".implode(", ", $cols) : "" : "")."
    ),
    finalized AS (
        SELECT
            $finalSelectFields
            SUM(p.clicks_value) AS clicks,
            SUM(p.impressions_value) AS impressions,
            SUM(p.clicks_value) / NULLIF(SUM(p.impressions_value), 0) AS ctr".(count($weightedStrategies) > 0 ? "," : "")."
            ".implode(",\n                ", array_map(function ($strategy) {
                $prefix = $strategy['prefix'];

                return "SUM(p.{$prefix}_weighted_sum) / NULLIF(SUM(p.{$prefix}_total_weight), 0) AS {$prefix}_value";
            }, $weightedStrategies))."
        FROM paired p
        $finalGroupByFields
    )
    SELECT ".implode(', ', $selectMetrics)." FROM finalized f
    ".implode("\n            ", $grouping['joins'])."
    $orderSql";

        $queryStart = $debugSqlEnabled ? microtime(true) : null;
        $rows = $connection->fetchAllAssociative($sql, $sqlParams);

        if ($debugSqlEnabled) {
            $elapsedMs = $queryStart !== null ? (int)round((microtime(true) - $queryStart) * 1000) : -1;
            error_log("[AggregateDebug] path=optimized_weighted strategy=WeightedMetricStrategy groupPattern={$groupPattern} rows=".count($rows)." elapsed_ms={$elapsedMs}");
        }

        return $rows;
    }
}
