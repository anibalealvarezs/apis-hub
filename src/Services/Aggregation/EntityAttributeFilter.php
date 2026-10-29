<?php

declare(strict_types=1);

namespace Services\Aggregation;

use InvalidArgumentException;

/** Shared SQL predicate compiler for legacy and universal aggregation. */
final class EntityAttributeFilter
{
    public function compile(string $column, array $condition, string $parameter, string $type): array
    {
        $op = $condition['operator'];
        $value = $condition['value'];
        if ($op === 'is_null' || $op === 'is_not_null') {
            return ['sql' => $column.($op === 'is_null' ? ' IS NULL' : ' IS NOT NULL'), 'params' => []];
        }
        $numeric = in_array($type, ['integer', 'smallint', 'bigint', 'float', 'decimal'], true);
        $validate = static function ($value) use ($numeric, $type) {
            if (!is_scalar($value) || ($numeric && (!is_numeric($value) || !is_finite((float)$value)))) {
                throw new InvalidArgumentException('Invalid entity attribute filter value');
            }
            if ($type === 'boolean') {
                if (!in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false'], true)) {
                    throw new InvalidArgumentException('Invalid boolean attribute value');
                }
                return in_array($value, [true, 1, '1', 'true'], true) ? '1' : '0';
            }
            return $value;
        };
        if (in_array($op, ['in', 'not_in', 'between'], true)) {
            $values = array_values((array)$value);
            if ($op === 'between' && count($values) !== 2) throw new InvalidArgumentException('Between requires two bounds');
            if (!$values) return ['sql' => $op === 'in' ? '1 = 0' : '1 = 1', 'params' => []];
            $params = [];
            foreach ($values as $index => $item) $params[$parameter.'_'.$index] = $validate($item);
            if ($op === 'between') {
                if ($values[0] > $values[1]) throw new InvalidArgumentException('Lower bound exceeds upper bound');
                return ['sql' => "$column BETWEEN :{$parameter}_0 AND :{$parameter}_1", 'params' => $params];
            }
            $list = implode(', ', array_map(static fn ($key) => ':'.$key, array_keys($params)));
            return ['sql' => $op === 'in' ? "$column IN ($list)" : "($column IS NULL OR $column NOT IN ($list))", 'params' => $params];
        }
        $symbol = match ($op) {
            'eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=',
            'like', 'not_like' => 'LIKE', default => throw new InvalidArgumentException('Unsupported entity attribute operator'),
        };
        $value = $validate($value);
        if (in_array($op, ['like', 'not_like'], true)) {
            if (!in_array($type, ['string', 'text', 'guid', 'ascii_string'], true)) throw new InvalidArgumentException('Text matching requires a text attribute');
            $value = str_contains((string)$value, '%') ? $value : '%'.$value.'%';
            $match = "LOWER($column) ".($op === 'not_like' ? 'NOT LIKE' : 'LIKE')." LOWER(:$parameter)";
            return ['sql' => $op === 'not_like' ? "($column IS NULL OR $match)" : $match, 'params' => [$parameter => $value]];
        }
        return ['sql' => "$column $symbol :$parameter", 'params' => [$parameter => $value]];
    }
}
