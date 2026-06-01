<?php

    declare(strict_types=1);

    namespace Services\Aggregation\Strategies;

    use Doctrine\DBAL\Connection;
    use Entities\Analytics\Channel;
    use Repositories\BaseRepository;
    use Services\Aggregation\AggregationPlan;
    use Services\Aggregation\CanonicalMetricSqlResolver;
    use Services\Aggregation\FilterConditionResolver;
    use Interfaces\OptimizedAggregationStrategyInterface;
    use Traits\OptimizedAggregationHelpersTrait;

    final class UniversalSqlStrategy implements OptimizedAggregationStrategyInterface
    {
        use OptimizedAggregationHelpersTrait;

        public function __construct(
            private readonly ?CanonicalMetricSqlResolver $metricSqlResolver = null,
        )
        {
        }

        public function getKey(): string
        {
            return 'universal_sql';
        }

        public function execute(
            Connection      $connection,
            AggregationPlan $plan,
            bool            $isPostgres
        ): ?array
        {
            $aggregations = $plan->getAggregations();
            $groupBy = $plan->getGroupBy();
            $filters = $plan->getFilters();
            $startDate = $plan->getStartDate();
            $endDate = $plan->getEndDate();
            $orderBy = $plan->getOrderBy();
            $orderDir = $plan->getOrderDir();

            $filtersArr = [];
            if ($filters !== null) {
                foreach ($filters as $key => $value) {
                    $filtersArr[(string)$key] = $value;
                }
            }

            $quoteChar = $isPostgres ? '"' : '`';
            $nameCol = $isPostgres ? 'LOWER(mc.name)' : 'mc.name';
            $periodCol = $isPostgres ? 'LOWER(mc.period)' : 'mc.period';
            $metricSqlResolver = $this->metricSqlResolver ?? new CanonicalMetricSqlResolver();
            $filterResolver = new FilterConditionResolver();
            $channelKey = $this->resolveChannelKey($filtersArr, $plan);
            $resolvedMetrics = [];

            $selectFields = [];
            $groupByFields = [];
            $joins = [];
            $whereClauses = [];
            $sqlParams = [];
            $orderMap = [];

            $relationMap = BaseRepository::getRelationMap();

            if ($startDate !== null) {
                $whereClauses[] = 'm.metric_date >= :startDate';
                $sqlParams['startDate'] = $startDate;
            }

            if ($endDate !== null) {
                $whereClauses[] = 'm.metric_date <= :endDate';
                $sqlParams['endDate'] = $endDate;
            }

            // Track unique joins to avoid duplicate LEFT JOIN clauses
            $joinedTables = [];
            $safeLeftJoin = function (string $table, string $alias, string $condition) use (&$joins, &$joinedTables) {
                if (!isset($joinedTables[$alias])) {
                    $joins[] = "LEFT JOIN $table $alias ON $condition";
                    $joinedTables[$alias] = true;
                }
            };

            // Handle GroupBy
            foreach ($groupBy as $field) {
                $quotedField = preg_match('/^[a-zA-Z0-9_]+$/', $field) ? $field : $quoteChar.$field.$quoteChar;
                $isDimension = str_starts_with($field, 'dimensions.');
                $dimKey = $isDimension ? substr($field, 11) : $field;

                // Check if it's a dynamic dimension
                $isStandardRelation = in_array($field, ['account_type', 'metric_date', 'daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'channel'], true)
                                      || isset($relationMap[$field])
                                      || str_ends_with($field, '_id');

                if (!$isStandardRelation || $isDimension) {
                    // Dimension join logic
                    $dimAlias = 'dim_'.preg_replace('/[^a-z0-9]/i', '_', $dimKey);
                    $sqlParams["key_$dimAlias"] = $dimKey;

                    $safeLeftJoin('dimension_set_items', "dsi_$dimAlias", "mc.dimension_set_id = dsi_$dimAlias.dimension_set_id AND dsi_$dimAlias.dimension_value_id IN (
                    SELECT sub_dv.id FROM dimension_values sub_dv 
                    JOIN dimension_keys sub_dk ON sub_dv.dimension_key_id = sub_dk.id 
                    WHERE LOWER(sub_dk.name) = :key_$dimAlias
                )");
                    $safeLeftJoin('dimension_values', "dv_$dimAlias", "dsi_$dimAlias.dimension_value_id = dv_$dimAlias.id");

                    $selectFields[] = "COALESCE(dv_$dimAlias.value, 'unknown') AS $quotedField";
                    $groupByFields[] = "dv_$dimAlias.value";
                    $orderMap[$field] = "dv_$dimAlias.value";
                    continue;
                }

                // Handle Date grouping
                if (in_array($field, ['daily', 'metric_date'], true)) {
                    $selectFields[] = "m.metric_date AS $quotedField";
                    $groupByFields[] = 'm.metric_date';
                    $orderMap[$field] = 'm.metric_date';
                    continue;
                }

                // Handle Relation
                if (isset($relationMap[$field])) {
                    $map = $relationMap[$field];
                    $alias = $map['alias'];

                    $safeLeftJoin($map['table'], $alias, "$alias.id = mc.{$map['fk']}");

                    $castType = $isPostgres ? 'VARCHAR' : 'CHAR';

                    if (isset($map['isJSON']) && $map['isJSON']) {
                        $jsonPath = $map['jsonPath'] ?? 'name';
                        $sqlField = $isPostgres ? "$alias.data->>'$jsonPath'" : "JSON_UNQUOTE(JSON_EXTRACT($alias.data, '$.$jsonPath'))";

                        $selectFields[] = "COALESCE($sqlField, 'N/A') AS $quotedField";
                        $groupByFields[] = $sqlField;
                        $orderMap[$field] = $sqlField;
                    } elseif (!empty($map['isAttribute'])) {
                        $sqlField = "$alias.{$map['field']}";
                        $selectFields[] = "COALESCE(CAST($sqlField AS $castType), 'N/A') AS $quotedField";
                        $groupByFields[] = $sqlField;
                        $orderMap[$field] = $sqlField;
                    } else {
                        $quotedFieldId = $quoteChar.$field.'_id'.$quoteChar;
                        $selectFields[] = "COALESCE(CAST($alias.{$map['field']} AS $castType), CAST(mc.{$map['fk']} AS $castType), 'Unknown') AS $quotedField";
                        $selectFields[] = "mc.{$map['fk']} AS $quotedFieldId";
                        $groupByFields[] = "$alias.{$map['field']}";
                        $groupByFields[] = "mc.{$map['fk']}";
                        $orderMap[$field] = "COALESCE(CAST($alias.{$map['field']} AS $castType), CAST(mc.{$map['fk']} AS $castType), 'Unknown')";
                        $orderMap[$field.'_id'] = "mc.{$map['fk']}";
                    }
                    continue;
                }

                // Channel
                if ($field === 'channel') {
                    $selectFields[] = "mc.channel AS $quotedField";
                    $groupByFields[] = 'mc.channel';
                    $orderMap[$field] = 'mc.channel';
                    continue;
                }
            }

            // Handle Filters
            foreach ($filtersArr as $key => $value) {
                $isDimension = str_starts_with($key, 'dimensions.');
                $dimKey = $isDimension ? substr($key, 11) : $key;

                $paramName = 'filter_'.preg_replace('/[^a-z0-9]/i', '_', $key);
                $condition = $filterResolver->resolve($value);
                
                // Exclude some internal filters that shouldn't generate SQL WHERE clauses directly
                if (in_array($key, ['snapshot_delta', 'latest_snapshot', 'period'], true)) {
                    continue;
                }

                if (isset($relationMap[$key])) {
                    $whereClauses[] = $this->buildFilterClause("mc.{$relationMap[$key]['fk']}", $condition, $paramName);
                    if ($condition['value'] !== null) {
                        $sqlParams[$paramName] = $this->formatFilterValue($condition['value']);
                    }
                    continue;
                }

                if ($key === 'channel') {
                    $channelId = $condition['value'];
                    if (is_scalar($channelId) && !ctype_digit((string)$channelId)) {
                        $ch = Channel::tryFromName((string)$channelId);
                        if ($ch) $channelId = $ch->getId();
                    }
                    $condition['value'] = $channelId;
                    
                    $whereClauses[] = $this->buildFilterClause('mc.channel', $condition, $paramName);
                    if ($condition['value'] !== null) {
                        $sqlParams[$paramName] = (int)$channelId;
                    }
                    continue;
                }

                // Dynamic dimension filter
                $isStandardRelation = in_array($key, ['account_type', 'metric_date', 'daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'channel'], true)
                                      || isset($relationMap[$key])
                                      || str_ends_with($key, '_id');

                if (!$isStandardRelation || $isDimension) {
                    $dimAlias = 'dim_filter_'.preg_replace('/[^a-z0-9]/i', '_', $dimKey);
                    $sqlParams["key_$dimAlias"] = strtolower($dimKey);

                    $safeLeftJoin('dimension_set_items', "dsi_$dimAlias", "mc.dimension_set_id = dsi_$dimAlias.dimension_set_id AND dsi_$dimAlias.dimension_value_id IN (
                    SELECT sub_dv.id FROM dimension_values sub_dv 
                    JOIN dimension_keys sub_dk ON sub_dv.dimension_key_id = sub_dk.id 
                    WHERE LOWER(sub_dk.name) = :key_$dimAlias
                )");
                    $safeLeftJoin('dimension_values', "dv_$dimAlias", "dsi_$dimAlias.dimension_value_id = dv_$dimAlias.id");

                    $whereClauses[] = $this->buildFilterClause("dv_$dimAlias.value", $condition, $paramName);
                    if ($condition['value'] !== null) {
                        $sqlParams[$paramName] = $this->formatFilterValue($condition['value']);
                    }
                    continue;
                }
            }

            foreach ($aggregations as $alias => $expr) {
                $normalizedExpr = strtolower(trim((string)$expr));
                $resolvedMetric = $metricSqlResolver->resolveMarketingMetric(
                    requestedMetric: $normalizedExpr,
                    channel: $channelKey,
                    nameCol: $nameCol,
                    periodCol: $periodCol,
                );
                $metricSql = is_string($resolvedMetric['sql_expression']) ? $resolvedMetric['sql_expression'] : null;
                
                if ($metricSql === null) {
                    // Try organic metric as fallback
                    $resolvedOrganicMetric = $metricSqlResolver->resolveOrganicMetric(
                        requestedMetric: $normalizedExpr,
                        channel: $channelKey,
                        nameCol: $nameCol,
                        periodCol: $periodCol,
                        period: 'lifetime'
                    );

                    if (is_string($resolvedOrganicMetric['sql_expression'])) {
                        $metricSql = $resolvedOrganicMetric['sql_expression'];
                        $resolvedMetric = $resolvedOrganicMetric;
                    }
                }
                
                if ($metricSql === null) {
                    $repository = $plan->getContextValue('repository');
                    if ($repository instanceof BaseRepository) {
                        $repository->appendOptimizedStrategyMeta([
                            'strategy_fallback_reason' => 'missing_metric_equivalence_in_universal',
                            'metric_resolution'        => [
                                'channel'          => $channelKey,
                                'strategy'         => $this->getKey(),
                                'requested_metric' => $resolvedMetric['requested_metric'] ?? $normalizedExpr,
                                'canonical_metric' => $resolvedMetric['canonical_metric'] ?? null,
                                'input_type'       => $resolvedMetric['input_type'] ?? 'unknown',
                                'legacy_alias_of'  => $resolvedMetric['legacy_alias_of'] ?? null,
                                'deprecation'      => $resolvedMetric['deprecation'] ?? null,
                                'source'           => $resolvedMetric['source'] ?? 'none',
                                'raw_names'        => $resolvedMetric['raw_names'] ?? [],
                                'missing'          => true,
                            ],
                        ]);
                    }

                    return null;
                }

                $safeAlias = preg_replace('/[^a-z0-9_]/i', '_', (string)$alias) ?: (string)$alias;
                $quotedAlias = $quoteChar.$safeAlias.$quoteChar;
                $selectFields[] = $metricSql.' AS '.$quotedAlias;
                $orderMap[strtolower($safeAlias)] = $quotedAlias;

                $resolvedMetrics[] = [
                    'alias'            => $safeAlias,
                    'requested_metric' => $resolvedMetric['requested_metric'] ?? $normalizedExpr,
                    'canonical_metric' => $resolvedMetric['canonical_metric'] ?? null,
                    'input_type'       => $resolvedMetric['input_type'] ?? 'unknown',
                    'legacy_alias_of'  => $resolvedMetric['legacy_alias_of'] ?? null,
                    'deprecation'      => $resolvedMetric['deprecation'] ?? null,
                    'raw_names'        => $resolvedMetric['raw_names'] ?? [],
                    'source'           => $resolvedMetric['source'] ?? 'none',
                ];
            }
            
            // If there are no select fields (e.g., no aggregations requested), we can't execute
            if ($selectFields === []) {
                return null;
            }

            $repository = $plan->getContextValue('repository');
            if ($repository instanceof BaseRepository) {
                $repository->appendOptimizedStrategyMeta([
                    'metric_resolution' => [
                        'channel'          => $channelKey,
                        'strategy'         => $this->getKey(),
                        'resolved_metrics' => $resolvedMetrics,
                    ],
                ]);
            }

            $orderSql = '';
            if ($orderBy !== null && trim($orderBy) !== '') {
                $direction = strtoupper((string)$orderDir) === 'DESC' ? 'DESC' : 'ASC';
                $safeOrderBy = strtolower((string)preg_replace('/[^a-z0-9_.]/i', '', $orderBy));
                $orderField = $orderMap[$safeOrderBy] ?? null;
                if ($orderField !== null) {
                    $orderSql = " ORDER BY $orderField $direction";
                }
            }

            $whereSql = $whereClauses !== [] ? "WHERE " . implode("\n              AND ", $whereClauses) : "";
            $groupSql = $groupByFields !== [] ? "GROUP BY\n                    " . implode(",\n            ", $groupByFields) : "";

            $sql = "SELECT
                ".implode(",\n                ", $selectFields)."
                FROM metrics m
                JOIN metric_configs mc ON m.metric_config_id = mc.id
                ".implode("\n        ", $joins)."
                $whereSql
                $groupSql
                $orderSql";

            return $connection->fetchAllAssociative($sql, $sqlParams);
        }
        
        private function buildFilterClause(string $col, array $condition, string $alias): string
        {
            return match ($condition['operator']) {
                'neq'         => "$col <> :$alias",
                'is_null'     => "$col IS NULL",
                'is_not_null' => "$col IS NOT NULL",
                'in'          => "$col IN (:$alias)",
                'eq'          => "$col = :$alias",
                default       => "$col = :$alias",
            };
        }
        
        private function formatFilterValue(mixed $value): mixed
        {
            if (is_array($value)) {
                // DBAL can handle arrays for IN clauses if connection is properly configured, 
                // but usually it requires Connection::PARAM_INT_ARRAY or similar.
                // For simplicity, we just pass the array and let DBAL try to bind it.
                return $value;
            }
            if (is_bool($value)) {
                return $value ? 1 : 0;
            }
            return $value;
        }

        /**
         * @param array<string, mixed> $filtersArr
         */
        private function resolveChannelKey(array $filtersArr, ?AggregationPlan $plan = null): ?string
        {
            if (!array_key_exists('channel', $filtersArr)) {
                if ($plan instanceof AggregationPlan) {
                    $ctxChannel = $plan->getContextValue('channel');
                    if (is_string($ctxChannel)) {
                        return $ctxChannel;
                    }
                }

                return null;
            }

            $value = $filtersArr['channel'];
            if (is_object($value) && property_exists($value, 'value')) {
                $value = $value->value;
            }

            if (!is_scalar($value)) {
                return null;
            }

            $normalized = strtolower(trim((string)$value));
            if ($normalized === '') {
                return null;
            }

            if (!ctype_digit($normalized)) {
                return $normalized;
            }

            $channel = Channel::tryFrom((int)$normalized);

            return $channel instanceof Channel ? strtolower(trim($channel->getName())) : null;
        }
    }
