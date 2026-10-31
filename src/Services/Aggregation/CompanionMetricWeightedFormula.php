<?php

declare(strict_types=1);

namespace Services\Aggregation;

/** Matches the complete persisted grain, not just account/date. No provider names. */
final class CompanionMetricWeightedFormula
{
    public const GRAIN_COLUMNS = ['channel', 'account_id', 'channeled_account_id', 'campaign_id', 'channeled_campaign_id', 'channeled_ad_group_id', 'channeled_ad_id', 'creative_id', 'event_id', 'channeled_event_id', 'page_id', 'post_id', 'product_id', 'customer_id', 'order_id', 'query_id', 'country_id', 'device_id', 'dimension_set_id', 'location_id', 'state_id', 'city_id'];

    /** One grouped scan per companion metric set, rather than subqueries per source row. */
    public function join(array $rule, string $alias, bool $isPostgres, array $bounds = [], array $sourceNames = []): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) throw new \InvalidArgumentException('Invalid companion alias.');
        $names = implode(', ', array_map(static fn ($name) => "'".str_replace("'", "''", strtolower($name))."'", $rule['weight_names']));
        $grain = implode(', ', array_map(static fn ($column) => "wc.$column", self::GRAIN_COLUMNS));
        $where = ["LOWER(wc.name) IN ($names)", "LOWER(wc.period) = 'daily'", ...$bounds];
        $conditions = ["$alias.metric_date = m.metric_date"];
        if ($sourceNames) {
            $sourceList = implode(', ', array_map(static fn ($name) => "'".str_replace("'", "''", strtolower($name))."'", $sourceNames));
            $conditions[] = "LOWER(mc.name) IN ($sourceList)";
        }
        foreach (self::GRAIN_COLUMNS as $column) {
            // Equijoins permit PostgreSQL hash/merge plans. The nullness comparison
            // distinguishes a real zero from NULL without reserving an ID sentinel.
            $conditions[] = $isPostgres
                ? "COALESCE($alias.$column, 0) = COALESCE(mc.$column, 0) AND ($alias.$column IS NULL) = (mc.$column IS NULL)"
                : "$alias.$column <=> mc.$column";
        }
        return "LEFT JOIN (SELECT $grain, wm.metric_date, COUNT(*) AS observation_count, MAX(wm.value) AS weight_value FROM metrics wm JOIN metric_configs wc ON wc.id = wm.metric_config_id WHERE ".implode(' AND ', $where)." GROUP BY $grain, wm.metric_date) $alias ON ".implode(' AND ', $conditions);
    }

    public function buildFromJoin(array $rule, string $alias): string
    {
        return $this->buildFromColumns($rule, "$alias.observation_count", "$alias.weight_value");
    }

    public function buildFromColumns(array $rule, string $count, string $weight): string
    {
        $sources = implode(', ', array_map(static fn ($name) => "'".str_replace("'", "''", strtolower($name))."'", $rule['source_names']));
        $source = "LOWER(mc.name) IN ($sources) AND LOWER(mc.period) = 'daily'";
        return "CASE WHEN SUM(CASE WHEN $source AND ($count IS NULL OR $count <> 1 OR $weight IS NULL OR $weight < 0) THEN 1 ELSE 0 END) > 0 THEN NULL ELSE SUM(CASE WHEN $source THEN m.value * $weight ELSE 0 END) / NULLIF(SUM(CASE WHEN $source THEN $weight ELSE 0 END), 0) END";
    }

    public function build(array $rule, bool $isPostgres): string
    {
        $quote = static fn (string $s): string => "'".str_replace("'", "''", strtolower($s))."'";
        $sources = implode(', ', array_map($quote, $rule['source_names']));
        $weights = implode(', ', array_map($quote, $rule['weight_names']));
        $conditions = ["LOWER(wc.name) IN ($weights)", 'LOWER(wc.period) = LOWER(mc.period)', 'wm.metric_date = m.metric_date'];
        foreach (self::GRAIN_COLUMNS as $column) {
            $conditions[] = $isPostgres ? "wc.$column IS NOT DISTINCT FROM mc.$column" : "wc.$column <=> mc.$column";
        }
        // Multiple companion observations indicate a broken identity: do not choose an arbitrary one.
        $weight = '(SELECT CASE WHEN COUNT(*) = 1 THEN MAX(wm.value) ELSE NULL END FROM metrics wm JOIN metric_configs wc ON wc.id = wm.metric_config_id WHERE '.implode(' AND ', $conditions).')';
        $source = "LOWER(mc.name) IN ($sources) AND LOWER(mc.period) = 'daily'";
        return "CASE WHEN SUM(CASE WHEN $source AND ($weight IS NULL OR $weight < 0) THEN 1 ELSE 0 END) > 0 THEN NULL ELSE SUM(CASE WHEN $source THEN m.value * $weight ELSE 0 END) / NULLIF(SUM(CASE WHEN $source THEN $weight ELSE 0 END), 0) END";
    }
}
