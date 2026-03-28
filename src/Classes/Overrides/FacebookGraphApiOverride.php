<?php

namespace Classes\Overrides;

use Anibalealvarezs\FacebookGraphApi\FacebookGraphApi;
use Anibalealvarezs\FacebookGraphApi\Enums\FacebookPostField;
use Anibalealvarezs\FacebookGraphApi\Enums\InstagramMediaField;
use Anibalealvarezs\FacebookGraphApi\Enums\TokenSample;
use Exception;
use Psr\Log\LoggerInterface;

class FacebookGraphApiOverride extends FacebookGraphApi
{
    protected ?LoggerInterface $logger = null;

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * @param string $pageId
     * @param string|null $since
     * @param string|null $until
     * @param string|null $period
     * @param string|array|null $metrics
     * @param array $additionalParams
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getFacebookPageInsights(
        string $pageId,
        ?string $since = null,
        ?string $until = null,
        \Anibalealvarezs\FacebookGraphApi\Enums\MetricSet $metricSet = \Anibalealvarezs\FacebookGraphApi\Enums\MetricSet::BASIC,
        array $customMetrics = [],
    ): array {
        // Full set of metrics we want to try (from least risky to most risky)
        $metricProgression = [
            'page_impressions',           // Very basic
            'page_content_impressions',   // Extremely reliable fallback
            'page_views_total',           // Very basic
            'page_post_engagements',      // High-level interactions
            'page_fan_adds',              // Standard daily metric for follows
            'page_video_views',           // Requires video content
            'page_impressions_paid'       // Requires ad activity
        ];

        // If customMetrics are provided, we use those instead
        if (!empty($customMetrics)) {
            $metricsToTry = $customMetrics;
        } else {
            $metricsToTry = $metricProgression;
        }

        try {
            // First attempt with all metrics
            $res = parent::getFacebookPageInsights(
                pageId: $pageId,
                since: $since,
                until: $until,
                metricSet: \Anibalealvarezs\FacebookGraphApi\Enums\MetricSet::CUSTOM,
                customMetrics: $metricsToTry
            );
            
            // If the parent call succeeds but returns an empty 'data' array, we might
            // be hit by a single conflicting metric. Trigger incremental fallback.
            if ($res && !empty($res['data'])) {
                return $res;
            }
            
            $msg = "First attempt for Page $pageId returned EMPTY data. Switching to incremental search.";
            if ($this->logger) {
                $this->logger->warning("FB API: $msg");
            } else {
                error_log("FB DEBUG: $msg");
            }
            
        } catch (Exception $e) {
            $msg = $e->getMessage();
            if (stripos($msg, '(#100)') === false || (stripos($msg, 'insights metric') === false && stripos($msg, 'param is not valid') === false)) {
                throw $e; // Re-throw if not a parameter/metric error
            }
            $logMsg = "First attempt for Page $pageId FAILED with error: $msg. Switching to incremental search.";
            if ($this->logger) {
                $this->logger->warning("FB API: $logMsg");
            } else {
                error_log("FB DEBUG: $logMsg");
            }
        }

        // Start incremental strategy (reaching here means first attempt was EMPTY or errored on (#100))
        $results = ['data' => []];
        $successfulMetrics = [];
        $failedMetrics = [];

        foreach ($metricsToTry as $metric) {
            try {
                $resSingle = parent::getFacebookPageInsights(
                    pageId: $pageId,
                    since: $since,
                    until: $until,
                    metricSet: \Anibalealvarezs\FacebookGraphApi\Enums\MetricSet::CUSTOM,
                    customMetrics: [$metric]
                );
                
                if ($resSingle && !empty($resSingle['data'])) {
                    // Page insights format: data => [ [name => M1, period => day, values => [...] ], ... ]
                    $results['data'] = array_merge($results['data'], $resSingle['data']);
                    $successfulMetrics[] = $metric;
                } else {
                    $failedMetrics[] = $metric . " (EMPTY)";
                }
            } catch (Exception $eInner) {
                // error_log("FB DEBUG: Metric '$metric' FAILED for Page $pageId: " . $eInner->getMessage());
                $failedMetrics[] = $metric . " (ERROR: " . substr($eInner->getMessage(), 0, 50) . "...)";
            }
        }

        if (empty($successfulMetrics)) {
            error_log("FB DEBUG: Incremental strategy for Page $pageId FINISHED WITH NO SUCCESSFUL METRICS. Tried: [" . implode(',', $metricsToTry) . "]");
            return ['data' => []]; // Return empty instead of throwing if we tried our best
        }

        $finMsg = "Incremental strategy finished for Page $pageId. Success: [" . implode(',', $successfulMetrics) . "]. Failed: [" . implode(',', $failedMetrics) . "]";
        if ($this->logger) {
            $this->logger->info("FB API: $finMsg");
        } else {
            error_log("FB DEBUG: $finMsg");
        }

        return $results;
    }

    public function getInstagramAccountInsights(
        string $instagramAccountId,
        string $since,
        string $until,
        string $timezone = 'America/Caracas',
        \Anibalealvarezs\FacebookGraphApi\Enums\Metric|array|null $metrics = null,
        ?\Anibalealvarezs\FacebookGraphApi\Enums\MetricGroup $metricGroup = null,
        ?\Anibalealvarezs\FacebookGraphApi\Enums\MetricType $metricType = null,
        ?\Anibalealvarezs\FacebookGraphApi\Enums\MetricPeriod $metricPeriod = null,
        ?\Anibalealvarezs\FacebookGraphApi\Enums\MetricTimeframe $metricTimeframe = null,
        \Anibalealvarezs\FacebookGraphApi\Enums\MetricBreakdown|array|null $metricBreakdown = null,
    ): array {
        $metricsToTry = [];
        if ($metrics) {
            $metricsToTry = is_array($metrics) ? $metrics : [$metrics];
        } elseif ($metricGroup) {
            $metricsToTry = $metricGroup->getMetrics();
        }

        try {
            return parent::getInstagramAccountInsights(
                instagramAccountId: $instagramAccountId,
                since: $since,
                until: $until,
                timezone: $timezone,
                metrics: $metrics,
                metricGroup: $metricGroup,
                metricType: $metricType,
                metricPeriod: $metricPeriod,
                metricTimeframe: $metricTimeframe,
                metricBreakdown: $metricBreakdown,
            );
        } catch (Exception $e) {
            $msg = $e->getMessage();
            if (stripos($msg, '(#100)') === false) {
                throw $e;
            }

            $logMsg = "Starting incremental metrics search for IG Account $instagramAccountId due to error: $msg";
            if ($this->logger) {
                $this->logger->info("IG API: $logMsg");
            } else {
                error_log("IG DEBUG: $logMsg");
            }
            
            $results = ['data' => []];
            $successfulMetrics = [];
            $failedMetrics = [];

            foreach ($metricsToTry as $metric) {
                $mValue = $metric instanceof \Anibalealvarezs\FacebookGraphApi\Enums\Metric ? $metric->value : (string) $metric;
                try {
                    $res = parent::getInstagramAccountInsights(
                        instagramAccountId: $instagramAccountId,
                        since: $since,
                        until: $until,
                        timezone: $timezone,
                        metrics: [$metric],
                        metricGroup: null,
                        metricType: $metricType,
                        metricPeriod: $metricPeriod,
                        metricTimeframe: $metricTimeframe,
                        metricBreakdown: $metricBreakdown,
                    );
                    if (!empty($res['data'])) {
                        $results['data'] = array_merge($results['data'], $res['data']);
                    }
                    $successfulMetrics[] = $mValue;
                } catch (Exception $eInner) {
                    error_log("IG DEBUG: Metric '$mValue' FAILED for IG Account $instagramAccountId: " . $eInner->getMessage());
                    $failedMetrics[] = $mValue;
                }
            }

            if (empty($successfulMetrics) && !empty($metricsToTry)) {
                throw new Exception("Finished IG incremental strategy for $instagramAccountId with NO successful metrics. Last Error: $msg");
            }

            $successMsg = "Incremental strategy finished for IG Account $instagramAccountId. Success: [" . implode(',', $successfulMetrics) . "]. Failed: [" . implode(',', $failedMetrics) . "]";
            if ($this->logger) {
                $this->logger->info("IG API: $successMsg");
            } else {
                error_log("IG DEBUG: $successMsg");
            }

            return $results;
        }
    }

    /**
     * @param string $method
     * @param string $endpoint
     * @param array $query
     * @param array|string $body
     * @param array $form_params
     * @param string $baseUrl
     * @param array $headers
     * @param array $additionalHeaders
     * @param \GuzzleHttp\Cookie\CookieJar|null $cookies
     * @param bool $verify
     * @param bool $allowNewToken
     * @param string $pathToSave
     * @param bool|null $stream
     * @param mixed $errorMessageNesting
     * @param int $sleep
     * @param array $customErrors
     * @param bool $ignoreAuth
     * @param \Anibalealvarezs\FacebookGraphApi\Enums\TokenSample $tokenSample
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws Exception
     */
    public function performRequest(
        string $method,
        string $endpoint,
        array $query = [],
        array|string $body = "",
        array $form_params = [],
        string $baseUrl = "",
        array $headers = [],
        array $additionalHeaders = [],
        ?\GuzzleHttp\Cookie\CookieJar $cookies = null,
        bool $verify = false,
        bool $allowNewToken = true,
        string $pathToSave = "",
        ?bool $stream = null,
        mixed $errorMessageNesting = null,
        int $sleep = 0,
        array $customErrors = [],
        bool $ignoreAuth = false,
        mixed $onFailure = null,
        TokenSample $tokenSample = TokenSample::USER,
    ): mixed {
        $logFile = __DIR__ . '/../../../logs/facebook_api_debug.log';
        $logMessage = "[" . date('Y-m-d H:i:s') . "] REQUEST: $method $endpoint\n";
        $logMessage .= "QUERY: " . json_encode($query, JSON_PRETTY_PRINT) . "\n";
        if ($body) $logMessage .= "BODY: " . (is_array($body) ? json_encode($body, JSON_PRETTY_PRINT) : $body) . "\n";
        if ($form_params) $logMessage .= "FORM PARAMS: " . json_encode($form_params, JSON_PRETTY_PRINT) . "\n";
        $logMessage .= "--------------------------------------------------\n";
        
        file_put_contents($logFile, $logMessage, FILE_APPEND);

        $result = parent::performRequest(
            method: $method,
            endpoint: $endpoint,
            query: $query,
            body: $body,
            form_params: $form_params,
            baseUrl: $baseUrl,
            headers: $headers,
            additionalHeaders: $additionalHeaders,
            cookies: $cookies,
            verify: $verify,
            allowNewToken: $allowNewToken,
            pathToSave: $pathToSave,
            stream: $stream,
            errorMessageNesting: $errorMessageNesting,
            sleep: $sleep,
            customErrors: $customErrors,
            ignoreAuth: $ignoreAuth,
            onFailure: $onFailure,
            tokenSample: $tokenSample,
        );

        $responseBody = $result->getBody()->getContents();
        $result->getBody()->rewind(); // Rewind for SDK to read again

        $logMessage = "[" . date('Y-m-d H:i:s') . "] RESPONSE: " . substr($responseBody, 0, 1000) . (strlen($responseBody) > 1000 ? "..." : "") . "\n";
        $logMessage .= "==================================================\n";
        
        file_put_contents($logFile, $logMessage, FILE_APPEND);

        return $result;
    }
}
