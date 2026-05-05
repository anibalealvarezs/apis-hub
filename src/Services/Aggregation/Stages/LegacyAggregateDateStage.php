<?php

    declare(strict_types=1);

    namespace Services\Aggregation\Stages;

    use InvalidArgumentException;
    use Services\Aggregation\LegacyAggregateExecutionContext;

    final class LegacyAggregateDateStage
    {
        /**
         * @param callable(string, bool=): string $mapFieldToSql
         * @param callable(string): bool $hasEntityField
         */
        public function apply(
            LegacyAggregateExecutionContext $context,
            bool                            $isChanneledMetric,
            bool                            $aggregateUseSnapshotDelta,
            string                          $aggregateSnapshotFallbackMode,
            callable                        $mapFieldToSql,
            callable                        $hasEntityField,
        ): void
        {
            $qb = $context->getQueryBuilder();
            $filters = $context->getFilters();
            $startDate = $context->getStartDate();
            $endDate = $context->getEndDate();

            $latestSnapshot = false;
            if ($filters && isset($filters->latest_snapshot)) {
                $latestSnapshot = filter_var($filters->latest_snapshot, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($latestSnapshot === null) {
                    $latestSnapshot = (bool)$filters->latest_snapshot;
                }
            }

            if ($latestSnapshot && $aggregateUseSnapshotDelta) {
                throw new InvalidArgumentException('latest_snapshot and snapshot_delta cannot be used together.');
            }

            if (!$startDate && !$endDate && !$latestSnapshot) {
                return;
            }

            if ($isChanneledMetric) {
                $sqlDateField = 'm.metric_date';
            } elseif ($context->isMetric()) {
                $sqlDateField = 'e.metric_date';
            } else {
                $dateField = 'platformCreatedAt';
                if (!$hasEntityField($dateField)) {
                    $dateField = $hasEntityField('createdAt') ? 'createdAt' : 'date';
                }
                $sqlDateField = $mapFieldToSql($dateField);
            }

            if ($latestSnapshot && $isChanneledMetric) {
                $qb->addSelect("MAX($sqlDateField) AS __snapshot_effective_date");
                $nullSafeComparator = $context->isPostgres() ? 'IS NOT DISTINCT FROM' : '<=>';
                $latestSnapshotBaseSql = "
                FROM metrics m_ls
                JOIN metric_configs mc_ls ON m_ls.metric_config_id = mc_ls.id
                WHERE mc_ls.channel = mc.channel
                  AND mc_ls.period = mc.period
                  AND (mc_ls.channeled_account_id $nullSafeComparator mc.channeled_account_id)
                  AND (mc_ls.page_id $nullSafeComparator mc.page_id)
                  AND (mc_ls.post_id $nullSafeComparator mc.post_id)
                  AND (mc_ls.dimension_set_id $nullSafeComparator mc.dimension_set_id)
                  AND (mc_ls.query_id $nullSafeComparator mc.query_id)
                  AND (mc_ls.country_id $nullSafeComparator mc.country_id)
                  AND (mc_ls.device_id $nullSafeComparator mc.device_id)
            ";

                $latestSnapshotSql = "SELECT MAX(m_ls.metric_date) {$latestSnapshotBaseSql}";
                if ($startDate || $endDate) {
                    $latestSnapshotRangeSql = $latestSnapshotSql;
                    if ($startDate) {
                        $latestSnapshotRangeSql .= ' AND m_ls.metric_date >= :startDate';
                        $qb->setParameter('startDate', $startDate);
                    }
                    if ($endDate) {
                        $latestSnapshotRangeSql .= ' AND m_ls.metric_date <= :endDate';
                        $qb->setParameter('endDate', $endDate);
                    }

                    $latestSnapshotSql = $aggregateSnapshotFallbackMode === 'resilient'
                        ? "COALESCE(($latestSnapshotRangeSql), ($latestSnapshotSql))"
                        : $latestSnapshotRangeSql;
                }

                $qb->andWhere("$sqlDateField = ($latestSnapshotSql)");

                return;
            }

            if ($aggregateUseSnapshotDelta && $isChanneledMetric) {
                $qb->addSelect("MAX($sqlDateField) AS __snapshot_effective_date");
                if ($aggregateSnapshotFallbackMode === 'strict') {
                    $qb->andWhere("$sqlDateField <= :snapshotDeltaEndDate");
                }
                $qb->setParameter('snapshotDeltaEndDate', $endDate)
                    ->setParameter('snapshotDeltaStartDate', $startDate);

                return;
            }

            if ($startDate) {
                $qb->andWhere("$sqlDateField >= :startDate")
                    ->setParameter('startDate', $startDate);
            }
            if ($endDate) {
                $qb->andWhere("$sqlDateField <= :endDate")
                    ->setParameter('endDate', $endDate);
            }
        }
    }

