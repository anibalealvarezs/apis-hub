<?php

declare(strict_types=1);

namespace Services\Aggregation;

use Repositories\BaseRepository;

final class AggregationExecutor
{
    public function execute(BaseRepository $repository, AggregationPlan $plan): AggregationExecutionResult
    {
        if ($plan->canUseOptimized()) {
            $optimizedResult = $repository->executeOptimizedAggregationPlan($plan);
            if ($optimizedResult !== null) {
                return $optimizedResult;
            }
        }

        $fallbackReason = $plan->getFallbackReason() ?? 'no_optimized_strategy_matched';

        return $repository->executeLegacyAggregationPlan($plan, $fallbackReason);
    }
}

