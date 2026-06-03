<?php

namespace Services\Analytics\VirtualMetricEngine\Nodes;

use Services\Analytics\VirtualMetricEngine\AstNodeInterface;
use Services\Analytics\VirtualMetricEngine\EvaluationContext;

class MetricNode implements AstNodeInterface
{
    public function __construct(protected string $metricAlias)
    {
    }

    public function evaluate(EvaluationContext $context): float|int|array
    {
        return $context->getMetricTimeSeries($this->metricAlias);
    }
}
