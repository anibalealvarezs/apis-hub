<?php

namespace Classes\Overrides\KlaviyoApi;

use Anibalealvarezs\KlaviyoApi\Enums\Interval;
use Anibalealvarezs\KlaviyoApi\Enums\Sort;
use GuzzleHttp\Exception\GuzzleException;

class KlaviyoApi extends \Anibalealvarezs\KlaviyoApi\KlaviyoApi
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
        ?array $additionalFields = null,
        ?array $filter = null,
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

    /**
     * Fetches all catalog items from Klaviyo and processes them with a callback.
     *
     * @param array|null $catalogItemsFields
     * @param array|null $variantFields
     * @param array|null $filter
     * @param Sort|null $sort
     * @param bool|null $includeVariants
     * @param string|null $sortField
     * @param callable|null $callback
     * @return void
     * @throws GuzzleException
     */
    public function getAllCatalogItemsAndProcess(
        ?array $catalogItemsFields = null,
        ?array $variantFields = null,
        ?array $filter = null,
        ?Sort $sort = Sort::ascending,
        ?bool $includeVariants = true,
        ?string $sortField = null,
        callable $callback = null,
    ): void {
        $cursor = null;

        do {
            $response = $this->getCatalogItems(
                catalogItemsFields: $catalogItemsFields,
                variantFields: $variantFields,
                filter: $filter,
                sort: $sort,
                includeVariants: $includeVariants,
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

    /**
     * Fetches all metrics from Klaviyo and processes them with a callback.
     *
     * @param array|null $metricFields
     * @param array|null $filter
     * @param callable|null $callback
     * @return void
     * @throws GuzzleException
     */
    public function getAllMetricsAndProcess(
        ?array $metricFields = null,
        ?array $filter = null,
        callable $callback = null,
    ): void {
        $cursor = null;

        do {
            $response = $this->getMetrics(
                cursor: $cursor,
                metricFields: $metricFields,
                filter: $filter,
            );
            if (!empty($response['data'])) {
                if ($callback) {
                    $callback($response['data']);
                }
            }
        } while (isset($response['links']['next']) && $response['links']['next'] && ($response['links']['next'] != "null") && ($cursor = self::getCursorFromUrl($response['links']['next'])));
    }

    /**
     * Fetches all metric aggregates from Klaviyo and processes them with a callback.
     *
     * @param string $metricId
     * @param array|null $returnFields
     * @param array|null $measurements
     * @param Interval $interval
     * @param array|null $filter
     * @param string|null $timezone
     * @param Sort|null $sort
     * @param string|null $sortField
     * @param callable|null $callback
     * @return void
     * @throws GuzzleException
     */
    public function getAllMetricAggregatesAndProcess(
        string $metricId,
        ?array $returnFields = null,
        ?array $measurements = null,
        Interval $interval = Interval::day,
        ?array $filter = null,
        ?string $timezone = null,
        ?Sort $sort = Sort::ascending,
        ?string $sortField = null,
        callable $callback = null,
    ): void {
        $cursor = null;

        do {
            $response = $this->getMetricAggregates(
                metricId: $metricId,
                return_fields: $returnFields,
                sort: $sort,
                sortField: $sortField,
                cursor: $cursor,
                measurements: $measurements,
                interval: $interval,
                filter: $filter,
                timezone: $timezone,
            );
            if (!empty($response['data']['attributes']['data'])) {
                if ($callback) {
                    $callback($response['data']['attributes']);
                }
            }
        } while (isset($response['links']['next']) && $response['links']['next'] && ($response['links']['next'] != "null") && ($cursor = self::getCursorFromUrl($response['links']['next'])));
    }
}
