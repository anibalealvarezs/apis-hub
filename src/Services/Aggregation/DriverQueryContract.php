<?php

declare(strict_types=1);

namespace Services\Aggregation;

use InvalidArgumentException;

/** Optional provider-owned restrictions. Applied before selecting an execution path. */
final class DriverQueryContract
{
    public function unavailableMetrics(array $contract, array $rows): array
    {
        $missing = [];
        foreach ($contract['resolved_rules'] ?? [] as $alias => $rule) {
            if (($rule['reducer'] ?? '') !== 'companion_weighted_average') continue;
            $column = preg_replace('/[^a-z0-9_]/i', '_', (string)$alias) ?: (string)$alias;
            if ($rows === [] || array_filter($rows, fn ($row) => !array_key_exists($column, $row) || $row[$column] === null)) $missing[] = $alias;
        }
        return $missing;
    }

    public function prepare(array $profiles, array $aggregations, array $filters, array $groupBy): array
    {
        $contracts = array_values(array_filter(array_column($profiles, 'query_contract')));
        if ($contracts === []) return ['contract' => [], 'filters' => $filters];
        $contract = $contracts[0];
        if (($contract['version'] ?? null) !== 1 || !is_string($contract['scope_filter'] ?? null) || !is_array($contract['scopes'] ?? null)) {
            throw new InvalidArgumentException('Unsupported or incomplete driver query contract.');
        }
        foreach ($contracts as $other) {
            if ($other !== $contract) throw new InvalidArgumentException('Conflicting driver query contracts.');
        }
        $filters += $contract['default_filters'] ?? [];
        $scopeKey = $contract['scope_filter'];
        foreach (array_keys($filters) as $key) {
            if (str_starts_with($key, $scopeKey.'__')) throw new InvalidArgumentException('Select one reporting scope.');
        }
        $condition = (new FilterConditionResolver())->resolve($filters[$scopeKey] ?? null);
        $scope = $condition['operator'] === 'is_null' ? '__unscoped__' : $condition['value'];
        if (!in_array($condition['operator'], ['eq', 'is_null'], true) || !is_string($scope) || !isset($contract['scopes'][$scope])) {
            throw new InvalidArgumentException('Select one supported reporting scope.');
        }
        $aliases = array_change_key_case($contract['aliases'] ?? [], CASE_LOWER);
        $allowed = array_combine(array_map('strtolower', $contract['scopes'][$scope]), $contract['scopes'][$scope]);
        $blocked = array_map('strtolower', $contract['blocked_metrics'] ?? []);
        $resolved = []; $quality = [];
        foreach ($aggregations as $alias => $expression) {
            if (str_starts_with(strtolower((string)$alias), '_contract_')) throw new InvalidArgumentException('Reserved aggregation alias.');
            $key = strtolower(trim((string)$expression));
            if (in_array($key, $blocked, true)) throw new InvalidArgumentException("Metric '{$expression}' is unavailable for this channel.");
            $target = strtolower($aliases[$key] ?? $key);
            if (!isset($allowed[$target])) throw new InvalidArgumentException("Metric '{$expression}' is unavailable in the selected reporting scope.");
            $metric = $allowed[$target];
            $rule = $contract['rules'][$metric] ?? ['reducer' => 'sum'];
            $rule['source_names'] ??= [$metric];
            // Aliases share a single stored identity; never sum both spellings of key events.
            $resolved[$alias] = $rule;
            if (isset($rule['quality'])) $quality[$alias] = $rule['quality'];
        }
        foreach ($groupBy as $field) {
            if (str_starts_with($field, 'trailing_')) throw new InvalidArgumentException('Rolling windows are unavailable for this metric contract. Select a calendar granularity.');
            if (isset($contract['groupable_fields'][$scope]) && !in_array($field, ['daily', 'metric_date', 'weekly', 'monthly', 'quarterly', 'semiannual', 'yearly', 'annually'], true) && !in_array($field, $contract['groupable_fields'][$scope], true)) {
                throw new InvalidArgumentException('The selected breakdown is unavailable in this reporting scope.');
            }
        }
        if (!empty($filters['snapshot_delta']) || !empty($filters['latest_snapshot'])) throw new InvalidArgumentException('Snapshot queries are unavailable for this daily reporting contract.');
        if (isset($filters['period']) && $filters['period'] !== 'daily') throw new InvalidArgumentException('This contract requires daily source observations; select the output calendar granularity instead.');
        $contract['resolved_rules'] = $resolved;
        $contract['quality'] = $quality;
        return compact('contract', 'filters');
    }
}
