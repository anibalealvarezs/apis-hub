<?php

namespace Services\Analytics\VirtualMetricEngine\Nodes;

use Services\Analytics\VirtualMetricEngine\AstNodeInterface;
use Services\Analytics\VirtualMetricEngine\EvaluationContext;
use InvalidArgumentException;

class DerivedMetricNode implements AstNodeInterface
{
    public function __construct(
        protected int $derivedMetricId
    ) {
    }

    public function getDerivedMetricId(): int
    {
        return $this->derivedMetricId;
    }

    public function evaluate(EvaluationContext $context): float|int|array
    {
        $dmResults = $context->getDerivedMetrics();

        if (! isset($dmResults[$this->derivedMetricId])) {
            throw new InvalidArgumentException(
                "Derived metric #{$this->derivedMetricId} result not found in context."
            );
        }

        return $dmResults[$this->derivedMetricId];
    }

    public function getMetricNodes(): array
    {
        return [];
    }
}
