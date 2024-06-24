<?php

namespace Classes\Overrides\ShopifyApi;

use Chmw\ShopifyApi\Enums\FinancialStatus;
use Chmw\ShopifyApi\Enums\FulfillmentStatus;
use Chmw\ShopifyApi\Enums\Status;
use GuzzleHttp\Exception\GuzzleException;

class ShopifyApi extends \Chmw\ShopifyApi\ShopifyApi
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
     * @param callable|null $callback
     * @return array
     * @throws GuzzleException
     */
    public function getAllCustomers(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?array $fields = null,
        ?array $ids = null,
        ?int $limit = 250, // Max: 250,
        ?int $sinceId = null,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
        callable $callback = null,
    ): array {
        $pageInfo = null;

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
            );
            if (!empty($response['body']['customers'])) {
                if ($callback) {
                    $callback($response['body']['customers']);
                }
            }
        } while (isset($response['headers']) && ($pageInfo = $this->getNextCursorLink($response['headers'])));

        return [];
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
     * @param callable|null $callback
     * @return array
     * @throws GuzzleException
     */
    public function getAllOrders(
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
        callable $callback = null,
    ): array {
        $pageInfo = null;

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
            );
            if (!empty($response['body']['orders'])) {
                if ($callback) {
                    $callback($response['body']['orders']);
                }
            }
        } while (isset($response['headers']) && ($pageInfo = $this->getNextCursorLink($response['headers'])));

        return [];
    }
}