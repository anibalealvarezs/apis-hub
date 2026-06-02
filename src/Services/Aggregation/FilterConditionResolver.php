<?php

    declare(strict_types=1);

    namespace Services\Aggregation;

    final class FilterConditionResolver
    {
        /**
         * @return array{operator: string, value: mixed}
         */
        public function resolve(mixed $rawValue): array
        {
            if (is_object($rawValue) || (is_array($rawValue) && isset($rawValue['operator']))) {
                $op = is_object($rawValue) ? ($rawValue->operator ?? 'eq') : ($rawValue['operator'] ?? 'eq');
                $val = is_object($rawValue) ? ($rawValue->value ?? null) : ($rawValue['value'] ?? null);

                $operator = strtolower(trim((string)$op));

                return match ($operator) {
                    'neq', 'not_equal', '!=', 'ne' => ['operator' => 'neq', 'value' => $val],
                    'is_null', 'null' => ['operator' => 'is_null', 'value' => null],
                    'is_not_null', 'not_null' => ['operator' => 'is_not_null', 'value' => null],
                    'in' => ['operator' => 'in', 'value' => is_array($val) ? array_values($val) : [$val]],
                    'like' => ['operator' => 'like', 'value' => $val],
                    default => ['operator' => 'eq', 'value' => $val],
                };
            }

            if (is_string($rawValue)) {
                $trimmed = trim($rawValue);
                if ($trimmed === 'N/A' || $trimmed === 'NULL') {
                    return ['operator' => 'is_null', 'value' => null];
                }
                if ($trimmed === 'NOT_NULL') {
                    return ['operator' => 'is_not_null', 'value' => null];
                }
                if (str_starts_with($trimmed, '!=')) {
                    return ['operator' => 'neq', 'value' => trim(substr($trimmed, 2))];
                }
            }

            if (is_array($rawValue)) {
                return ['operator' => 'in', 'value' => array_values($rawValue)];
            }

            return ['operator' => 'eq', 'value' => $rawValue];
        }
    }

