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
            'fields' => $postFields ?
                (
                    is_array($postFields) ?
                    implode(',', array_map(fn ($field) => (
                        $field instanceof FacebookPostField ?
                        $field->value :
                        $field
                    ), $postFields)) :
                    $postFields
                ) :
                FacebookPostField::toCommaSeparatedList()
                . ($includeAttachments ? ',attachments' : '')
                . ($includeComments ? ',comments' : '')
                . ($includeReactions ? ',reactions' : '')
                . ($includeDynamicPosts ? ',dynamic_posts' : '')
                . ($includeSharedPosts ? ',sharedposts' : '')
                . ($includeSponsorTags ? ',sponsor_tags' : '')
                . ($includeTo ? ',to' : ''),
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
                endpoint: $pageId.'/posts',
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
}
