<?php

declare(strict_types=1);

namespace Services\Aggregation;

/** A scope is part of the full grain, so it can safely prune the weight scan. */
final class CompanionScopeFilter
{
    public function compile(array $contract, array $filters): ?array
    {
        $field = $contract['scope_filter'] ?? null;
        if (!is_string($field) || !str_starts_with($field, 'dimensions.') || !array_key_exists($field, $filters)) return null;
        $condition = (new FilterConditionResolver)->resolve($filters[$field]);
        if ($condition['operator'] !== 'eq' || !is_scalar($condition['value'])) return null;
        return [
            'sql' => 'wc.dimension_set_id IN (SELECT wi.dimension_set_id FROM dimension_set_items wi JOIN dimension_values wv ON wv.id = wi.dimension_value_id JOIN dimension_keys wk ON wk.id = wv.dimension_key_id WHERE LOWER(wk.name) = :companion_scope_key AND wv.value = :companion_scope_value)',
            'params' => ['companion_scope_key' => strtolower(substr($field, 11)), 'companion_scope_value' => (string)$condition['value']],
        ];
    }
}
