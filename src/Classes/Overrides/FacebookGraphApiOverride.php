<?php

namespace Classes\Overrides;

use Anibalealvarezs\FacebookGraphApi\FacebookGraphApi;
use Anibalealvarezs\FacebookGraphApi\Enums\FacebookPostField;
use Anibalealvarezs\FacebookGraphApi\Enums\InstagramMediaField;
use Anibalealvarezs\FacebookGraphApi\Enums\TokenSample;
use Exception;

class FacebookGraphApiOverride extends FacebookGraphApi
{
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
        // Experimental Purge for v25.0:
        // If we are using the BASIC set, we intercept it and switch to a sanitized CUSTOM list
        if ($metricSet === \Anibalealvarezs\FacebookGraphApi\Enums\MetricSet::BASIC && empty($customMetrics)) {
            $customMetrics = ['page_impressions', 'page_impressions_paid', 'page_video_views'];
            $metricSet = \Anibalealvarezs\FacebookGraphApi\Enums\MetricSet::CUSTOM;
        }

        return parent::getFacebookPageInsights(
            pageId: $pageId,
            since: $since,
            until: $until,
            metricSet: $metricSet,
            customMetrics: $customMetrics
        );
    }
}
