<?php

namespace Classes\Requests;

use Chmw\ShopifyApi\ShopifyApi;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;

class CustomerRequests
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
        return $shopifyClient->getAllCustomers(
            createdAtMin: $filters->createdAtMin ?? null,
            createdAtMax: $filters->createdAtMax ?? null,
            fields: $fields,
            ids: $filters->ids ?? null,
            sinceId: $sinceId,
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
    public static function getListFromKlaviyo(int $limit = 10, int $pagination = 0, object $filters = null): array
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
    public static function getListFromFacebook(int $limit = 10, int $pagination = 0, object $filters = null): array
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