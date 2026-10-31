<?php

declare(strict_types=1);

namespace Services\Aggregation;

/** Pair source measures and companions in one fact scan, without a grain self-join. */
final class CompanionMetricWindow
{
    public function compile(array $rules, array $bounds): array
    {
        $quote = static fn ($name) => "'".str_replace("'", "''", strtolower($name))."'";
        $names = []; $sets = [];
        foreach ($rules as $rule) {
            $names = array_merge($names, $rule['source_names']);
            if (($rule['reducer'] ?? '') !== 'companion_weighted_average') continue;
            $weights = $rule['weight_names']; sort($weights);
            $sets[json_encode($weights)] = $weights;
            $names = array_merge($names, $weights);
        }
        if (!$sets) return [];
        $columns = []; $companions = [];
        foreach ($sets as $key => $weights) {
            $index = count($companions);
            $count = '_contract_weight_count_'.$index;
            $value = '_contract_weight_value_'.$index;
            $predicate = 'LOWER(wc.name) IN ('.implode(', ', array_map($quote, $weights)).')';
            $columns[] = "SUM(CASE WHEN $predicate THEN 1 ELSE 0 END) OVER contract_grain AS $count";
            $columns[] = "MAX(CASE WHEN $predicate THEN wm.value END) OVER contract_grain AS $value";
            $companions[$key] = ['count' => 'm.'.$count, 'value' => 'm.'.$value];
        }
        $where = ["LOWER(wc.period) = 'daily'", 'LOWER(wc.name) IN ('.implode(', ', array_map($quote, array_unique($names))).')', ...array_unique($bounds)];
        $grain = implode(', ', array_map(static fn ($column) => 'wc.'.$column, CompanionMetricWeightedFormula::GRAIN_COLUMNS));
        $sql = 'WITH contract_observations AS MATERIALIZED (SELECT wm.*, '.implode(', ', $columns).' FROM metrics wm JOIN metric_configs wc ON wc.id = wm.metric_config_id WHERE '.implode(' AND ', $where).' WINDOW contract_grain AS (PARTITION BY '.$grain.', wm.metric_date)) ';
        return ['prefix' => $sql, 'table' => 'contract_observations m', 'companions' => $companions];
    }
}
