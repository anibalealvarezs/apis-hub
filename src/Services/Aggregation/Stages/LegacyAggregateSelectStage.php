<?php

declare(strict_types=1);

namespace Services\Aggregation\Stages;

use InvalidArgumentException;
use Services\Aggregation\LegacyAggregateExecutionContext;

final class LegacyAggregateSelectStage
{
    /**
     * @param callable(string, bool=): string $mapFieldToSql
     * @param callable(array<string, string>): array<string, array<string, mixed>> $resolveWeightedAggregationStrategies
     */
    public function apply(
        LegacyAggregateExecutionContext $context,
        bool $isChanneledMetric,
        callable $mapFieldToSql,
        callable $resolveWeightedAggregationStrategies,
    ): bool {
        $qb = $context->getQueryBuilder();
        $aggregations = $context->getAggregations();
        $weightedMetricExpressions = (array)$context->getPlan()->getStageValue('reducers', 'weighted_metric_expressions', []);
        $weightedStrategies = $weightedMetricExpressions === []
            ? []
            : $resolveWeightedAggregationStrategies($aggregations);
        $needsImpressionsJoin = $weightedStrategies !== [];

        if ($needsImpressionsJoin && $isChanneledMetric) {
            // Kept intentionally as no-op to preserve legacy behavior.
        }

        foreach ($aggregations as $alias => $expr) {
            $parsedExpr = $mapFieldToSql($expr, true);

            if ($isChanneledMetric && preg_match('/\bm\.value\b/i', $parsedExpr) && !str_contains($parsedExpr, 'CASE WHEN')) {
                throw new InvalidArgumentException(
                    "Direct aggregation of 'value' field is restricted for ChanneledMetrics to prevent data corruption. ".
                    "Please use intelligent formulas (e.g., 'spend', 'clicks', 'ctr', 'cpc', 'cpm', 'frequency', 'position') ".
                    "or filter specifically by 'name' before aggregating."
                );
            }

            $qb->addSelect("$parsedExpr AS $alias");
        }

        return $needsImpressionsJoin;
    }
}

