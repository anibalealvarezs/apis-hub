<?php

namespace Services\Analytics\VirtualMetricEngine\Nodes;

use Services\Analytics\VirtualMetricEngine\AstNodeInterface;
use Services\Analytics\VirtualMetricEngine\EvaluationContext;

class DerivedMetricNode implements AstNodeInterface
{
    public function __construct(
        protected int $derivedMetricId,
        protected ?\Doctrine\ORM\EntityManager $em = null
    ) {
    }

    public function getDerivedMetricId(): int
    {
        return $this->derivedMetricId;
    }

    public function evaluate(EvaluationContext $context): float|int|array
    {
        return $context->resolveDerivedMetric($this->derivedMetricId);
    }

    public function getMetricNodes(): array
    {
        return [];
    }
}
