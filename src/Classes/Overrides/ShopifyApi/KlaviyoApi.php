<?php

namespace Classes\Overrides\ShopifyApi;

use Chmw\KlaviyoApi\Enums\Sort;
use GuzzleHttp\Exception\GuzzleException;

class KlaviyoApi extends \Chmw\KlaviyoApi\KlaviyoApi
{
    /**
     * @param array|null $profileFields
     * @param array|null $additionalFields
     * @param array|null $filter
     * @param Sort|null $sort
     * @param string|null $sortField
     * @param callable|null $callback
     * @return void
     * @throws GuzzleException
     */
    public function getAllProfilesAndProcess(
        ?array $profileFields = null,
        ?array $additionalFields = null, // Options: 'subscriptions', 'predictive_analytics'
        ?array $filter = null, // Format [["operator" => ["equals",...], "field" => "name", "value" => "String"], ...]
        ?Sort $sort = Sort::ascending,
        ?string $sortField = null,
        callable $callback = null,
    ): void {
        $cursor = null;

        do {
            $response = $this->getProfiles(
                profileFields: $profileFields,
                additionalFields: $additionalFields,
                filter: $filter,
                sort: $sort,
                sortField: $sortField,
                cursor: $cursor,
            );
            if (!empty($response['data'])) {
                if ($callback) {
                    $callback($response['data']);
                }
            }
        } while (isset($response['links']['next']) && $response['links']['next'] && ($response['links']['next'] != "null") && ($cursor = self::getCursorFromUrl($response['links']['next'])));
    }
}