<?php

declare(strict_types=1);

namespace Services\Aggregation;

final class CompanionTimeWeightedAverageFormulaBuilder
{
    /**
     * @param array<int, string> $sourceMetricNames
     * @param array<int, string> $totalTimeMetricNames
     * @param callable(array<int, string>): string $toSqlStringList
     */
    public function build(
        array $sourceMetricNames,
        array $totalTimeMetricNames,
        string $valCol,
        bool $isPostgres,
        string $periodCondition,
        bool $isChanneledMetric,
        callable $toSqlStringList
    ): string {
        $nullSafeComparator = $isPostgres ? 'IS NOT DISTINCT FROM' : '<=>';
        $metricDateColumn = $isChanneledMetric ? 'm.metric_date' : 'e.metric_date';
        $sourceMetricList = $toSqlStringList($sourceMetricNames);
        $totalTimeMetricList = $toSqlStringList($totalTimeMetricNames);
        $sourceCondition = ($isPostgres ? "LOWER(mc.name) IN ($sourceMetricList)" : "mc.name IN ($sourceMetricList)")." AND {$periodCondition}";
        $totalTimePeriodCondition = str_replace('mc.', 'mc2.', $periodCondition);
        $totalTimeCondition = ($isPostgres ? "LOWER(mc2.name) IN ($totalTimeMetricList)" : "mc2.name IN ($totalTimeMetricList)")." AND {$totalTimePeriodCondition}";

        $companionTotalTimeSql = "(SELECT m2.value
                FROM metrics m2
                JOIN metric_configs mc2 ON m2.metric_config_id = mc2.id
                WHERE {$totalTimeCondition}
                AND m2.metric_date = {$metricDateColumn}
                AND mc2.channel = mc.channel
                AND (mc2.dimension_set_id {$nullSafeComparator} mc.dimension_set_id)
                AND (mc2.query_id {$nullSafeComparator} mc.query_id)
                AND (mc2.page_id {$nullSafeComparator} mc.page_id)
                AND (mc2.country_id {$nullSafeComparator} mc.country_id)
                AND (mc2.device_id {$nullSafeComparator} mc.device_id)
                AND (mc2.channeled_account_id {$nullSafeComparator} mc.channeled_account_id)
                AND (mc2.post_id {$nullSafeComparator} mc.post_id)
                LIMIT 1)";

        $nonZeroAverageCondition = "{$sourceCondition} AND NULLIF({$valCol}, 0) IS NOT NULL";

        return "SUM(CASE WHEN {$nonZeroAverageCondition} THEN COALESCE({$companionTotalTimeSql}, 0) ELSE 0 END)
                / NULLIF(SUM(CASE WHEN {$nonZeroAverageCondition} THEN COALESCE({$companionTotalTimeSql}, 0) / NULLIF({$valCol}, 0) ELSE 0 END), 0)";
    }
}

