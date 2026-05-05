<?php

declare(strict_types=1);

namespace Services\Aggregation\Strategies;

use Doctrine\DBAL\Connection;
use Services\Aggregation\AggregationPlan;
use Services\Aggregation\OptimizedAggregationStrategyInterface;
use Services\Aggregation\OptimizedAggregationHelpersTrait;

final class MarketingHierarchyStrategy implements OptimizedAggregationStrategyInterface
{
    use OptimizedAggregationHelpersTrait;

    public function getKey(): string
    {
        return 'marketing_hierarchy';
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

        $groupPattern = $plan->getStageValue('grouping', 'normalized_pattern');
        $quoteChar = $isPostgres ? '"' : '`';
        $nameCol = $isPostgres ? 'LOWER(mc.name)' : 'mc.name';
        $periodCol = $isPostgres ? 'LOWER(mc.period)' : 'mc.period';

        $metricSqlByExpr = [
            'spend'            => "SUM(CASE WHEN {$nameCol} IN ('spend', 'spend_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'clicks'           => "SUM(CASE WHEN {$nameCol} IN ('clicks', 'clicks_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'impressions'      => "SUM(CASE WHEN {$nameCol} IN ('impressions', 'impressions_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'reach'            => "SUM(CASE WHEN {$nameCol} IN ('reach', 'reach_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'frequency'        => "AVG(CASE WHEN {$nameCol} IN ('frequency', 'frequency_daily') AND $periodCol = 'daily' THEN m.value END)",
            'ctr'              => "SUM(CASE WHEN {$nameCol} IN ('clicks', 'clicks_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END) / NULLIF(SUM(CASE WHEN {$nameCol} IN ('impressions', 'impressions_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END), 0)",
            'cpc'              => "SUM(CASE WHEN {$nameCol} IN ('spend', 'spend_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END) / NULLIF(SUM(CASE WHEN {$nameCol} IN ('clicks', 'clicks_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END), 0)",
            'cpm'              => "SUM(CASE WHEN {$nameCol} IN ('spend', 'spend_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END) / NULLIF(SUM(CASE WHEN {$nameCol} IN ('impressions', 'impressions_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END), 0) * 1000",
            'results'          => "SUM(CASE WHEN {$nameCol} IN ('results', 'results_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
            'cost_per_result'  => "SUM(CASE WHEN {$nameCol} IN ('spend', 'spend_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END) / NULLIF(SUM(CASE WHEN {$nameCol} IN ('results', 'results_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END), 0)",
            'result_rate'      => "SUM(CASE WHEN {$nameCol} IN ('results', 'results_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END) / NULLIF(SUM(CASE WHEN {$nameCol} IN ('impressions', 'impressions_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END), 0)",
            'purchase_roas'    => "AVG(CASE WHEN {$nameCol} IN ('purchase_roas', 'purchase_roas_daily') AND $periodCol = 'daily' THEN m.value END)",
            'actions'          => "SUM(CASE WHEN {$nameCol} IN ('actions', 'actions_daily') AND $periodCol = 'daily' THEN m.value ELSE 0 END)",
        ];

        $selectFields = [];
        $groupByFields = [];
        $orderMap = [];

        $hasDaily = str_contains((string)$groupPattern, 'daily');
        if ($hasDaily) {
            $selectFields[] = "m.metric_date AS {$quoteChar}daily{$quoteChar}";
            $groupByFields[] = 'm.metric_date';
            $orderMap['daily'] = 'm.metric_date';
        }

        if (str_contains((string)$groupPattern, 'gender')) {
            $genderExpr = $isPostgres ? "m.metadata->>'gender'" : "JSON_UNQUOTE(JSON_EXTRACT(m.metadata, '$.gender'))";
            $selectFields[] = "COALESCE($genderExpr, 'unknown') AS {$quoteChar}gender{$quoteChar}";
            $groupByFields[] = "COALESCE($genderExpr, 'unknown')";
            $orderMap['gender'] = "COALESCE($genderExpr, 'unknown')";
        }

        if (str_contains((string)$groupPattern, 'age')) {
            $ageExpr = $isPostgres ? "m.metadata->>'age'" : "JSON_UNQUOTE(JSON_EXTRACT(m.metadata, '$.age'))";
            $selectFields[] = "COALESCE($ageExpr, 'unknown') AS {$quoteChar}age{$quoteChar}";
            $groupByFields[] = "COALESCE($ageExpr, 'unknown')";
            $orderMap['age'] = "COALESCE($ageExpr, 'unknown')";
        }

        if (str_contains((string)$groupPattern, 'ad+ad_id')) {
            $selectFields[] = "COALESCE(ca_ad.name, 'N/A') AS {$quoteChar}ad{$quoteChar}";
            $selectFields[] = "mc.channeled_ad_id AS {$quoteChar}ad_id{$quoteChar}";
            $groupByFields[] = 'mc.channeled_ad_id';
            $groupByFields[] = 'ca_ad.name';
            $orderMap['ad'] = "COALESCE(ca_ad.name, 'N/A')";
            $orderMap['ad_id'] = 'mc.channeled_ad_id';
        } elseif (str_contains((string)$groupPattern, 'adgroup+adgroup_id')) {
            $selectFields[] = "COALESCE(ca_ag.name, 'N/A') AS {$quoteChar}adgroup{$quoteChar}";
            $selectFields[] = "mc.channeled_ad_group_id AS {$quoteChar}adgroup_id{$quoteChar}";
            $groupByFields[] = 'mc.channeled_ad_group_id';
            $groupByFields[] = 'ca_ag.name';
            $orderMap['adgroup'] = "COALESCE(ca_ag.name, 'N/A')";
            $orderMap['adgroup_id'] = 'mc.channeled_ad_group_id';
        }

        foreach ($aggregations as $alias => $expr) {
            $normalizedExpr = strtolower(trim((string)$expr));
            if (!isset($metricSqlByExpr[$normalizedExpr])) {
                return null;
            }

            $safeAlias = preg_replace('/[^a-z0-9_]/i', '_', (string)$alias) ?: (string)$alias;
            $quotedAlias = $quoteChar.$safeAlias.$quoteChar;
            $selectFields[] = $metricSqlByExpr[$normalizedExpr].' AS '.$quotedAlias;
            $orderMap[strtolower($safeAlias)] = $quotedAlias;
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

        $joins = [];
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
        {$orderSql}";

        return $connection->fetchAllAssociative($sql, $sqlParams);
    }
}
