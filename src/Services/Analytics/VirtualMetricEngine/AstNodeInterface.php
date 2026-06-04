<?php

namespace Services\Analytics\VirtualMetricEngine;

/**
 * Interface AstNodeInterface
 * Represents a single node in the Abstract Syntax Tree for a custom KPI formula.
 */
interface AstNodeInterface
{
    /**
     * Evaluates the node and returns either a scalar float/int or a TimeSeries array.
     *
     * @param EvaluationContext $context The context containing pre-fetched metric data.
     * @return float|int|array
     */
    public function evaluate(EvaluationContext $context): float|int|array;

    /**
     * Extracts all unique MetricNode instances referenced in this node and its children.
     *
     * @return array<int, \Services\Analytics\VirtualMetricEngine\Nodes\MetricNode>
     */
    public function getMetricNodes(): array;
}
