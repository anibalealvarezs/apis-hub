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
        $query = [
            'fields' => 'id,message,created_time,updated_time,full_picture,permalink_url,shares,is_hidden,is_published,status_type,attachments,from,message_tags,story,story_tags,admin_creator,actions,application,is_eligible_for_promotion,is_expired,icon,is_spherical,is_popular,timeline_visibility,height,width,backdated_time,scheduled_publish_time,is_inline_created,child_attachments,parent_id,promotable_id,properties,via',
            'limit' => min($limit, 100),
        ];

        if (!empty($additionalParams)) {
            $query = array_merge($query, $additionalParams);
        }

        $posts = [];
        $after = null;

        $this->setPageId($pageId);

        do {
            if ($after) {
                $query['after'] = $after;
            }

            $response = $this->performRequest(
                method: 'GET',
                endpoint: 'v25.0/'.$pageId.'/posts',
                query: $query,
                sleep: 200000, // Reduced from 1,000,000 to 200,000 (0.2s)
                tokenSample: TokenSample::PAGE,
            );

            $data = json_decode($response->getBody()->getContents(), true);

            $posts = [...$posts, ...($data['data'] ?? [])];

            $after = $data['paging']['cursors']['after'] ?? null;
        } while ($after && count($data['data'] ?? []) > 0);

        return ['data' => $posts];
    }

    /**
     * @param string $igUserId
     * @param string|array|null $mediaFields
     * @param int $limit
     * @param array $additionalParams
     * @return array
     * @throws Exception
     */
    public function getInstagramMedia(
        string $igUserId,
        string|array|null $mediaFields = null,
        int $limit = 100,
        array $additionalParams = [],
    ): array {
        $query = [
            'fields' => $mediaFields ?
                (
                    is_array($mediaFields) ?
                    implode(',', array_map(fn ($field) => (
                        $field instanceof InstagramMediaField ?
                        $field->value :
                        $field
                    ), $mediaFields)) :
                    $mediaFields
                ) :
                InstagramMediaField::toCommaSeparatedList(),
            'limit' => min($limit, 100)
        ];

        if (!empty($additionalParams)) {
            $query = array_merge($query, $additionalParams);
        }

        $media = [];
        $after = null;

        try {
            do {
                if ($after) {
                    $query['after'] = $after;
                }

                $response = $this->performRequest(
                    method: 'GET',
                    endpoint: $igUserId."/media",
                    query: $query,
                    sleep: 200000, // Reduced from 1,000,000 to 200,000 (0.2s)
                );
                $data = json_decode($response->getBody()->getContents(), true);

                $media = array_merge($media, $data['data'] ?? []);
                $after = $data['paging']['cursors']['after'] ?? null;
            } while ($after && count($data['data'] ?? []) > 0);

            return ['data' => $media];
        } catch (Exception $e) {
            throw new Exception("Failed to retrieve media for Instagram ID ".$igUserId.": ".$e->getMessage());
        }
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
