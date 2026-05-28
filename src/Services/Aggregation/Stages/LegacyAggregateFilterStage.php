<?php

    declare(strict_types=1);

    namespace Services\Aggregation\Stages;

    use Services\Aggregation\LegacyAggregateExecutionContext;

    final class LegacyAggregateFilterStage
    {
        /**
         * @param array<string, array<string, mixed>> $relationMap
         * @param callable(string, bool=): string $mapFieldToSql
         * @param callable(mixed): array{operator: string, value: mixed} $resolveFilterCondition
         * @param callable(string): bool $hasEntityField
         */
        public function apply(
            LegacyAggregateExecutionContext $context,
            array                           $relationMap,
            bool                            $isChanneledMetric,
            callable                        $mapFieldToSql,
            callable                        $resolveFilterCondition,
            callable                        $hasEntityField,
        ): void
        {
            $qb = $context->getQueryBuilder();
            $filters = $context->getFilters();
            if (!$filters) {
                return;
            }

            $standardRelations = (array)$context->getRelationContextValue('standardRelations', []);
            $dateFields = (array)$context->getRelationContextValue('dateFields', []);
            $safeLeftJoin = $context->getRelationContextValue('safeLeftJoin');
            $joinRelation = $context->getRelationContextValue('joinRelation');

            if (!is_callable($safeLeftJoin) || !is_callable($joinRelation)) {
                return;
            }

            foreach ($filters as $key => $value) {
                if ($key === 'debug_sql' || $key === '_' || $key === 'latest_snapshot' || $key === 'snapshot_delta' || $key === 'snapshot_fallback_mode') {
                    continue;
                }

                $isDimension = str_starts_with($key, 'dimensions.');
                $dimKey = $isDimension ? substr($key, 11) : $key;

                if ($isChanneledMetric && ($isDimension || ($key !== 'account_type' && !in_array($key, $standardRelations, true) && !in_array($key, $dateFields, true) && !$hasEntityField($key)))) {
                    $dimAlias = 'f_dim_'.preg_replace('/[^a-z0-9]/i', '_', $dimKey);
                    $condition = $resolveFilterCondition($value);
                    $safeLeftJoin('e', 'dimension_set_items', "dsi_$dimAlias", "e.dimension_set_id = dsi_$dimAlias.dimension_set_id AND dsi_$dimAlias.dimension_value_id IN (
                    SELECT sub_dv.id FROM dimension_values sub_dv 
                    JOIN dimension_keys sub_dk ON sub_dv.dimension_key_id = sub_dk.id 
                    WHERE sub_dk.name = :key_$dimAlias
                )");
                    $safeLeftJoin("dsi_$dimAlias", 'dimension_values', "dv_$dimAlias", "dsi_$dimAlias.dimension_value_id = dv_$dimAlias.id");

                    $qb->setParameter("key_$dimAlias", $dimKey);
                    if ($condition['operator'] === 'eq') {
                        $qb->andWhere("dv_$dimAlias.value = :val_$dimAlias")
                            ->setParameter("val_$dimAlias", $condition['value']);
                    } elseif ($condition['operator'] === 'neq') {
                        $qb->andWhere("dv_$dimAlias.value <> :val_$dimAlias")
                            ->setParameter("val_$dimAlias", $condition['value']);
                    } elseif ($condition['operator'] === 'in') {
                        $qb->andWhere("dv_$dimAlias.value IN (:val_$dimAlias)")
                            ->setParameter("val_$dimAlias", $condition['value'], \Doctrine\DBAL\Connection::PARAM_STR_ARRAY);
                    } elseif ($condition['operator'] === 'like') {
                        $qb->andWhere("dv_$dimAlias.value LIKE :val_$dimAlias")
                            ->setParameter("val_$dimAlias", $condition['value']);
                    } elseif ($condition['operator'] === 'is_null') {
                        $qb->andWhere("dv_$dimAlias.value IS NULL");
                    } elseif ($condition['operator'] === 'is_not_null') {
                        $qb->andWhere("dv_$dimAlias.value IS NOT NULL");
                    }
                    continue;
                }

                if ((str_ends_with($context->getEntityName(), 'Metric') || $isChanneledMetric) && (isset($relationMap[$key]) || $key === 'account_type')) {
                    $realKey = ($key === 'account_type') ? 'channeledAccount' : $key;
                    $map = $relationMap[$realKey];
                    $fk = $map['fk'] ?? null;

                    if ($key === 'account_type') {
                        $joinRelation($realKey);
                    }

                    if ($value === 'N/A' || $value === 'NULL') {
                        $nullTarget = ($key === 'page') ? 'mc.page_id' : "mc.$fk";
                        $qb->andWhere("$nullTarget IS NULL");
                    } elseif ($value === 'NOT_NULL') {
                        $nullTarget = ($key === 'page') ? 'mc.page_id' : "mc.$fk";
                        $qb->andWhere("$nullTarget IS NOT NULL");
                    } elseif ($key === 'account_type') {
                        $typeFilter = $context->isPostgres() ? "LOWER({$map['alias']}.type) = LOWER(:f_$key)" : "{$map['alias']}.type = :f_$key";
                        $qb->andWhere($typeFilter)
                            ->setParameter("f_$key", $value);
                    } elseif (!empty($map['isAttribute'])) {
                        $joinRelation($realKey);
                        $sqlKey = $mapFieldToSql($key);
                        $sqlKeyComparable = $context->isPostgres() ? "CAST($sqlKey AS TEXT)" : "CAST($sqlKey AS CHAR)";
                        $paramName = 'f_'.preg_replace('/[^a-z0-9]/i', '_', $key);
                        $condition = $resolveFilterCondition($value);

                        if ($condition['operator'] === 'is_null') {
                            $qb->andWhere("$sqlKey IS NULL");
                        } elseif ($condition['operator'] === 'is_not_null') {
                            $qb->andWhere("$sqlKey IS NOT NULL");
                        } elseif ($condition['operator'] === 'neq') {
                            $qb->andWhere("$sqlKeyComparable <> :$paramName")
                                ->setParameter($paramName, (string)$condition['value']);
                        } elseif ($condition['operator'] === 'in') {
                            $qb->andWhere("$sqlKeyComparable IN (:$paramName)")
                                ->setParameter($paramName, $condition['value'], \Doctrine\DBAL\Connection::PARAM_STR_ARRAY);
                        } elseif ($condition['operator'] === 'like') {
                            $qb->andWhere("$sqlKeyComparable LIKE :$paramName")
                                ->setParameter($paramName, (string)$condition['value']);
                        } else {
                            $qb->andWhere("$sqlKeyComparable = :$paramName")
                                ->setParameter($paramName, (string)$condition['value']);
                        }
                    } else {
                        $targetCol = ($key === 'page') ? 'mc.page_id' : "mc.$fk";
                        if (is_numeric($value)) {
                            $qb->andWhere("$targetCol = :f_$key")
                                ->setParameter("f_$key", (int)$value);
                        } else {
                            $qb->andWhere('1 = 0');
                        }
                    }

                    continue;
                }

                $sqlKey = $mapFieldToSql($key);
                $paramName = 'f_'.preg_replace('/[^a-z0-9]/i', '_', $key);
                $condition = $resolveFilterCondition($value);

                if ($condition['operator'] === 'is_null') {
                    $qb->andWhere("$sqlKey IS NULL");
                } elseif ($condition['operator'] === 'is_not_null') {
                    $qb->andWhere("$sqlKey IS NOT NULL");
                } elseif ($condition['operator'] === 'neq') {
                    $qb->andWhere("$sqlKey <> :$paramName")
                        ->setParameter($paramName, $condition['value']);
                } elseif ($condition['operator'] === 'in') {
                    $qb->andWhere("$sqlKey IN (:$paramName)")
                        ->setParameter($paramName, $condition['value'], \Doctrine\DBAL\Connection::PARAM_STR_ARRAY);
                } elseif ($condition['operator'] === 'like') {
                    $qb->andWhere("$sqlKey LIKE :$paramName")
                        ->setParameter($paramName, $condition['value']);
                } else {
                    $qb->andWhere("$sqlKey = :$paramName")
                        ->setParameter($paramName, $condition['value']);
                }
            }
        }
    }

