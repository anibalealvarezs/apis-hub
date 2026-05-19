<?php

    declare(strict_types=1);

    namespace Services\Aggregation\Stages;

    use Doctrine\DBAL\Exception;
    use Services\Aggregation\LegacyAggregateExecutionContext;

    final class LegacyAggregateFinalizeStage
    {
        /**
         * @param callable(array<int, array<string,mixed>>&, ?string, ?string): void $extractSnapshotAggregateMeta
         * @param callable(array<int, array<string,mixed>>, string, string, string, string, array<string,string>, array<int,string>): array<int, array<string,mixed>> $fillTemporalGaps
         * @return array<int, array<string,mixed>>
         * @throws Exception
         */
        public function apply(
            LegacyAggregateExecutionContext $context,
            callable                        $extractSnapshotAggregateMeta,
            callable                        $fillTemporalGaps,
        ): array
        {
            $qb = $context->getQueryBuilder();
            $aggregations = $context->getAggregations();
            $plan = $context->getPlan();
            $startDate = $context->getStartDate();
            $endDate = $context->getEndDate();

            $stmt = $qb->executeQuery();
            $results = $stmt->fetchAllAssociative();

            $extractSnapshotAggregateMeta($results, $startDate, $endDate);

            $groupBy = (array)$plan->getStageValue('grouping', 'group_by', $plan->getGroupBy());
            $hasTemporalGrouping = (bool)$plan->getStageValue('grouping', 'has_temporal_grouping', false);

            if ($startDate && $endDate && $hasTemporalGrouping) {
                $temporalField = null;
                $temporalType = null;

                foreach ($groupBy as $field) {
                    if (in_array(strtolower((string)$field), ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'], true)) {
                        $temporalField = (string)$field;
                        $temporalType = strtolower((string)$field);
                        break;
                    }
                }

                if ($temporalField) {
                    $results = $fillTemporalGaps(
                        $results,
                        $temporalField,
                        $temporalType,
                        $startDate,
                        $endDate,
                        $aggregations,
                        $groupBy
                    );
                }
            }

            return $results;
        }
    }

