<?php

namespace Classes\Requests;

class VendorRequests
{
    /**
     * @param int $limit
     * @param int $pagination
     * @param object|null $filters
     * @return array
     */
    public static function getListFromShopify(int $limit = 10, int $pagination = 0, object $filters = null): array
    {
        return ['Vendors are not supported in Shopify. They\'re retrieved along with Products.'];
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