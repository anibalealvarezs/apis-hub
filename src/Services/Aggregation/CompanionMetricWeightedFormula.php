<?php

declare(strict_types=1);

namespace Services\Aggregation;

/** Matches the complete persisted grain, not just account/date. No provider names. */
final class CompanionMetricWeightedFormula
{
    public function build(array $rule, bool $isPostgres): string
    {
        $quote = static fn (string $s): string => "'".str_replace("'", "''", strtolower($s))."'";
        $sources = implode(', ', array_map($quote, $rule['source_names']));
        $weights = implode(', ', array_map($quote, $rule['weight_names']));
        $conditions = ["LOWER(wc.name) IN ($weights)", 'LOWER(wc.period) = LOWER(mc.period)', 'wm.metric_date = m.metric_date'];
        foreach (['channel', 'account_id', 'channeled_account_id', 'campaign_id', 'channeled_campaign_id', 'channeled_ad_group_id', 'channeled_ad_id', 'creative_id', 'event_id', 'channeled_event_id', 'page_id', 'post_id', 'product_id', 'customer_id', 'order_id', 'query_id', 'country_id', 'device_id', 'dimension_set_id', 'location_id', 'state_id', 'city_id'] as $column) {
            $conditions[] = $isPostgres ? "wc.$column IS NOT DISTINCT FROM mc.$column" : "wc.$column <=> mc.$column";
        }
        // Multiple companion observations indicate a broken identity: do not choose an arbitrary one.
        $weight = '(SELECT CASE WHEN COUNT(*) = 1 THEN MAX(wm.value) ELSE NULL END FROM metrics wm JOIN metric_configs wc ON wc.id = wm.metric_config_id WHERE '.implode(' AND ', $conditions).')';
        $source = "LOWER(mc.name) IN ($sources) AND LOWER(mc.period) = 'daily'";
        return "CASE WHEN SUM(CASE WHEN $source AND ($weight IS NULL OR $weight < 0) THEN 1 ELSE 0 END) > 0 THEN NULL ELSE SUM(CASE WHEN $source THEN m.value * $weight ELSE 0 END) / NULLIF(SUM(CASE WHEN $source THEN $weight ELSE 0 END), 0) END";
    }
}
