<?php

declare(strict_types=1);

namespace Classes\Requests;

use Core\Conversions\UniversalMetricConverter;
use Core\Conversions\UniversalEntityConverter;
use Carbon\Carbon;
use Classes\MetricsProcessor;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Entities\Analytics\Channeled\ChanneledAccount;
use Enums\Channel;
use Enums\Period;
use Exception;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;

class MetricRequests
{
    /**
     * @return array
     */
    public static function supportedChannels(): array
    {
        return [
            'shopify',
            'klaviyo',
            'amazon',
            'bigcommerce',
            'netsuite',
            'facebook_organic',
            'facebook_marketing',
            'pinterest',
            'linkedin',
            'x',
            'tiktok',
            'google_search_console',
            'google_analytics',
        ];
    }

    /**
     * @param LoggerInterface $logger
     * @return array
     * @throws Exception
     */
    public static function validateGoogleConfig(LoggerInterface $logger): array
    {
        return \Helpers\GoogleSearchConsoleHelpers::validateGoogleConfig($logger);
    }

    /**
     * @param LoggerInterface|null $logger
     * @param string|null $channel
     * @return array
     */
    public static function validateFacebookConfig(?LoggerInterface $logger = null, ?string $channel = null): array
    {
        return \Classes\Clients\FacebookClient::getConfig($logger, $channel);
    }

    public static function getListFromKlaviyo(?string $start = null, ?string $end = null, ?object $filters = null, bool $resume = true, ?int $jobId = null): Response
    {
        return (new \Core\Services\SyncService())->execute('klaviyo', $start, $end, ['jobId' => $jobId, 'resume' => $resume]);
    }

    public static function getListFromShopify(?object $filters = null, bool $resume = true, ?int $jobId = null): Response
    {
        return (new \Core\Services\SyncService())->execute('shopify', $filters->createdAtMin ?? null, $filters->createdAtMax ?? null, ['jobId' => $jobId, 'resume' => $resume]);
    }

    public static function getListFromFacebookOrganic(?string $start = null, ?string $end = null, ?object $filters = null, bool $resume = true, ?int $jobId = null): Response
    {
        return (new \Core\Services\SyncService())->execute('facebook_organic', $start, $end, ['jobId' => $jobId, 'resume' => $resume]);
    }

    public static function getListFromFacebookMarketing(?string $start = null, ?string $end = null, ?object $filters = null, bool $resume = true, ?int $jobId = null): Response
    {
        return (new \Core\Services\SyncService())->execute('facebook_marketing', $start, $end, ['jobId' => $jobId, 'resume' => $resume]);
    }

    public static function getListFromNetSuite(?object $filters = null, bool $resume = true, ?int $jobId = null): Response
    {
        return (new \Core\Services\SyncService())->execute('netsuite', $filters->createdAtMin ?? null, $filters->createdAtMax ?? null, ['jobId' => $jobId, 'resume' => $resume]);
    }

    public static function getListFromAmazon(?object $filters = null, bool $resume = true, ?int $jobId = null): Response
    {
        return (new \Core\Services\SyncService())->execute('amazon', $filters->createdAtMin ?? null, $filters->createdAtMax ?? null, ['jobId' => $jobId, 'resume' => $resume]);
    }

    public static function getListFromGoogleSearchConsole(?string $startDate = null, ?string $endDate = null, array $config = []): Response
    {
        return (new \Core\Services\SyncService())->execute('google_search_console', $startDate, $endDate, $config);
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
        if (!$logger) {
            $logger = Helpers::setLogger('metrics-processor.log');
        }

        if ($collection->isEmpty()) {
            return ['metrics' => 0, 'rows' => 0, 'duplicates' => 0];
        }

        $manager = Helpers::getManager();

        try {
            $manager->getConnection()->beginTransaction();

            // 1. Process Configurations (Ensures metric_configs exist)
            $metricConfigMap = MetricsProcessor::processMetricConfigs(
                metrics: $collection,
                manager: $manager,
                processQueries: true,
                processAccounts: true,
                processChanneledAccounts: true,
                processDimensions: true
            );

            // 2. Process Global Metrics
            $metricMap = MetricsProcessor::processMetrics(
                metrics: $collection,
                manager: $manager,
                metricConfigMap: $metricConfigMap
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
                'duplicates' => 0
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
            'processed' => $result['metrics']
        ]));
    }

    public static function getFacebookFilter(array $config, string $entityKey = '', string $filterType = 'cache_include'): ?string
    {
        return $config[$entityKey][$filterType] ?? null;
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
