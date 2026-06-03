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

    public function __construct(array $metricData = [])
    {
        $this->metricData = $metricData;
    }

    /**
     * Get the time series array for a specific metric.
     *
     * @param string $metricAlias
     * @return array<string, float>
     */
    public function getMetricTimeSeries(string $metricAlias): array
    {
        return $this->metricData[$metricAlias] ?? [];
    }
}
