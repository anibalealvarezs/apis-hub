<?php

namespace Classes\Requests;

use Chmw\ShopifyApi\ShopifyApi;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;

class OrderRequests
{
    /**
     * @param int|null $sinceId
     * @param array|null $fields
     * @param object|null $filters
     * @return array
     * @throws GuzzleException
     */
    public static function getListFromShopify(int $sinceId = null, array $fields = null, object $filters = null): array
    {
        $config = Helpers::getChannelsConfig()['shopify'];
        $shopifyClient = new ShopifyApi(
            apiKey: $config['shopify_api_key'],
            shopName: $config['shopify_shop_name'],
            version: $config['shopify_last_stable_revision'],
        );
        return $shopifyClient->getAllOrders(
            createdAtMin: $filters->createdAtMin ?? null,
            createdAtMax: $filters->createdAtMax ?? null,
            fields: $fields, // Example: ["id", "processed_at", "total_price", "total_discounts", "discount_codes", "customer", "line_items"]
            financialStatus: $filters->financialStatus ?? null,
            fulfillmentStatus: $filters->fulfillmentStatus ?? null,
            ids: $filters->ids ?? null,
            processedAtMin: $filters->processedAtMin ?? null,
            processedAtMax: $filters->processedAtMax ?? null,
            sinceId: $sinceId,
            status: $filters->status ?? null,
            updatedAtMin: $filters->updatedAtMin ?? null,
            updatedAtMax: $filters->updatedAtMax ?? null,
        );
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @return array
     */
    public static function getListFromBigCommerce(int $limit = 10, int $pagination = 0, object $filters = null): array
    {
        //
        return [];
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @return array
     */
    public static function getListFromNetsuite(int $limit = 10, int $pagination = 0, object $filters = null): array
    {
        //
        return [];
    }

    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @return array
     */
    public static function getListFromAmazon(int $limit = 10, int $pagination = 0, object $filters = null): array
    {
        //
        return [];
    }
}