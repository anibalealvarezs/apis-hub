<?php

namespace Classes\Conversions;

use Carbon\Carbon;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channels;

class KlaviyoConvert
{
    public static function customers(array $customers): ArrayCollection
    {
        return new ArrayCollection(array_map(function($customer) {
            return (object) [
                'platformId' => $customer['id'],
                'platformCreatedAt' => Carbon::parse($customer['attributes']['created']),
                'channel' => Channels::klaviyo->value,
                'email' => $customer['attributes']['email'],
                'data' => $customer,
            ];
        }, $customers));
    }

    public static function products(array $products): ArrayCollection
    {
        return new ArrayCollection(array_map(function($product) {
            return (object) [
                'platformId' => $product['id'],
                'sku' => $product['sku'] ?? '',
                'platformCreatedAt' => isset($product['attributes']['created']) ? Carbon::parse($product['attributes']['created']) : null,
                'channel' => Channels::klaviyo->value,
                'data' => $product,
                'vendor' => null,
                'variants' => self::productVariants($product['included']),
            ];
        }, $products));
    }

    public static function productVariants(array $productVariants): ArrayCollection
    {
        return new ArrayCollection(array_map(function($productVariant) {
            return (object) [
                'platformId' => $productVariant['id'],
                'sku' => $productVariant['sku'] ?? '',
                'platformCreatedAt' => isset($productVariant['attributes']['created']) ? Carbon::parse($productVariant['attributes']['created']) : null,
                'channel' => Channels::klaviyo->value,
                'data' => $productVariant,
            ];
        }, $productVariants));
    }

    public static function productCategories(array $productCategories): ArrayCollection
    {
        return new ArrayCollection(array_map(function($productCategory) {
            return (object) [
                'platformId' => $productCategory['id'],
                'platformCreatedAt' => isset($productCategory['attributes']['created']) ? Carbon::parse($productCategory['attributes']['created']) : null,
                'channel' => Channels::klaviyo->value,
                'data' => $productCategory,
            ];
        }, $productCategories));
    }
}