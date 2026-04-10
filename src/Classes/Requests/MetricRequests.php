<?php

declare(strict_types=1);

namespace Classes\Requests;

use Carbon\Carbon;
use Classes\MetricsProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use Exception;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class MetricRequests implements RequestInterface
{
    

    /**
     * @param \Enums\Channel|string $channel
     * @param string|null $startDate
     * @param string|null $endDate
     * @param \Psr\Log\LoggerInterface|null $logger
     * @param int|null $jobId
     * @param object|null $filters
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public static function getList(
        \Enums\Channel|string $channel,
        ?string $startDate = null,
        ?string $endDate = null,
        ?\Psr\Log\LoggerInterface $logger = null,
        ?int $jobId = null,
        ?object $filters = null
    ): \Symfony\Component\HttpFoundation\Response {
        $chanEnum = ($channel instanceof \Enums\Channel) ? $channel : \Enums\Channel::tryFromName((string)$channel);
        $chanKey = $chanEnum?->name ?? (string)$channel;

        // Intelligent date resolution for Shopify/NetSuite
        $start = $startDate;
        $end = $endDate;
        if (in_array($chanKey, ['shopify', 'netsuite', 'amazon'])) {
            $start = $filters->createdAtMin ?? $startDate;
            $end = $filters->createdAtMax ?? $endDate;
        }

        return (new \Core\Services\SyncService())->execute($chanKey, $start, $end, [
            'jobId' => $jobId,
            'resume' => $filters->resume ?? true,
            'filters' => $filters,
        ]);
    }

    // --- Universal Modular Persistence ---

    /**
     * Persists a collection of metrics into the database.
     * Truly modular: Accepts any metrics collection and handles persistence generically.
     *
     * @param ArrayCollection $collection
     * @param LoggerInterface|null $logger
     * @return array
     */
    public static function persist(ArrayCollection $collection, ?LoggerInterface $logger = null): array
    {
        if (! $logger) {
            $logger = Helpers::setLogger('metrics-processor.log');
        }

        if ($collection->isEmpty()) {
            return ['metrics' => 0, 'rows' => 0, 'duplicates' => 0];
        }

        $manager = Helpers::getManager();

        try {
            $manager->getConnection()->beginTransaction();

            $channel = $collection->first()?->channel ?? null;

            // 1. Process Configurations (Ensures metric_configs exist)
            $metricConfigMap = MetricsProcessor::processMetricConfigs(
                metrics: $collection,
                manager: $manager,
                processQueries: true,
                processAccounts: true,
                processChanneledAccounts: true,
                processDimensions: true,
                logger: $logger,
                channel: (string)$channel
            );

            // 2. Process Global Metrics
            $metricMap = MetricsProcessor::processMetrics(
                metrics: $collection,
                manager: $manager,
                metricConfigMap: $metricConfigMap,
                logger: $logger,
                channel: (string)$channel
            );

            // 3. Process Channeled Metrics
            $channeledMetricMap = MetricsProcessor::processChanneledMetrics(
                metrics: $collection,
                manager: $manager,
                metricMap: $metricMap,
                logger: $logger
            );

            $manager->getConnection()->commit();

            return [
                'metrics' => $collection->count(),
                'rows' => $collection->count(), // In modular drivers, metrics are derived from rows
                'duplicates' => 0,
            ];

        } catch (Exception $e) {
            if ($manager->getConnection()->isTransactionActive()) {
                $manager->getConnection()->rollback();
            }
            $logger->error("Error in MetricRequests::persist: " . $e->getMessage());

            throw $e;
        }
    }

    /**
     * Legacy entry point that returns a Response object.
     *
     * @param ArrayCollection $collection
     * @param LoggerInterface|null $logger
     * @return Response
     */
    public static function process(ArrayCollection $collection, ?LoggerInterface $logger = null): Response
    {
        $result = self::persist($collection, $logger);

        return new Response(json_encode([
            'status' => 'success',
            'processed' => $result['metrics'],
        ]));
    }


    public static function getRetentionRange(array $config, string $channel, string $default): Carbon
    {
        $days = $config['retention_days'] ?? 30;

        return Carbon::now()->subDays((int)$days);
    }

    protected static function determineDateRange(string $channel, ?string $start, ?string $end): array
    {
        return [
            'start' => $start ?: Carbon::now()->subDays(30)->format('Y-m-d'),
            'end' => $end ?: Carbon::now()->format('Y-m-d'),
        ];
    }
}
