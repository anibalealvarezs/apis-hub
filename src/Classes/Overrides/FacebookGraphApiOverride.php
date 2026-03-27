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

    /**
     * @param string $pageId
     * @param string|array|null $postFields
     * @param bool $includeAttachments
     * @param bool $includeComments
     * @param bool $includeReactions
     * @param bool $includeDynamicPosts
     * @param bool $includeSharedPosts
     * @param bool $includeSponsorTags
     * @param bool $includeTo
     * @param int $limit
     * @param array $additionalParams
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getFacebookPosts(
        string $pageId,
        string|array|null $postFields = null,
        bool $includeAttachments = false,
        bool $includeComments = false,
        bool $includeReactions = false,
        bool $includeDynamicPosts = false,
        bool $includeSharedPosts = false,
        bool $includeSponsorTags = false,
        bool $includeTo = false,
        int $limit = 10,
        array $additionalParams = [],
    ): array {
        // Strict White List for v25.0 Stabilization
        $safeFields = 'id,message,created_time,status_type,story,story_tags,shares,full_picture,permalink_url,from,updated_time,is_published,is_hidden,is_expired,is_popular,is_spherical';
        
        if ($includeTo) {
            $safeFields .= ',to';
        }

        return parent::getFacebookPosts(
            pageId: $pageId,
            postFields: $safeFields,
            includeAttachments: $includeAttachments,
            includeComments: $includeComments,
            includeReactions: $includeReactions,
            includeDynamicPosts: $includeDynamicPosts,
            includeSharedPosts: $includeSharedPosts,
            includeSponsorTags: $includeSponsorTags,
            includeTo: $includeTo,
            limit: $limit,
            additionalParams: $additionalParams
        );
    }
}
