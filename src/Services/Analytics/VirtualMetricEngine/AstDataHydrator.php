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
        $groupBy = $filters['groupBy'] ?? [];
        // Unset period and dates so they aren't parsed as direct column filters
        unset($filters['startDate'], $filters['endDate'], $filters['period'], $filters['groupBy']);
        
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
                groupBy: $groupBy,
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
            if ($this->logger) {
                $this->logger->info("Fetched rows for channel {$channel}", ['rowCount' => count($rows), 'sample' => $rows[0] ?? null]);
            }
            $isSeries = false;
            $temporalField = 'unknown';
            
            // AggregationPlanner outputs the date under the literal key of the temporal grouping (e.g. 'daily')
            foreach (['daily', 'weekly', 'monthly', 'quarterly', 'yearly'] as $tField) {
                if (in_array($tField, $groupBy)) {
                    $isSeries = true;
                    $temporalField = $tField;
                    break;
                }
            }

            foreach ($metricsList as $metric) {
                $key = $channel !== 'global' ? "{$channel}.{$metric}" : $metric;
                if ($isSeries) {
                    $seriesData = [];
                    foreach ($rows as $row) {
                        $date = $row[$temporalField] ?? $row['date'] ?? 'unknown';
                        $seriesData[$date] = $row[$metric] ?? 0;
                    }
                    $metricData[$key] = $seriesData;
                } else {
                    if (!empty($rows) && isset($rows[0])) {
                        $metricData[$key] = $rows[0][$metric] ?? 0;
                    } else {
                        $metricData[$key] = 0;
                    }
                }
            }
        }

        return $metricData;
    }
}
