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
        $metricNodes = $node->getMetricNodes();
        if (empty($metricNodes)) {
            return [];
        }

        // Group by channel and specific filter hash
        $batchedQueries = [];
        foreach ($metricNodes as $mNode) {
            $metricAlias = $mNode->getMetricAlias();
            $parts = explode('.', $metricAlias, 2);
            $channel = count($parts) === 2 ? $parts[0] : 'global';
            $metric = count($parts) === 2 ? $parts[1] : $metricAlias;

            // Merge global non-temporal filters with node-specific filters (node overrides global)
            $nodeFilters = $mNode->getFilters();
            $combinedFilters = array_merge($filters, $nodeFilters);
            
            // Temporal filters are excluded from the hash because they are handled globally
            $hashFilters = $combinedFilters;
            unset($hashFilters['startDate'], $hashFilters['endDate'], $hashFilters['period'], $hashFilters['groupBy']);

            ksort($hashFilters);
            $filterHash = md5(json_encode($hashFilters));
            $batchKey = $channel . '_' . $filterHash;

            if (!isset($batchedQueries[$batchKey])) {
                $batchedQueries[$batchKey] = [
                    'channel' => $channel,
                    'filters' => $hashFilters,
                    'metrics' => [],
                    'nodes' => []
                ];
            }
            $batchedQueries[$batchKey]['metrics'][$metric] = $metric;
            $batchedQueries[$batchKey]['nodes'][] = clone $mNode; // store for mapping back to keys
        }

        $executor = new AggregationExecutor();
        
        $metricData = [];
        $startDate = $filters['startDate'] ?? null;
        $endDate = $filters['endDate'] ?? null;
        $groupBy = $filters['groupBy'] ?? [];
        
        foreach ($batchedQueries as $batch) {
            $channel = $batch['channel'];
            $channelFilter = (object) $batch['filters'];
            
            $repository = null;
            if ($channel !== 'global') {
                $channelEntity = $this->em->getRepository(\Entities\Analytics\Channel::class)->findOneBy(['name' => $channel]);
                $channelFilter->channel = $channelEntity ? $channelEntity->getId() : 0;
                
                $entitiesConfig = \Helpers\Helpers::getEntitiesConfig();
                $channeledClass = $entitiesConfig['metric']['channeled_class'] ?? null;
                if ($channeledClass && class_exists($channeledClass)) {
                    $repository = $this->em->getRepository($channeledClass);
                }
            }
            
            if (!$repository) {
                $repository = $this->em->getRepository(\Entities\Analytics\Metric::class);
            }
            
            $aggregations = $batch['metrics'];

            $dbStart = microtime(true);
            if ($this->logger) {
                $this->logger->info("Starting DB aggregation for channel: {$channel}", ['aggregations' => $aggregations, 'filters' => (array)$channelFilter]);
            }
            
            $result = $executor->executeAggregate(
                repository: $repository,
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

            // Map data back to unique hash keys
            foreach ($batch['nodes'] as $mNode) {
                $metric = count(explode('.', $mNode->getMetricAlias(), 2)) === 2 ? explode('.', $mNode->getMetricAlias(), 2)[1] : $mNode->getMetricAlias();
                $hashKey = $mNode->getHashKey();
                
                if ($isSeries) {
                    $seriesData = [];
                    foreach ($rows as $row) {
                        $date = $row[$temporalField] ?? $row['date'] ?? 'unknown';
                        $seriesData[$date] = $row[$metric] ?? 0;
                    }
                    $metricData[$hashKey] = $seriesData;
                } else {
                    if (!empty($rows) && isset($rows[0])) {
                        $metricData[$hashKey] = $rows[0][$metric] ?? 0;
                    } else {
                        $metricData[$hashKey] = 0;
                    }
                }
            }
        }

        return $metricData;
    }
}
