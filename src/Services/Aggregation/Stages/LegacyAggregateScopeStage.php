<?php

declare(strict_types=1);

namespace Services\Aggregation\Stages;

use Services\Aggregation\LegacyAggregateExecutionContext;

final class LegacyAggregateScopeStage
{
    /**
     * @param array<string, bool> $activeAggregateJoins
     */
    public function apply(
        LegacyAggregateExecutionContext $context,
        bool $isChanneledMetric,
        array &$activeAggregateJoins,
    ): void {
        $qb = $context->getQueryBuilder();

        if ($isChanneledMetric) {
            $qb->join('e', 'metrics', 'm', 'e.metric_id = m.id')
                ->join('m', 'metric_configs', 'mc', 'm.metric_config_id = mc.id');

            $activeAggregateJoins['m'] = true;
            $activeAggregateJoins['mc'] = true;

            return;
        }

        if ($context->isMetric()) {
            $qb->join('e', 'metric_configs', 'mc', 'e.metric_config_id = mc.id');
            $activeAggregateJoins['mc'] = true;
        }
    }
}

