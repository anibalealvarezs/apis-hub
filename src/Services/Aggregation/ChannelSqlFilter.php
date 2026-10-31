<?php

declare(strict_types=1);

namespace Services\Aggregation;

/** Resolve channel names inside SQL without consulting the ambient ORM manager. */
final class ChannelSqlFilter
{
    public function compile(array $condition, string $parameter): array
    {
        $operator = $condition['operator'];
        if (in_array($operator, ['is_null', 'is_not_null'], true)) {
            return ['sql' => 'mc.channel IS '.($operator === 'is_not_null' ? 'NOT ' : '').'NULL', 'params' => []];
        }
        if (!in_array($operator, ['eq', 'neq', 'in', 'not_in'], true)) throw new \InvalidArgumentException('Select channels by ID or name using equality or membership.');
        $negative = in_array($operator, ['neq', 'not_in'], true);
        $values = is_array($condition['value']) ? $condition['value'] : [$condition['value']];
        $ids = []; $names = []; $params = [];
        foreach ($values as $index => $value) {
            if (!is_scalar($value) || trim((string)$value) === '') throw new \InvalidArgumentException('Invalid channel filter.');
            $key = $parameter.'_'.$index;
            if (ctype_digit((string)$value)) { $ids[] = ':'.$key; $params[$key] = (int)$value; }
            else { $names[] = ':'.$key; $params[$key] = strtolower(trim((string)$value)); }
        }
        $membership = $negative ? 'NOT IN' : 'IN';
        $parts = [];
        if ($ids) $parts[] = 'mc.channel '.$membership.' ('.implode(', ', $ids).')';
        if ($names) $parts[] = 'mc.channel '.$membership.' (SELECT id FROM channels WHERE name IN ('.implode(', ', $names).'))';
        return ['sql' => $parts ? '('.implode($negative ? ' AND ' : ' OR ', $parts).')' : ($negative ? '1 = 1' : '1 = 0'), 'params' => $params];
    }
}
