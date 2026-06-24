<?php

    declare(strict_types=1);

    namespace Traits;

    use Anibalealvarezs\ApiDriverCore\Classes\MetricAggregationStrategyRegistry;
    use Exceptions\ConfigurationException;
    use Helpers\Helpers;

    trait OptimizedAggregationHelpersTrait
    {
        /**
         * @param array<string, string> $aggregations
         * @return array<string, array<string, mixed>>
         * @throws ConfigurationException
         */
        protected function resolveWeightedAggregationStrategies(array $aggregations): array
        {
            $strategies = [];
            $isPostgres = Helpers::isPostgres();
            foreach ($aggregations as $alias => $expr) {
                $normalizedExpr = strtolower(trim($expr));
                $strategy = MetricAggregationStrategyRegistry::resolve($normalizedExpr);
                if ($strategy === null || ($strategy['method'] ?? null) !== MetricAggregationStrategyRegistry::METHOD_WEIGHTED_BY_METRIC) {
                    continue;
                }

                $safeAlias = preg_replace('/[^a-z0-9_]/i', '_', $alias) ?: $alias;
                $strategies[$safeAlias] = [
                    ...$strategy,
                    'alias'               => $safeAlias,
                    'quoted_alias'        => $isPostgres ? '"'.$safeAlias.'"' : '`'.$safeAlias.'`',
                    'prefix'              => 'wm_'.count($strategies),
                    'source_metric_names' => array_values(array_unique(array_map('strtolower', (array)($strategy['source_metric_names'] ?? [$normalizedExpr])))),
                    'weight_metric_names' => array_values(array_unique(array_map('strtolower', (array)($strategy['weight_metric_names'] ?? [])))),
                ];

                if ($strategies[$safeAlias]['weight_metric_names'] === []) {
                    unset($strategies[$safeAlias]);
                }
            }

            return $strategies;
        }

        /**
         * @param array<int, string> $values
         */
        protected function toSqlStringList(array $values): string
        {
            $escaped = array_map(static function (string $value): string {
                return "'".str_replace("'", "''", strtolower(trim($value)))."'";
            }, $values);

            return implode(',', $escaped);
        }

        /**
         * @return array<int, string>
         */
        protected function expandGroupPatternToFields(string $groupPattern): array
        {
            if (str_contains($groupPattern, '+')) {
                return array_values(array_filter(array_map('trim', explode('+', $groupPattern)), static fn($f) => $f !== ''));
            }

            return match ($groupPattern) {
                'none' => [],
                default => [$groupPattern],
            };
        }

        /**
         * @param array{operator: string, value: mixed} $condition
         */
        protected function isSentinelEmptyFilter(array $condition): bool
        {
            if ($condition['operator'] === 'in' && is_array($condition['value'])) {
                foreach ($condition['value'] as $v) {
                    if (is_numeric($v)) {
                        return false;
                    }
                }
                return true;
            }

            return false;
        }
    }
