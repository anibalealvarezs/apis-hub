<?php

declare(strict_types=1);

namespace Services\Aggregation;

final class MetricCurrencyGuardFormula
{
    public function build(array $rule, bool $isPostgres): string
    {
        $path = $rule['currency_metadata_path'];
        foreach ($path as $part) if (!preg_match('/^[a-zA-Z0-9_]+$/', $part)) throw new \InvalidArgumentException('Invalid currency metadata path.');
        $currency = $isPostgres ? "m.metadata #>> '{".implode(',', $path)."}'" : "JSON_UNQUOTE(JSON_EXTRACT(m.metadata, '$.".implode('.', $path)."'))";
        $names = implode(', ', array_map(fn ($name) => "'".str_replace("'", "''", strtolower($name))."'", $rule['source_names']));
        $source = "LOWER(mc.name) IN ($names) AND LOWER(mc.period) = 'daily'";
        $currency = "NULLIF(TRIM($currency), '')";
        return "CASE WHEN COUNT(DISTINCT CASE WHEN $source THEN mc.channeled_account_id END) > 1 AND (COUNT(DISTINCT CASE WHEN $source THEN $currency END) <> 1 OR MAX(CASE WHEN $source AND $currency IS NULL THEN 1 ELSE 0 END) = 1) THEN 1 ELSE 0 END";
    }
}
