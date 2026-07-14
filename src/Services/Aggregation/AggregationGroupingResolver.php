<?php

    declare(strict_types=1);

    namespace Services\Aggregation;

    final class AggregationGroupingResolver
    {
        /**
         * @param array<int, string> $groupBy
         */
        public function resolveGroupPattern(array $groupBy): ?string
        {
            if ($groupBy === []) {
                return 'none';
            }

            $rawFields = array_values(array_map(static fn($field) => trim((string)$field), $groupBy));
            $normalized = array_values(array_map(static fn($field) => strtolower($field), $rawFields));

            if (count($normalized) === 1) {
                $field = $normalized[0];
                $rawField = $rawFields[0];
                if (in_array($field, ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'], true)) {
                    return $field;
                }

                if (in_array($field, ['query', 'page', 'country', 'device'], true)) {
                    return $field;
                }

                if (str_starts_with(strtolower($rawField), 'dimensions.') && strlen($rawField) > 11) {
                    return 'dimensions.'.substr($rawField, 11);
                }
            }

            $allDimensions = array_reduce($rawFields, static fn(bool $carry, string $field): bool => $carry && str_starts_with(strtolower($field), 'dimensions.'), true);
            if ($allDimensions) {
                $dimensionFields = array_map(static fn(string $field): string => 'dimensions.'.substr($field, 11), $rawFields);
                usort($dimensionFields, static fn(string $left, string $right): int => strcmp(strtolower($left), strtolower($right)));

                return implode('+', $dimensionFields);
            }

            $knownEntityFields = ['query', 'page', 'country', 'device'];
            $allEntities = count($normalized) >= 2
                && array_reduce($normalized, static fn(bool $carry, string $field): bool => $carry && in_array($field, $knownEntityFields, true), true);
            if ($allEntities) {
                sort($normalized);

                return implode('+', $normalized);
            }

            if (count($normalized) === 2 && in_array('daily', $normalized) && in_array('channeledcampaign', $normalized)) {
                return 'daily+channeledCampaign';
            }

            return null;
        }

        /**
         * @param array<string, mixed> $filtersArr
         * @return array<int, string>
         */
        public function resolveOptimizedDimensionKeys(string $groupPattern, array $filtersArr, array $relationMap): array
        {
            $dimensionKeys = [];

            if (str_contains($groupPattern, '+')) {
                $fields = array_values(array_filter(array_map('trim', explode('+', $groupPattern)), static fn($f) => $f !== ''));
            } else {
                $fields = $groupPattern === 'none' ? [] : [$groupPattern];
            }

            foreach ($fields as $field) {
                if (str_starts_with($field, 'dimensions.')) {
                    $dimensionKeys[] = trim(substr($field, 11));
                }
            }

            foreach (array_keys($filtersArr) as $key) {
                if (!str_starts_with((string)$key, 'dimensions.')) {
                    continue;
                }

                $dimensionKeys[] = trim(substr((string)$key, 11));
            }

            $excludedKeys = $this->getOptimizedDimensionSetExcludedKeys($relationMap);
            $dimensionKeys = array_values(array_unique(array_filter(
                $dimensionKeys,
                static fn($key) => !in_array($key, $excludedKeys, true)
            )));

            usort($dimensionKeys, function (string $left, string $right): int {
                $priority = array_flip(['query', 'country', 'device']);

                return ($priority[$left] ?? PHP_INT_MAX) <=> ($priority[$right] ?? PHP_INT_MAX);
            });

            return $dimensionKeys;
        }

        public function getOptimizedDimensionSetExcludedKeys(array $relationMap): array
        {
            return [];
        }

        /**
         * @param array<int, string> $dimensionKeys
         */
        public function buildOptimizedDimensionSetWhereSql(array $dimensionKeys): string
        {
            $where = "";
            foreach ($dimensionKeys as $key) {
                $safeKey = str_replace("'", "''", $key);
                $where .= "\n            AND EXISTS (
                SELECT 1 
                FROM dimension_set_items dsi 
                JOIN dimension_values dv ON dv.id = dsi.dimension_value_id 
                JOIN dimension_keys dk ON dk.id = dv.dimension_key_id 
                WHERE dsi.dimension_set_id = mc.dimension_set_id 
                AND LOWER(dk.name) = LOWER('$safeKey')
            )";
            }

            // Exclude known parallel columns from the set if they are not explicitly requested as dimensions
            $excluded = [];
            foreach (['query', 'country', 'device'] as $potential) {
                if (!in_array($potential, $dimensionKeys, true)) {
                    $excluded[] = $potential;
                }
            }

            if ($excluded !== []) {
                $excludedSql = implode(', ', array_map(static fn($k) => "'".str_replace("'", "''", $k)."'", $excluded));
                $where .= "\n            AND mc.dimension_set_id NOT IN (
                SELECT dimension_set_id 
                FROM dimension_set_items dsi 
                JOIN dimension_values dv ON dv.id = dsi.dimension_value_id 
                JOIN dimension_keys dk ON dk.id = dv.dimension_key_id 
                WHERE dk.name IN ($excludedSql)
            )";
            }

            return $where;
        }

        private function expandGroupPatternToFields(string $groupPattern): array
        {
            if ($groupPattern === 'none') {
                return [];
            }

            if (str_contains($groupPattern, '+')) {
                return array_values(array_filter(array_map('trim', explode('+', $groupPattern)), static fn($f) => $f !== ''));
            }

            return [$groupPattern];
        }

        public function buildWeightedGroupingConfig(string $groupPattern, bool $isPostgres, string $quoteChar, array $relationMap = []): ?array
        {
            $fields = $this->expandGroupPatternToFields($groupPattern);
            if ($fields === []) {
                return [
                    'final_select'   => [],
                    'group_by'       => [],
                    'joins'          => [],
                    'outer_select'   => [],
                    'order_map'      => [],
                    'configs_select' => [],
                    'configs_joins'  => [],
                ];
            }

            $isAllDimensions = array_reduce($fields, static fn(bool $carry, string $field): bool => $carry && str_starts_with($field, 'dimensions.'), true);
            if ($isAllDimensions) {
                return $this->buildDimensionSetCombinationGroupingConfig($fields, $isPostgres, $quoteChar);
            }

            $isAllEntities = array_reduce($fields, static fn(bool $carry, string $field): bool => $carry && (in_array($field, ['query', 'page', 'country', 'device', 'daily', 'channeledCampaign'], true)), true);
            if ($isAllEntities) {
                return $this->buildEntityCombinationGroupingConfig($fields, $isPostgres, $quoteChar, $relationMap);
            }

            return null;
        }

        private function buildEntityCombinationGroupingConfig(array $fields, bool $isPostgres, string $quoteChar, array $relationMap): array
        {
            $finalSelect = [];
            $groupBy = [];
            $joins = [];
            $outerSelect = [];
            $orderMap = [];
            $configsSelect = [];
            $configsJoins = [];

            foreach ($fields as $field) {
                if ($field === 'daily') {
                    $alias = $quoteChar.'daily'.$quoteChar;
                    $finalSelect[] = "m.metric_date AS $alias";
                    $groupBy[] = $alias;
                    $outerSelect[] = $alias;
                    $orderMap['daily'] = "f.$alias";
                    $orderMap['date'] = "f.$alias";
                    continue;
                }

                $alias = $quoteChar.$field.$quoteChar;
                $relation = $relationMap[$field] ?? null;

                if ($relation) {
                    $table = $relation['table'];
                    $fk = $relation['fk'];
                    $semanticField = $relation['field'];
                    $tAlias = $relation['alias'] ?? "t_$field";

                    $configsJoins[] = "LEFT JOIN $table $tAlias ON $tAlias.id = mc.$fk";
                    $configsSelect[] = "COALESCE($tAlias.$semanticField, 'N/A') AS $alias";
                    $finalSelect[] = "mc.$alias";
                    $groupBy[] = $alias;
                    $outerSelect[] = $alias;
                    $orderMap[$field] = "f.$alias";
                }
            }

            return [
                'final_select'   => $finalSelect,
                'group_by'       => $groupBy,
                'joins'          => [], // Joins are now in configs_joins
                'outer_select'   => $outerSelect,
                'order_map'      => $orderMap,
                'configs_select' => $configsSelect,
                'configs_joins'  => $configsJoins,
            ];
        }

        private function buildDimensionSetCombinationGroupingConfig(array $fields, bool $isPostgres, string $quoteChar): array
        {
            $finalSelect = [];
            $groupBy = [];
            $outerSelect = [];
            $orderMap = [];
            $configsSelect = [];
            $configsJoins = [];

            foreach ($fields as $field) {
                $dkName = str_replace('dimensions.', '', $field);
                $alias = $quoteChar.$field.$quoteChar;
                $safeDk = preg_replace('/[^a-z0-9]/i', '_', $dkName);
                $dvAlias = "dv_$safeDk";
                $dsiAlias = "dsi_$safeDk";
                $dkAlias = "dk_$safeDk";

                $tAlias = "t_$safeDk";
                $configsJoins[] = "LEFT JOIN (
                SELECT dsi.dimension_set_id, dv.value
                FROM dimension_set_items dsi
                JOIN dimension_values dv ON dv.id = dsi.dimension_value_id
                JOIN dimension_keys dk ON dk.id = dv.dimension_key_id
                WHERE LOWER(dk.name) = LOWER('".str_replace("'", "''", $dkName)."')
            ) $tAlias ON $tAlias.dimension_set_id = mc.dimension_set_id";

                // Fallback dimension for metrics that store page-like data under landing_page
                $fbExpr = "'N/A'";
                if ($dkName === 'page') {
                    $fbAlias = "t_landing_page";
                    $configsJoins[] = "LEFT JOIN (
                    SELECT dsi.dimension_set_id, dv.value
                    FROM dimension_set_items dsi
                    JOIN dimension_values dv ON dv.id = dsi.dimension_value_id
                    JOIN dimension_keys dk ON dk.id = dv.dimension_key_id
                    WHERE LOWER(dk.name) = LOWER('landing_page')
                ) $fbAlias ON $fbAlias.dimension_set_id = mc.dimension_set_id";
                    $fbExpr = "$fbAlias.value, 'N/A'";
                }

                $configsSelect[] = "COALESCE($tAlias.value, $fbExpr) AS $alias";
                $finalSelect[] = "mc.$alias";
                $groupBy[] = $alias;
                $outerSelect[] = $alias;
                $orderMap[$field] = "f.$alias";
            }

            return [
                'final_select'   => $finalSelect,
                'group_by'       => $groupBy,
                'joins'          => [], // Joins are now in configs_joins
                'outer_select'   => $outerSelect,
                'order_map'      => $orderMap,
                'configs_select' => $configsSelect,
                'configs_joins'  => $configsJoins,
            ];
        }
    }
