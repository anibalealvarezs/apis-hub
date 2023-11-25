<?php

namespace Classes\Conversions;

use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channels;

class ShopifyConvert
{
    public static function customers(array $customers): ArrayCollection
    {
        return new ArrayCollection(array_map(function($customer) {
            return (object) [
                'platformId' => $customer['id'],
                'channel' => Channels::shopify->value,
                'email' => $customer['email'],
                'data' => $customer,
            ];
        }, $customers));
    }

    /**
     * @param array $discounts
     * @return ArrayCollection
     */
    public static function discounts(array $discounts): ArrayCollection
    {
        return new ArrayCollection(array_map(function($discount) {
            return (object) [
                'platformId' => $discount['id'],
                'channel' => Channels::shopify->value,
                'code' => $discount['code'],
                'data' => $discount,
            ];
        }, $discounts));
    }

    public static function priceRules(array $priceRules): ArrayCollection
    {
        return new ArrayCollection(array_map(function($priceRule) {
            return (object) [
                'platformId' => $priceRule['id'],
                'channel' => Channels::shopify->value,
                'data' => $priceRule,
            ];
        }, $priceRules));
    }

    public static function orders(array $orders): ArrayCollection
    {
        return new ArrayCollection(array_map(function($order) {
            return (object) [
                'platformId' => $order['id'],
                'channel' => Channels::shopify->value,
                'data' => $order,
                'customer' => (object) $order['customer'],
                'discountCodes' => !empty($order['discount_codes']) ?
                    array_map(function($discountCode) {
                        return $discountCode['code'];
                    }, $order['discount_codes']) :
                    [],
                'lineItems' => $order['line_items'] ?? '',
            ];
        }, $orders));
    }

    public static function products(array $products): ArrayCollection
    {
        return new ArrayCollection(array_map(function($product) {
            return (object) [
                'platformId' => $product['id'],
                'channel' => Channels::shopify->value,
                'data' => $product,
                'vendor' => $product['vendor'],
                'variants' => self::productVariants($product['variants']),
            ];
        }, $products));
    }

    public static function productVariants(array $productVariants): ArrayCollection
    {
        return new ArrayCollection(array_map(function($productVariant) {
            return (object) [
                'platformId' => $productVariant['id'],
                'channel' => Channels::shopify->value,
                'data' => $productVariant,
            ];
        }, $productVariants));
    }

    public static function productCategories(array $productCategories, bool $isSmartCollection = false): ArrayCollection
    {
        return new ArrayCollection(array_map(function($productCategory) use ($isSmartCollection) {
            return (object) [
                'platformId' => $productCategory['id'],
                'channel' => Channels::shopify->value,
                'data' => $productCategory,
                'isSmartCollection' => $isSmartCollection,
            ];
        }, $productCategories));
    }

    public static function collects(array $collects): ArrayCollection
    {
        $collectionsProducts = [];
        foreach ($collects as $collect) {
            $collectionsProducts[$collect['collection_id']][] = $collect['product_id'];
        }

        return new ArrayCollection($collectionsProducts);
    }
}