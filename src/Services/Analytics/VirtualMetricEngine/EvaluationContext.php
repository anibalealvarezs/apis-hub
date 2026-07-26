<?php

namespace Services\Analytics\VirtualMetricEngine;

/**
 * Class EvaluationContext
 * Holds the pre-fetched time-series data for the base metrics required by an AST formula.
 */
class EvaluationContext
{
    /**
     * @var array<string, array<string, float>> 
     * Keyed by metric alias (e.g., 'meta.spend'), value is a Date -> Value array.
     */
    protected array $metricData;

    protected ?callable $derivedMetricResolver = null;

    public function __construct(array $metricData = [])
    {
        $this->metricData = $metricData;
    }

    /**
     * Get the time series array or scalar value for a specific metric.
     *
     * @param string $metricAlias
     * @return array|float|int
     */
    public function getMetricTimeSeries(string $metricAlias): array|float|int
    {
        return $this->metricData[$metricAlias] ?? 0;
    }

    public function setDerivedMetricResolver(callable $resolver): void
    {
        $this->derivedMetricResolver = $resolver;
    }

    public function resolveDerivedMetric(int $id): array
    {
        if ($this->derivedMetricResolver === null) {
            throw new \RuntimeException("No derived metric resolver set in EvaluationContext.");
        }

        return ($this->derivedMetricResolver)($id);
    }
}
