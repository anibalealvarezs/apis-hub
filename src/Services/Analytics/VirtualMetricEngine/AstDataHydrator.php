<?php

namespace Services\Analytics\VirtualMetricEngine;

use Doctrine\ORM\EntityManager;
use Repositories\MetricRepository;
use Services\Aggregation\AggregationExecutor;
use Exceptions\ConfigurationException;
use Psr\Log\LoggerInterface;

class AstDataHydrator
{
    public function __construct(protected EntityManager $em, protected ?LoggerInterface $logger = null)
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
        $startDate = $filters['startDate'] ?? null;
        $endDate = $filters['endDate'] ?? null;
        // Unset period and dates so they aren't parsed as direct column filters
        unset($filters['startDate'], $filters['endDate'], $filters['period']);
        
        $filterObj = (object) $filters;

        foreach ($channelMetrics as $channel => $metricsList) {
            $channelFilter = clone $filterObj;
            if ($channel !== 'global') {
                $channelEntity = $this->em->getRepository(\Entities\Analytics\Channel::class)->findOneBy(['name' => $channel]);
                $channelFilter->channel = $channelEntity ? $channelEntity->getId() : 0;
            }
            
            $aggregations = [];
            foreach ($metricsList as $metric) {
                // Use canonical metric mappings instead of hardcoded raw SQL, mirroring the frontend
                $aggregations[$metric] = $metric;
            }

            $dbStart = microtime(true);
            if ($this->logger) {
                $this->logger->info("Starting DB aggregation for channel: {$channel}", ['aggregations' => $aggregations]);
            }
            
            $result = $executor->executeAggregate(
                repository: $metricRepository,
                aggregations: $aggregations,
                groupBy: [], // For scalar evaluation
                filters: $channelFilter,
                startDate: $startDate,
                endDate: $endDate
            );
            
            $dbTime = round((microtime(true) - $dbStart) * 1000, 2);
            if ($this->logger) {
                $this->logger->info("Finished DB aggregation for channel: {$channel} in {$dbTime}ms");
            }

            // Fetch rows
            $rows = $result->getRows();
            if (!empty($rows) && isset($rows[0])) {
                $row = $rows[0];
                foreach ($metricsList as $metric) {
                    $key = $channel !== 'global' ? "{$channel}.{$metric}" : $metric;
                    // Support legacy aliasing if the planner alters the key slightly
                    $metricData[$key] = $row[$metric] ?? 0;
                }
            } else {
                foreach ($metricsList as $metric) {
                    $key = $channel !== 'global' ? "{$channel}.{$metric}" : $metric;
                    $metricData[$key] = 0;
                }
            }
        }

        return $metricData;
    }
}
