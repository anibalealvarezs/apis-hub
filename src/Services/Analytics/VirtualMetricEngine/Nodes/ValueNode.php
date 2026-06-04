<?php

namespace Services\Analytics\VirtualMetricEngine\Nodes;

use Services\Analytics\VirtualMetricEngine\AstNodeInterface;
use Services\Analytics\VirtualMetricEngine\EvaluationContext;

class ValueNode implements AstNodeInterface
{
    public function __construct(protected float|int $value)
    {
    }

    public function evaluate(EvaluationContext $context): float|int|array
    {
        return $this->value;
    }

    public function getMetricNodes(): array
    {
        return [];
    }
}
