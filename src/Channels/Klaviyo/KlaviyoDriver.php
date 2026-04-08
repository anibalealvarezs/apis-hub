<?php

namespace Channels\Klaviyo;

use Interfaces\SyncDriverInterface;
use Interfaces\AuthProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use Helpers\Helpers;
use Carbon\Carbon;
use DateTime;
use Exception;
use Classes\Conversions\KlaviyoConvert;
use Classes\Overrides\KlaviyoApi\KlaviyoApi;
use Classes\Requests\MetricRequests;
use Anibalealvarezs\KlaviyoApi\Enums\AggregatedMeasurement;
use Enums\Channel;
use Entities\Analytics\Channeled\ChanneledMetric;

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

        $jobId = $config['jobId'] ?? null;
        $resume = $config['resume'] ?? true;

        $this->logger->info("Starting KlaviyoDriver sync...");

        try {
            $api = new KlaviyoApi(
                apiKey: $this->authProvider->getAccessToken()
            );

            // 1. Resolve Metrics
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

            // 2. Build Filters
            $formattedFilters = [
                [
                    "operator" => "greater-than",
                    "field" => "datetime",
                    "value" => $startDate->format('Y-m-d H:i:s'),
                ],
                [
                    "operator" => "less-than",
                    "field" => "datetime",
                    "value" => $endDate->format('Y-m-d H:i:s'),
                ]
            ];

            // 3. Process each metric
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

            return new Response(json_encode(['status' => 'success', 'message' => 'Klaviyo sync completed']));

        } catch (Exception $e) {
            $this->logger->error("KlaviyoDriver error: " . $e->getMessage());
            throw $e;
        }
    }
}
