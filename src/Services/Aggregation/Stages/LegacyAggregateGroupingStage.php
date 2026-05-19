<?php

    declare(strict_types=1);

    namespace Services\Aggregation\Stages;

    use Services\Aggregation\LegacyAggregateExecutionContext;

    final class LegacyAggregateGroupingStage
    {
        /**
         * @param array<string, array<string, mixed>> $relationMap
         * @param callable(string, bool=): string $mapFieldToSql
         * @param callable(string): bool $hasEntityField
         */
        public function apply(
            LegacyAggregateExecutionContext $context,
            array                           $relationMap,
            bool                            $isChanneledMetric,
            callable                        $mapFieldToSql,
            callable                        $hasEntityField,
        ): void
        {
            $qb = $context->getQueryBuilder();
            $groupBy = $context->getGroupBy();
            $standardRelations = (array)$context->getRelationContextValue('standardRelations', []);
            $dateFields = (array)$context->getRelationContextValue('dateFields', []);
            $rootAlias = (string)$context->getRelationContextValue('rootAlias', 'mc');
            $safeLeftJoin = $context->getRelationContextValue('safeLeftJoin');
            $joinRelation = $context->getRelationContextValue('joinRelation');

            if (!is_callable($safeLeftJoin) || !is_callable($joinRelation)) {
                return;
            }

            foreach ($groupBy as $field) {
                $quoteChar = $context->isPostgres() ? '"' : '`';
                $quotedField = preg_match('/^[a-zA-Z0-9_]+$/', $field) ? $field : $quoteChar.$field.$quoteChar;
                $isDimension = str_starts_with($field, 'dimensions.');
                $dimKey = $isDimension ? substr($field, 11) : $field;

                if (($context->isMetric() || $isChanneledMetric) && ($isDimension || ($field !== 'account_type' && !in_array($field, $standardRelations, true) && !str_ends_with($field, '_id') && !in_array($field, $dateFields, true) && !$hasEntityField($field)))) {
                    $dimRootAlias = $isChanneledMetric ? 'e' : 'mc';
                    $dimAlias = 'dim_'.preg_replace('/[^a-z0-9]/i', '_', $dimKey);
                    $qb->setParameter("key_$dimAlias", $dimKey);
                    $safeLeftJoin($dimRootAlias, 'dimension_set_items', "dsi_$dimAlias", "$dimRootAlias.dimension_set_id = dsi_$dimAlias.dimension_set_id AND dsi_$dimAlias.dimension_value_id IN (
                    SELECT sub_dv.id FROM dimension_values sub_dv 
                    JOIN dimension_keys sub_dk ON sub_dv.dimension_key_id = sub_dk.id 
                    WHERE sub_dk.name = :key_$dimAlias
                )");
                    $safeLeftJoin("dsi_$dimAlias", 'dimension_values', "dv_$dimAlias", "dsi_$dimAlias.dimension_value_id = dv_$dimAlias.id");
                    $qb->addSelect("dv_$dimAlias.value AS $quotedField")
                        ->addGroupBy("dv_$dimAlias.value");
                    continue;
                }

                if (in_array($field, $standardRelations, true) || str_ends_with($field, '_id')) {
                    $relationKey = $field;
                    $isExplicitId = str_ends_with($field, '_id');
                    if ($isExplicitId && !isset($relationMap[$field])) {
                        $relationKey = substr($field, 0, -3);
                        if ($relationKey === 'channeled_account') {
                            $relationKey = 'channeledAccount';
                        }
                        if ($relationKey === 'channeled_campaign') {
                            $relationKey = 'channeledCampaign';
                        }
                    }

                    if (isset($relationMap[$relationKey])) {
                        $isPrimaryRelation = in_array($relationKey, ['channeledAccount', 'campaign', 'channeledCampaign'], true);
                        $joinRelation($relationKey, $isPrimaryRelation);
                        $map = $relationMap[$relationKey];

                        if ($isExplicitId || $field === $map['fk']) {
                            $qb->addSelect("$rootAlias.{$map['fk']} AS $quotedField")
                                ->addGroupBy("$rootAlias.{$map['fk']}");
                        } else {
                            $parsedIdField = $mapFieldToSql($field);
                            $qb->addSelect("$parsedIdField AS $quotedField")
                                ->addGroupBy($parsedIdField);

                            if ($field === $relationKey && !isset($map['isAttribute'])) {
                                $shadowId = $quoteChar.$field.'_id'.$quoteChar;
                                $qb->addSelect("$rootAlias.{$map['fk']} AS $shadowId")
                                    ->addGroupBy("$rootAlias.{$map['fk']}");
                            }
                        }
                    } else {
                        $qb->addSelect("$rootAlias.$field AS $quotedField")
                            ->addGroupBy("$rootAlias.$field");
                    }
                    continue;
                }

                if (($context->isMetric() || $isChanneledMetric) && in_array($field, ['account', 'campaign'], true)) {
                    $isAccount = $field === 'account';
                    $genericKey = $isAccount ? 'account' : 'campaign';
                    $channeledKey = $isAccount ? 'channeledAccount' : 'channeledCampaign';
                    $genericMap = $relationMap[$genericKey];
                    $channeledMap = $relationMap[$channeledKey];
                    $joinRelation($genericKey);
                    $joinRelation($channeledKey);

                    if ($isAccount) {
                        $joinRelation('channeledCampaign');
                        $campaignAlias = $relationMap['channeledCampaign']['alias'];
                        $safeLeftJoin($campaignAlias, 'channeled_accounts', 'rca_fallback', "{$campaignAlias}.channeled_account_id = rca_fallback.id");

                        $castType = $context->isPostgres() ? 'VARCHAR' : 'CHAR';
                        $quotedFieldId = $quoteChar.$field.'_id'.$quoteChar;
                        $qb->addSelect("COALESCE(CAST({$channeledMap['alias']}.{$channeledMap['field']} AS $castType), CAST(rca_fallback.name AS $castType), CAST({$genericMap['alias']}.{$genericMap['field']} AS $castType), CAST({$channeledMap['alias']}.platform_id AS $castType), CAST(mc.{$channeledMap['fk']} AS $castType), 'Unknown') AS $quotedField")
                            ->addSelect("mc.{$channeledMap['fk']} AS $quotedFieldId")
                            ->addGroupBy("{$channeledMap['alias']}.{$channeledMap['field']}")
                            ->addGroupBy('rca_fallback.name')
                            ->addGroupBy("{$genericMap['alias']}.{$genericMap['field']}")
                            ->addGroupBy("{$channeledMap['alias']}.platform_id")
                            ->addGroupBy("mc.{$channeledMap['fk']}");
                    } else {
                        if (isset($genericMap['isJSON']) && $genericMap['isJSON']) {
                            $sqlField = $mapFieldToSql($field);
                            $qb->addSelect("COALESCE($sqlField, 'N/A') AS $quotedField")
                                ->addGroupBy($sqlField);
                        } else {
                            $quotedFieldId = $quoteChar.$field.'_id'.$quoteChar;
                            $castType = $context->isPostgres() ? 'VARCHAR' : 'CHAR';
                            $qb->addSelect("COALESCE(CAST({$channeledMap['alias']}.{$channeledMap['field']} AS $castType), CAST({$genericMap['alias']}.{$genericMap['field']} AS $castType), CAST({$channeledMap['alias']}.platform_id AS $castType), CAST(mc.{$channeledMap['fk']} AS $castType), 'Unknown') AS $quotedField")
                                ->addSelect("mc.{$channeledMap['fk']} AS $quotedFieldId")
                                ->addGroupBy("{$channeledMap['alias']}.{$channeledMap['field']}")
                                ->addGroupBy("{$genericMap['alias']}.{$genericMap['field']}")
                                ->addGroupBy("{$channeledMap['alias']}.platform_id")
                                ->addGroupBy("mc.{$channeledMap['fk']}");
                        }
                    }
                    continue;
                }

                if (($context->isMetric() || $isChanneledMetric) && isset($relationMap[$field])) {
                    $joinRelation($field);
                    $map = $relationMap[$field];
                    $castType = $context->isPostgres() ? 'VARCHAR' : 'CHAR';
                    if (isset($map['isJSON']) && $map['isJSON']) {
                        $sqlField = $mapFieldToSql($field);
                        $qb->addSelect("COALESCE($sqlField, 'N/A') AS $quotedField")
                            ->addGroupBy($sqlField);
                    } elseif (!empty($map['isAttribute'])) {
                        $sqlField = $mapFieldToSql($field);
                        $qb->addSelect("COALESCE(CAST($sqlField AS $castType), 'N/A') AS $quotedField")
                            ->addGroupBy($sqlField);
                    } else {
                        $quotedFieldId = $quoteChar.$field.'_id'.$quoteChar;
                        $qb->addSelect("COALESCE(CAST({$map['alias']}.{$map['field']} AS $castType), CAST(mc.{$map['fk']} AS $castType), 'Unknown') AS $quotedField")
                            ->addSelect("mc.{$map['fk']} AS $quotedFieldId")
                            ->addGroupBy("{$map['alias']}.{$map['field']}")
                            ->addGroupBy("mc.{$map['fk']}");
                    }
                    continue;
                }

                $sqlField = $mapFieldToSql($field);
                $qb->addSelect("$sqlField AS $quotedField")
                    ->addGroupBy($sqlField);
            }
        }
    }


