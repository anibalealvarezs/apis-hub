<?php

namespace Services\Analytics\VirtualMetricEngine\Nodes;

use Services\Analytics\VirtualMetricEngine\AstNodeInterface;
use Services\Analytics\VirtualMetricEngine\EvaluationContext;

class MetricNode implements AstNodeInterface
{
    public function __construct(
        protected string $metricAlias,
        protected array $filters = []
    ) {
    }

    public function getMetricAlias(): string
    {
        return $this->metricAlias;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getHashKey(): string
    {
        // Generate a deterministic hash for this specific metric + filter combination
        // Sort keys to ensure consistent hashing even if filter order changes
        $sortedFilters = $this->filters;
        ksort($sortedFilters);
        $filterString = !empty($sortedFilters) ? json_encode($sortedFilters) : '';
        return md5($this->metricAlias . $filterString);
    }

    public function evaluate(EvaluationContext $context): float|int|array
    {
        return $context->getMetricTimeSeries($this->getHashKey());
    }

    public function getMetricNodes(): array
    {
        return [$this];
    }
}
