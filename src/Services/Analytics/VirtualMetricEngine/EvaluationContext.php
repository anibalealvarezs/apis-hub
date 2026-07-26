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

    /**
     * @var array<int, mixed>
     * Pre-resolved derived metric results keyed by DM ID.
     * Sent by the facade in the computeKpi payload.
     */
    protected array $derivedMetrics = [];

    /**
     * @var array<string, mixed>
     * The original filters from the computeKpi request (startDate, endDate, groupBy, etc.).
     */
    protected array $filters = [];

    /**
     * @param array<string, array<string, float>> $metricData
     * @param array<int, mixed> $derivedMetrics
     * @param array<string, mixed> $filters
     */
    public function __construct(array $metricData = [], array $derivedMetrics = [], array $filters = [])
    {
        $this->metricData = $metricData;
        $this->derivedMetrics = $derivedMetrics;
        $this->filters = $filters;
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

    /**
     * Get all pre-resolved derived metric results.
     *
     * @return array<int, mixed>
     */
    public function getDerivedMetrics(): array
    {
        return $this->derivedMetrics;
    }

    /**
     * Get the original request filters.
     *
     * @return array<string, mixed>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }
}
