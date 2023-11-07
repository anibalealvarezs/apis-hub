<?php

namespace Classes\Requests;

use Chmw\ShopifyApi\ShopifyApi;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;

class DiscountRequests
{
    /**
     * @param int|null $sinceId
     * @param object|null $filters
     * @return array
     * @throws GuzzleException
     */
    public static function getListFromShopify(int $sinceId = null, object $filters = null): array
    {
        $config = Helpers::getChannelsConfig()['shopify'];
        $shopifyClient = new ShopifyApi(
            apiKey: $config['shopify_api_key'],
            shopName: $config['shopify_shop_name'],
            version: $config['shopify_last_stable_revision'],
        );
        $priceRules = $shopifyClient->getAllPriceRules(
            createdAtMin: $filters->createdAtMin ?? null,
            createdAtMax: $filters->createdAtMax ?? null,
            endsAtMin: $filters->endsAtMin ?? null,
            endsAtMax: $filters->endsAtMax ?? null,
            sinceId: $sinceId,
            startsAtMin: $filters->startsAtMin ?? null,
            startsAtMax: $filters->startsAtMax ?? null,
            timesUsed: $filters->timesUsed ?? null,
            updatedAtMin: $filters->updatedAtMin ?? null,
            updatedAtMax: $filters->updatedAtMax ?? null,
        );
        $discounts = [];
        foreach ($priceRules as $priceRule) {
            $discountCodes = $shopifyClient->getAllDiscountCodes(
                priceRuleId: $priceRule['id'],
            );
            $discountCodes = array_map(function ($discountCode) use ($priceRule) {
                $discountCode['price_rule'] = $priceRule;
                return $discountCode;
            }, $discountCodes);
            $discounts[] = $discountCodes;
        }
        return $discounts;
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
}