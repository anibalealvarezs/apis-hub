<?php

namespace Services\Analytics\VirtualMetricEngine;

use Doctrine\ORM\EntityManager;
use Repositories\MetricRepository;
use Services\Aggregation\AggregationExecutor;
use Exceptions\ConfigurationException;

class AstDataHydrator
{
    public function __construct(protected EntityManager $em)
    {
    }

    /**
     * @param AstNodeInterface $node
     * @param array $filters
     * @return array
     * @throws ConfigurationException
     * @throws \Doctrine\DBAL\Exception
     */
    public function hydrate(AstNodeInterface $node, array $filters): array
    {
        $metrics = $node->getMetrics();
        if (empty($metrics)) {
            return [];
        }

        // Group by channel
        $channelMetrics = [];
        foreach ($metrics as $metricAlias) {
            $parts = explode('.', $metricAlias, 2);
            if (count($parts) === 2) {
                $channel = $parts[0];
                $metric = $parts[1];
                $channelMetrics[$channel][] = $metric;
            } else {
                // If no channel prefix, we might default to a specific channel or throw error.
                // For now, let's assume it's valid if there's no prefix, maybe 'global'.
                $channelMetrics['global'][] = $metricAlias;
            }
        }

        /** @var MetricRepository $metricRepository */
        $metricRepository = $this->em->getRepository(\Entities\Analytics\Metric::class);
        $executor = new AggregationExecutor();
        
        $metricData = [];
        $filterObj = (object) $filters;

        foreach ($channelMetrics as $channel => $metricsList) {
            if ($channel !== 'global') {
                $filterObj->channel = $channel;
            }
            
            // Build the aggregations array for the AggregationPlanner
            // e.g. ['spend' => 'SUM(value) AS spend']
            $aggregations = [];
            foreach ($metricsList as $metric) {
                // We assume SUM by default for AST hydration, or we could support more complex mappings.
                $aggregations[$metric] = "SUM(value) AS {$metric}";
            }

            // We default to period=lifetime if not provided, but it should be in filters
            $result = $executor->executeAggregate(
                repository: $metricRepository,
                aggregations: $aggregations,
                groupBy: [], // For scalar evaluation
                filters: $filterObj
            );

            // Fetch rows
            $rows = $result->getRows();
            if (!empty($rows) && isset($rows[0])) {
                $row = $rows[0];
                foreach ($metricsList as $metric) {
                    $key = $channel !== 'global' ? "{$channel}.{$metric}" : $metric;
                    // If the metric was aggregated, it will be in the row
                    $metricData[$key] = $row[$metric] ?? 0;
                }
            } else {
                // Fallback if no data found
                foreach ($metricsList as $metric) {
                    $key = $channel !== 'global' ? "{$channel}.{$metric}" : $metric;
                    $metricData[$key] = 0;
                }
            }
        }

        return $metricData;
    }
}
