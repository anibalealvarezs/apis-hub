<?php

    declare(strict_types=1);

    namespace Services\Aggregation\Stages;

    use Doctrine\DBAL\Query\QueryBuilder;
    use Services\Aggregation\LegacyAggregateExecutionContext;

    final class LegacyAggregateRelationContextStage
    {
        /**
         * @param array<string, array<string, mixed>> $relationMap
         * @param array<string, bool> $activeAggregateJoins
         * @return array<string, mixed>
         */
        public function apply(
            LegacyAggregateExecutionContext $context,
            QueryBuilder                    $qb,
            array                           $relationMap,
            bool                            $isChanneledMetric,
            array                           &$activeAggregateJoins,
        ): array
        {
            $standardRelations = array_keys($relationMap);
            $dateFields = [
                'daily',
                'weekly',
                'monthly',
                'quarterly',
                'yearly',
                'year',
                'month',
                'day',
                'week',
                'quarter',
                'dayofweek',
                'dayname',
                'monthname',
                'metricDate',
                'platformCreatedAt',
                'createdAt',
                'date',
            ];
            $rootAlias = ($isChanneledMetric || $context->isMetric()) ? 'mc' : 'm';

            $safeLeftJoin = static function (string $from, string $table, string $alias, string $condition) use ($qb): void {
                if (method_exists($qb, 'getQueryPart')) {
                    $currentJoins = $qb->getQueryPart('join');
                    foreach ($currentJoins as $joins) {
                        foreach ($joins as $join) {
                            if (($join['joinAlias'] ?? null) === $alias) {
                                return;
                            }
                        }
                    }
                }

                $qb->leftJoin($from, $table, $alias, $condition);
            };

            $joinRelation = function (string $field, bool $enforceExistence = false) use (&$activeAggregateJoins, $safeLeftJoin, $qb, $rootAlias, $relationMap, &$joinRelation): void {
                if (!isset($relationMap[$field])) {
                    return;
                }

                $map = $relationMap[$field];
                if (isset($activeAggregateJoins[$map['alias']])) {
                    return;
                }

                $sourceAlias = $rootAlias;
                if (isset($map['from'])) {
                    $joinRelation($map['from'], $enforceExistence);
                    $sourceAlias = $relationMap[$map['from']]['alias'];
                }

                if ($enforceExistence) {
                    $qb->innerJoin($sourceAlias, $map['table'], $map['alias'], "$sourceAlias.{$map['fk']} = {$map['alias']}.id");
                } else {
                    $safeLeftJoin($sourceAlias, $map['table'], $map['alias'], "$sourceAlias.{$map['fk']} = {$map['alias']}.id");
                }

                $activeAggregateJoins[$map['alias']] = true;
            };

            return [
                'standardRelations' => $standardRelations,
                'dateFields'        => $dateFields,
                'rootAlias'         => $rootAlias,
                'safeLeftJoin'      => $safeLeftJoin,
                'joinRelation'      => $joinRelation,
            ];
        }
    }

