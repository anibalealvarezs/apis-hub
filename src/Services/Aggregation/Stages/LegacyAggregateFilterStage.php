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

                $baseKey = preg_replace('/__\d+$/', '', (string)$key);
                $isDimension = str_starts_with($key, 'dimensions.') || str_starts_with($baseKey, 'dimensions.');
                $rawDimKey = $isDimension ? substr($key, 11) : $key;
                $dimKey = preg_replace('/__\d+$/', '', $rawDimKey);

                if ($isDimension && in_array($dimKey, ['intent', 'language', 'brand_relation', 'business_relevance'], true)) {
                    $isAccountSemantic = in_array($dimKey, ['brand_relation', 'business_relevance'], true);
                    $semTable = $isAccountSemantic ? 'account_query_classifications' : 'query_classifications';
                    $valParam = 'val_'.preg_replace('/[^a-z0-9]/i', '_', $key);
                    $condition = $resolveFilterCondition($value);
                    $semCol = $dimKey;
                    $semColSql = $context->isPostgres() ? "sem.$semCol" : "LOWER(sem.$semCol)";
                    $likeOp = $context->isPostgres() ? "ILIKE" : "LIKE";
                    $likeParamSql = $context->isPostgres() ? ":$valParam" : "LOWER(:$valParam)";
                    $accountScope = $isAccountSemantic ? " AND sem.channeled_account_id = e.channeled_account_id" : "";

                    if ($condition['operator'] === 'is_null') {
                        $qb->andWhere("(e.query_id IS NULL OR e.query_id NOT IN (SELECT sem.query_id FROM $semTable sem WHERE sem.$semCol IS NOT NULL$accountScope))");
                    } elseif ($condition['operator'] === 'is_not_null') {
                        $qb->andWhere("e.query_id IN (SELECT sem.query_id FROM $semTable sem WHERE sem.$semCol IS NOT NULL$accountScope)");
                    } elseif (in_array($condition['operator'], ['like', 'not_like'], true)) {
                        $valStr = (string)$condition['value'];
                        $valPattern = str_contains($valStr, '%') ? $valStr : "%{$valStr}%";
                        if ($condition['operator'] === 'not_like') {
                            $qb->andWhere("(e.query_id IS NULL OR e.query_id NOT IN (SELECT sem.query_id FROM $semTable sem WHERE $semColSql $likeOp $likeParamSql$accountScope))")
                                ->setParameter($valParam, $valPattern);
                        } else {
                            $qb->andWhere("e.query_id IN (SELECT sem.query_id FROM $semTable sem WHERE $semColSql $likeOp $likeParamSql$accountScope)")
                                ->setParameter($valParam, $valPattern);
                        }
                    } else {
                        $isNegative = in_array($condition['operator'], ['neq', 'not_in'], true);
                        $op = $isNegative ? 'NOT IN' : 'IN';
                        $sub = "(SELECT sem.query_id FROM $semTable sem WHERE sem.$semCol IN (:$valParam)$accountScope)";
                        $qb->andWhere($isNegative ? "(e.query_id IS NULL OR e.query_id NOT IN $sub)" : "e.query_id IN $sub");
                        $values = is_array($condition['value']) ? array_values($condition['value']) : [$condition['value']];
                        $qb->setParameter($valParam, $values, \Doctrine\DBAL\ArrayParameterType::STRING);
                    }
                    continue;
                }

                if ($isChanneledMetric && ($isDimension || ($baseKey !== 'account_type' && !in_array($baseKey, $standardRelations, true) && !in_array($baseKey, $dateFields, true) && !$hasEntityField($baseKey)))) {
                    $dimAlias = 'f_dim_'.preg_replace('/[^a-z0-9]/i', '_', $dimKey);
                    $valParam = 'val_'.preg_replace('/[^a-z0-9]/i', '_', $key);
                    $condition = $resolveFilterCondition($value);
                    $safeLeftJoin('e', 'dimension_set_items', "dsi_$dimAlias", "e.dimension_set_id = dsi_$dimAlias.dimension_set_id AND dsi_$dimAlias.dimension_value_id IN (
                    SELECT sub_dv.id FROM dimension_values sub_dv 
                    JOIN dimension_keys sub_dk ON sub_dv.dimension_key_id = sub_dk.id 
                    WHERE sub_dk.name = :key_$dimAlias
                )");
                    $safeLeftJoin("dsi_$dimAlias", 'dimension_values', "dv_$dimAlias", "dsi_$dimAlias.dimension_value_id = dv_$dimAlias.id");

                    $qb->setParameter("key_$dimAlias", $dimKey);
                    if ($condition['operator'] === 'eq') {
                        $qb->andWhere("dv_$dimAlias.value = :$valParam")
                            ->setParameter($valParam, $condition['value']);
                    } elseif ($condition['operator'] === 'neq') {
                        $qb->andWhere("dv_$dimAlias.value <> :$valParam")
                            ->setParameter($valParam, $condition['value']);
                    } elseif ($condition['operator'] === 'in') {
                        $qb->andWhere("dv_$dimAlias.value IN (:$valParam)")
                            ->setParameter($valParam, $condition['value'], \Doctrine\DBAL\ArrayParameterType::STRING);
                    } elseif ($condition['operator'] === 'like') {
                        $valStr = (string)$condition['value'];
                        $valPattern = str_contains($valStr, '%') ? $valStr : "%{$valStr}%";
                        $likeClause = $context->isPostgres() ? "dv_$dimAlias.value ILIKE :$valParam" : "LOWER(dv_$dimAlias.value) LIKE LOWER(:$valParam)";
                        $qb->andWhere($likeClause)
                            ->setParameter($valParam, $valPattern);
                    } elseif ($condition['operator'] === 'not_like') {
                        $valStr = (string)$condition['value'];
                        $valPattern = str_contains($valStr, '%') ? $valStr : "%{$valStr}%";
                        $notLikeClause = $context->isPostgres() ? "(dv_$dimAlias.value IS NULL OR dv_$dimAlias.value NOT ILIKE :$valParam)" : "(dv_$dimAlias.value IS NULL OR LOWER(dv_$dimAlias.value) NOT LIKE LOWER(:$valParam))";
                        $qb->andWhere($notLikeClause)
                            ->setParameter($valParam, $valPattern);
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
                                ->setParameter($paramName, $condition['value'], \Doctrine\DBAL\ArrayParameterType::STRING);
                        } elseif ($condition['operator'] === 'not_in') {
                            $qb->andWhere("$sqlKeyComparable NOT IN (:$paramName)")
                                ->setParameter($paramName, $condition['value'], \Doctrine\DBAL\ArrayParameterType::STRING);
                        } elseif ($condition['operator'] === 'like') {
                            $valStr = (string)$condition['value'];
                            $valPattern = str_contains($valStr, '%') ? $valStr : "%{$valStr}%";
                            $likeClause = $context->isPostgres() ? "$sqlKeyComparable ILIKE :$paramName" : "LOWER($sqlKeyComparable) LIKE LOWER(:$paramName)";
                            $qb->andWhere($likeClause)
                                ->setParameter($paramName, $valPattern);
                        } elseif ($condition['operator'] === 'not_like') {
                            $valStr = (string)$condition['value'];
                            $valPattern = str_contains($valStr, '%') ? $valStr : "%{$valStr}%";
                            $notLikeClause = $context->isPostgres() ? "($sqlKeyComparable IS NULL OR $sqlKeyComparable NOT ILIKE :$paramName)" : "($sqlKeyComparable IS NULL OR LOWER($sqlKeyComparable) NOT LIKE LOWER(:$paramName))";
                            $qb->andWhere($notLikeClause)
                                ->setParameter($paramName, $valPattern);
                        } else {
                            $qb->andWhere("$sqlKeyComparable = :$paramName")
                                ->setParameter($paramName, (string)$condition['value']);
                        }
                    } else {
                        $condition = $resolveFilterCondition($value);
                        $isLike = in_array($condition['operator'] ?? null, ['like', 'not_like'], true);
                        $isNonNumericString = is_string($condition['value']) && !is_numeric($condition['value']) && !in_array($condition['operator'], ['is_null', 'is_not_null'], true);

                        if ($isLike || $isNonNumericString) {
                            $joinRelation($realKey);
                            $sqlKey = $mapFieldToSql($key);
                            $sqlKeyComparable = $context->isPostgres() ? "CAST($sqlKey AS TEXT)" : "CAST($sqlKey AS CHAR)";
                            $paramName = 'f_'.preg_replace('/[^a-z0-9]/i', '_', $key);

                            if ($condition['operator'] === 'is_null') {
                                $qb->andWhere("$sqlKey IS NULL");
                            } elseif ($condition['operator'] === 'is_not_null') {
                                $qb->andWhere("$sqlKey IS NOT NULL");
                            } elseif ($condition['operator'] === 'neq') {
                                $qb->andWhere("$sqlKeyComparable <> :$paramName")
                                    ->setParameter($paramName, (string)$condition['value']);
                            } elseif ($condition['operator'] === 'in') {
                                $qb->andWhere("$sqlKeyComparable IN (:$paramName)")
                                    ->setParameter($paramName, (array)$condition['value'], \Doctrine\DBAL\ArrayParameterType::STRING);
                            } elseif ($condition['operator'] === 'not_in') {
                                $qb->andWhere("$sqlKeyComparable NOT IN (:$paramName)")
                                    ->setParameter($paramName, (array)$condition['value'], \Doctrine\DBAL\ArrayParameterType::STRING);
                            } elseif ($condition['operator'] === 'like') {
                                $valStr = (string)$condition['value'];
                                $valPattern = str_contains($valStr, '%') ? $valStr : "%{$valStr}%";
                                $likeClause = $context->isPostgres() ? "$sqlKeyComparable ILIKE :$paramName" : "LOWER($sqlKeyComparable) LIKE LOWER(:$paramName)";
                                $qb->andWhere($likeClause)
                                    ->setParameter($paramName, $valPattern);
                            } elseif ($condition['operator'] === 'not_like') {
                                $valStr = (string)$condition['value'];
                                $valPattern = str_contains($valStr, '%') ? $valStr : "%{$valStr}%";
                                $notLikeClause = $context->isPostgres() ? "($sqlKeyComparable IS NULL OR $sqlKeyComparable NOT ILIKE :$paramName)" : "($sqlKeyComparable IS NULL OR LOWER($sqlKeyComparable) NOT LIKE LOWER(:$paramName))";
                                $qb->andWhere($notLikeClause)
                                    ->setParameter($paramName, $valPattern);
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
                        ->setParameter($paramName, $condition['value'], \Doctrine\DBAL\ArrayParameterType::STRING);
                } elseif ($condition['operator'] === 'like') {
                    $valStr = (string)$condition['value'];
                    $valPattern = str_contains($valStr, '%') ? $valStr : "%{$valStr}%";
                    $likeClause = $context->isPostgres() ? "$sqlKey ILIKE :$paramName" : "LOWER($sqlKey) LIKE LOWER(:$paramName)";
                    $qb->andWhere($likeClause)
                        ->setParameter($paramName, $valPattern);
                } elseif ($condition['operator'] === 'not_like') {
                    $valStr = (string)$condition['value'];
                    $valPattern = str_contains($valStr, '%') ? $valStr : "%{$valStr}%";
                    $notLikeClause = $context->isPostgres() ? "($sqlKey IS NULL OR $sqlKey NOT ILIKE :$paramName)" : "($sqlKey IS NULL OR LOWER($sqlKey) NOT LIKE LOWER(:$paramName))";
                    $qb->andWhere($notLikeClause)
                        ->setParameter($paramName, $valPattern);
                } else {
                    $qb->andWhere("$sqlKey = :$paramName")
                        ->setParameter($paramName, $condition['value']);
                }
            }
        }
    }

