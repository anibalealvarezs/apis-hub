<?php

namespace Classes\Overrides\ShopifyApi;

use Anibalealvarezs\ShopifyApi\Enums\FinancialStatus;
use Anibalealvarezs\ShopifyApi\Enums\FulfillmentStatus;
use Anibalealvarezs\ShopifyApi\Enums\PublishedStatus;
use Anibalealvarezs\ShopifyApi\Enums\SortOptions;
use Anibalealvarezs\ShopifyApi\Enums\Status;
use GuzzleHttp\Exception\GuzzleException;

class ShopifyApi extends \Anibalealvarezs\ShopifyApi\ShopifyApi
{
    /**
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param array|null $fields
     * @param array|null $ids
     * @param int|null $limit
     * @param int|null $sinceId
     * @param string|null $updatedAtMin
     * @param string|null $updatedAtMax
     * @param string|null $pageInfo
     * @param callable|null $callback
     * @param SortOptions $sort
     * @return void
     * @throws GuzzleException
     */
    public function getAllCustomersAndProcess(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?array $fields = null,
        ?array $ids = null,
        ?int $limit = 250, // Max: 250,
        ?int $sinceId = null,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
        ?string $pageInfo = null,
        callable $callback = null,
        SortOptions $sort = SortOptions::idAsc,
    ): void {

        do {
            $response = $this->getCustomers(
                pageInfo: $pageInfo,
                createdAtMin: $createdAtMin,
                createdAtMax: $createdAtMax,
                fields: $fields,
                ids: $ids,
                limit: $limit,
                sinceId: $sinceId,
                updatedAtMin: $updatedAtMin,
                updatedAtMax: $updatedAtMax,
                includeHeaders: true,
                sort: $sort,
            );
            if (!empty($response['body']['customers'])) {
                if ($callback) {
                    $callback($response['body']['customers']);
                }
            }
        } while (isset($response['headers']) && ($pageInfo = $this->getNextCursorLink($response['headers'])));
    }

    /**
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param array|null $fields
     * @param FinancialStatus|null $financialStatus
     * @param FulfillmentStatus|null $fulfillmentStatus
     * @param array|null $ids
     * @param int|null $limit
     * @param string|null $processedAtMin
     * @param string|null $processedAtMax
     * @param int|null $sinceId
     * @param Status|null $status
     * @param string|null $updatedAtMin
     * @param string|null $updatedAtMax
     * @param string|null $pageInfo
     * @param callable|null $callback
     * @param SortOptions $sort
     * @return void
     * @throws GuzzleException
     */
    public function getAllOrdersAndProcess(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?array $fields = null,
        ?FinancialStatus $financialStatus = null,
        ?FulfillmentStatus $fulfillmentStatus = null,
        ?array $ids = null,
        ?int $limit = 250, // Max: 250,
        ?string $processedAtMin = null,
        ?string $processedAtMax = null,
        ?int $sinceId = null,
        ?Status $status = Status::any,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
        ?string $pageInfo = null,
        callable $callback = null,
        SortOptions $sort = SortOptions::idAsc,
    ): void {

        do {
            $response = $this->getOrders(
                pageInfo: $pageInfo,
                createdAtMin: $createdAtMin,
                createdAtMax: $createdAtMax,
                fields: $fields,
                financialStatus: $financialStatus,
                fulfillmentStatus: $fulfillmentStatus,
                ids: $ids,
                limit: $limit,
                processedAtMin: $processedAtMin,
                processedAtMax: $processedAtMax,
                sinceId: $sinceId,
                status: $status,
                updatedAtMin: $updatedAtMin,
                updatedAtMax: $updatedAtMax,
                includeHeaders: true,
                sort: $sort,
            );
            if (!empty($response['body']['orders'])) {
                if ($callback) {
                    $callback($response['body']['orders']);
                }
            }
        } while (isset($response['headers']) && ($pageInfo = $this->getNextCursorLink($response['headers'])));
    }

    /**
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param string|null $endsAtMin
     * @param string|null $endsAtMax
     * @param int|null $limit
     * @param int|null $sinceId
     * @param string|null $startsAtMin
     * @param string|null $startsAtMax
     * @param int|null $timesUsed
     * @param string|null $updatedAtMin
     * @param string|null $updatedAtMax
     * @param string|null $pageInfo
     * @param callable|null $callback
     * @param SortOptions $sort
     * @return void
     * @throws GuzzleException
     */
    public function getAllPriceRulesAndProcess(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?string $endsAtMin = null,
        ?string $endsAtMax = null,
        ?int $limit = 250, // Max: 250,
        ?int $sinceId = null,
        ?string $startsAtMin = null,
        ?string $startsAtMax = null,
        ?int $timesUsed = null,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
        ?string $pageInfo = null,
        callable $callback = null,
        SortOptions $sort = SortOptions::idAsc,
    ): void {

        do {
            $response = $this->getPriceRules(
                pageInfo: $pageInfo,
                createdAtMin: $createdAtMin,
                createdAtMax: $createdAtMax,
                endsAtMin: $endsAtMin,
                endsAtMax: $endsAtMax,
                limit: $limit,
                sinceId: $sinceId,
                startsAtMin: $startsAtMin,
                startsAtMax: $startsAtMax,
                timesUsed: $timesUsed,
                updatedAtMin: $updatedAtMin,
                updatedAtMax: $updatedAtMax,
                includeHeaders: true,
                sort: $sort,
            );
            if (!empty($response['body']['price_rules'])) {
                if ($callback) {
                    $callback($response['body']['price_rules']);
                }
            }
        } while (isset($response['headers']) && ($pageInfo = $this->getNextCursorLink($response['headers'])));
    }

    /**
     * @param int|null $priceRuleId
     * @param int|null $limit
     * @param string|null $pageInfo
     * @param callable|null $callback
     * @return void
     * @throws GuzzleException
     */
    public function getAllDiscountCodesAndProcess(
        ?int $priceRuleId,
        ?int $limit = 250, // Max: 250,
        ?string $pageInfo = null,
        callable $callback = null,
    ): void {

        do {
            $response = $this->getDiscountCodes(
                priceRuleId: $priceRuleId,
                pageInfo: $pageInfo,
                limit: $limit,
                includeHeaders: true,
            );
            if (!empty($response['body']['discount_codes'])) {
                if ($callback) {
                    $callback($response['body']['discount_codes']);
                }
            }
        } while (isset($response['headers']) && ($pageInfo = $this->getNextCursorLink($response['headers'])));
    }

    /**
     * @param string|null $collectionId
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param array|null $fields
     * @param array|null $handle
     * @param array|null $ids
     * @param int|null $limit
     * @param array|null $presentmentCurrencies
     * @param string|null $productType
     * @param string|null $publishedAtMin
     * @param string|null $publishedAtMax
     * @param int|null $sinceId
     * @param PublishedStatus|null $status
     * @param string|null $title
     * @param string|null $updatedAtMin
     * @param string|null $updatedAtMax
     * @param string|null $vendor
     * @param string|null $pageInfo
     * @param callable|null $callback
     * @param SortOptions $sort
     * @return void
     * @throws GuzzleException
     */
    public function getAllProductsAndProcess(
        ?string $collectionId = null,
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?array $fields = null,
        ?array $handle = null,
        ?array $ids = null,
        ?int $limit = 250, // Max: 250,
        ?array $presentmentCurrencies = null,
        ?string $productType = null,
        ?string $publishedAtMin = null,
        ?string $publishedAtMax = null,
        ?int $sinceId = null,
        ?PublishedStatus $status = null,
        ?string $title = null,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
        ?string $vendor = null,
        ?string $pageInfo = null,
        callable $callback = null,
        SortOptions $sort = SortOptions::idAsc,
    ): void {

        do {
            $response = $this->getProducts(
                pageInfo: $pageInfo,
                collectionId: $collectionId,
                createdAtMin: $createdAtMin,
                createdAtMax: $createdAtMax,
                fields: $fields,
                handle: $handle,
                ids: $ids,
                limit: $limit,
                presentmentCurrencies: $presentmentCurrencies,
                productType: $productType,
                publishedAtMin: $publishedAtMin,
                publishedAtMax: $publishedAtMax,
                sinceId: $sinceId,
                status: $status,
                title: $title,
                updatedAtMin: $updatedAtMin,
                updatedAtMax: $updatedAtMax,
                vendor: $vendor,
                includeHeaders: true,
                sort: $sort,
            );
            if (!empty($response['body']['products'])) {
                if ($callback) {
                    $callback($response['body']['products']);
                }
            }
        } while (isset($response['headers']) && ($pageInfo = $this->getNextCursorLink($response['headers'])));
    }
}