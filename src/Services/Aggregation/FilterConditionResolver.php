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
            if (is_object($rawValue)) {
                $operator = strtolower(trim($rawValue->operator ?? 'eq'));
                $value = $rawValue->value ?? null;

                return match ($operator) {
                    'neq', 'not_equal', '!=', 'ne' => ['operator' => 'neq', 'value' => $value],
                    'is_null', 'null' => ['operator' => 'is_null', 'value' => null],
                    'is_not_null', 'not_null' => ['operator' => 'is_not_null', 'value' => null],
                    default => ['operator' => 'eq', 'value' => $value],
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

            return ['operator' => 'eq', 'value' => $rawValue];
        }
    }

