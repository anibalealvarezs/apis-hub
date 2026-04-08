<?php

namespace Channels\Klaviyo;

use Interfaces\SyncDriverInterface;
use Interfaces\AuthProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use Helpers\Helpers;
use DateTime;
use Exception;
use Classes\Conversions\KlaviyoConvert;
use Classes\Overrides\KlaviyoApi\KlaviyoApi;
use Classes\Requests\MetricRequests;
use Classes\Requests\CustomerRequests;
use Classes\Requests\ProductRequests;
use Anibalealvarezs\KlaviyoApi\Enums\AggregatedMeasurement;

class KlaviyoDriver implements SyncDriverInterface
{
    private ?AuthProviderInterface $authProvider = null;
    private ?LoggerInterface $logger = null;

    public function __construct(?AuthProviderInterface $authProvider = null, ?LoggerInterface $logger = null)
    {
        $this->authProvider = $authProvider;
        $this->logger = $logger;
    }

    public function setAuthProvider(AuthProviderInterface $provider): void
    {
        $this->authProvider = $provider;
    }

    public function getChannel(): string
    {
        return 'klaviyo';
    }

    public function sync(DateTime $startDate, DateTime $endDate, array $config = []): Response
    {
        if (!$this->authProvider instanceof \Core\Auth\KlaviyoAuthProvider) {
            throw new Exception("Invalid or missing AuthProvider for KlaviyoDriver");
        }

        if (!$this->logger) {
            $this->logger = Helpers::setLogger('klaviyo-driver.log');
        }

        $type = $config['type'] ?? 'metrics';
        $jobId = $config['jobId'] ?? null;

        $this->logger->info("Starting KlaviyoDriver sync...", ['type' => $type]);

        try {
            $api = new KlaviyoApi(
                apiKey: $this->authProvider->getAccessToken()
            );

            return match ($type) {
                'metrics' => $this->syncMetrics($api, $startDate, $endDate, $config),
                'customers' => $this->syncCustomers($api, $startDate, $endDate, $config),
                'products' => $this->syncProducts($api, $config),
                default => throw new Exception("Unsupported entity type for Klaviyo: {$type}"),
            };

        } catch (Exception $e) {
            $this->logger->error("KlaviyoDriver error: " . $e->getMessage());
            throw $e;
        }
    }

    private function syncMetrics(KlaviyoApi $api, DateTime $startDate, DateTime $endDate, array $config): Response
    {
        $jobId = $config['jobId'] ?? null;
        $metricNames = $config['metricNames'] ?? (Helpers::getChannelsConfig()['klaviyo']['metrics'] ?? []);
        $metricIds = [];
        $metricMap = [];

        $api->getAllMetricsAndProcess(
            metricFields: ['id', 'name'],
            callback: function ($metrics) use (&$metricIds, &$metricMap, $metricNames, $jobId) {
                Helpers::checkJobStatus($jobId);
                foreach ($metrics as $metric) {
                    if (empty($metricNames) || in_array($metric['attributes']['name'], $metricNames)) {
                        $metricIds[] = $metric['id'];
                        $metricMap[$metric['id']] = $metric['attributes']['name'];
                    }
                }
            }
        );

        $formattedFilters = [
            ["operator" => "greater-than", "field" => "datetime", "value" => $startDate->format('Y-m-d H:i:s')],
            ["operator" => "less-than", "field" => "datetime", "value" => $endDate->format('Y-m-d H:i:s')]
        ];

        foreach ($metricIds as $metricId) {
            $this->logger->info("Processing Klaviyo metric: " . ($metricMap[$metricId] ?? $metricId));
            $api->getAllMetricAggregatesAndProcess(
                metricId: $metricId,
                measurements: [AggregatedMeasurement::count],
                filter: $formattedFilters,
                sortField: 'datetime',
                callback: function ($aggregates) use ($metricId, $metricMap, $jobId) {
                    Helpers::checkJobStatus($jobId);
                    MetricRequests::process(KlaviyoConvert::metricAggregates($aggregates, $metricId, $metricMap));
                }
            );
        }

        return new Response(json_encode(['status' => 'success', 'message' => 'Klaviyo metrics sync completed']));
    }

    private function syncCustomers(KlaviyoApi $api, DateTime $startDate, DateTime $endDate, array $config): Response
    {
        $jobId = $config['jobId'] ?? null;
        $fields = $config['fields'] ?? null;
        
        $this->logger->info("Syncing Klaviyo Customers...");
        
        $api->getAllProfilesAndProcess(
            profileFields: $fields,
            additionalFields: ['predictive_analytics', 'subscriptions'],
            filter: [
                ["operator" => "greater-than", "field" => "created", "value" => $startDate->format('Y-m-d H:i:s')],
                ["operator" => "less-than", "field" => "created", "value" => $endDate->format('Y-m-d H:i:s')],
            ],
            sortField: 'created',
            callback: function ($customers) use ($jobId) {
                Helpers::checkJobStatus($jobId);
                CustomerRequests::process(KlaviyoConvert::customers($customers));
            }
        );

        return new Response(json_encode(['status' => 'success', 'message' => 'Klaviyo customers sync completed']));
    }

    private function syncProducts(KlaviyoApi $api, array $config): Response
    {
        $jobId = $config['jobId'] ?? null;
        $fields = $config['fields'] ?? null;
        $filters = $config['filters'] ?? null;

        $this->logger->info("Syncing Klaviyo Products...");

        $formattedFilters = [];
        if ($filters) {
            foreach ($filters as $key => $value) {
                $formattedFilters[] = [
                    "operator" => 'equals',
                    "field" => $key,
                    "value" => $value,
                ];
            }
        }

        $api->getAllCatalogItemsAndProcess(
            catalogItemsFields: $fields,
            filter: $formattedFilters,
            callback: function ($products) use ($jobId) {
                Helpers::checkJobStatus($jobId);
                ProductRequests::process(KlaviyoConvert::products($products));
            }
        );

        return new Response(json_encode(['status' => 'success', 'message' => 'Klaviyo products sync completed']));
    }
}
