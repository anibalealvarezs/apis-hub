<?php

namespace Classes\Conversions;

use Carbon\Carbon;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channels;

class NetSuiteConvert
{
    public static function customers(array $customers): ArrayCollection
    {
        return new ArrayCollection(array_map(function($customer) {
            return (object) [
                'platformId' => $customer['entityid'],
                'platformCreatedAt' => Carbon::parse($customer['datecreated']),
                'channel' => Channels::netsuite->value,
                'email' => $customer['email'] ?? '',
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
                'platformCreatedAt' => Carbon::parse($discount['created_at']),
                'channel' => Channels::netsuite->value,
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
                'platformCreatedAt' => Carbon::parse($priceRule['created_at']),
                'channel' => Channels::netsuite->value,
                'data' => $priceRule,
            ];
        }, $priceRules));
    }

    public static function orders(array $orders): ArrayCollection
    {
        return new ArrayCollection(array_map(function($order) {
            return (object) [
                'platformId' => $order['id'],
                'platformCreatedAt' => Carbon::parse($order['created_at']),
                'channel' => Channels::netsuite->value,
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
        $productsArray = [];
        foreach ($products as $product) {
            // Ignore Assembly intermediate parents => !is_null($product['parent'])
            // Ignore Assembly children if parent is not in the list => !isset($productsArray[$product['custitem_web_store_design_item']])
            if (($product['itemtype'] === 'Assembly') && (!isset($product['parent']) || !isset($product['custitem_web_store_design_item']))) {
                continue;
            }
            // Identify Parents
            if ($product['itemtype'] === 'NonInvtPart') {
                if (!isset($productsArray[$product['id']])){
                    $productsArray[$product['id']] = [
                        'id' => $product['id'],
                        'sku' => $product['itemid'] ?? '',
                        'created_at' => $product['createddate'] ?? '',
                        'design_id' => $product['custitem_design_code'] ?? '',
                        'vendors' => isset($product['vendorname']) && $product['vendorname'] ? [
                            [
                                'name' => $product['vendorname'],
                            ]
                        ] : [],
                        'variants' => [],
                        'categories' => isset($product['commercecategoryid']) ? [
                            [
                                'id' => $product['commercecategoryid'],
                            ],
                        ] : [],
                        'data' => $product,
                    ];
                }
                continue;
            }
            if (($product['itemtype'] === 'Assembly') && isset($product['custitem_web_store_design_item']) && !isset($productsArray[$product['custitem_web_store_design_item']])) {
                $productsArray[$product['custitem_web_store_design_item']] = [
                    'id' => $product['custitem_web_store_design_item'],
                    'sku' => '',
                    'created_at' => '',
                    'design_id' => $product['custitem_design_code'] ?? '',
                    'vendors' => isset($product['vendorname']) && $product['vendorname'] ? [
                        [
                            'name' => $product['vendorname'],
                        ]
                    ] : [],
                    'variants' => [],
                    'categories' => isset($product['commercecategoryid']) ? [
                        [
                            'id' => $product['commercecategoryid'],
                            'data' => [],
                        ],
                    ] : [],
                    'data' => [],
                ];
            }
            // Process Assembly Variants
            if ($product['itemtype'] === 'Assembly') { // Parent Assembly
                if (!isset($productsArray[$product['custitem_web_store_design_item']])) {
                    continue;
                }
                $productsArray[$product['custitem_web_store_design_item']]['variants'][] = $product;
            }
        }

        $fixedProductsArray = [];
        foreach($productsArray as $key => $product) {
            // Remove duplicates
            // Apply array_map() to each sub-array to convert it to a string representation
            $stringMatrix = array_map(function($variant) {
                return json_encode($variant);
            }, $product['variants']);
            // Remove duplicates based on the string representation
            $uniqueStringMatrix = array_unique($stringMatrix);
            // Convert back to the original multidimensional array
            $product['variants'] = array_map(function($variant) {
                return json_decode($variant, true);
            }, $uniqueStringMatrix);
            $fixedProductsArray[$key] = $product;
        }

        return new ArrayCollection(array_map(function($product) {
            return (object) [
                'platformId' => $product['id'],
                'sku' => $product['sku'] ?? '',
                'platformCreatedAt' => Carbon::parse($product['created_at']),
                'channel' => Channels::netsuite->value,
                'data' => $product['data'],
                'vendor' => self::vendors($product['vendors'])[0] ?? [],
                'variants' => self::productVariants($product['variants']),
                'categories' => self::productCategories($product['categories']),
            ];
        }, $fixedProductsArray));
    }

    public static function productVariants(array $productVariants): ArrayCollection
    {
        return new ArrayCollection(array_map(function($productVariant) {
            return (object) [
                'platformId' => $productVariant['id'],
                'sku' => $productVariant['itemid'] ?? '',
                'platformCreatedAt' => isset($productVariant['createddate']) ? Carbon::parse($productVariant['createddate']) : null,
                'channel' => Channels::netsuite->value,
                'data' => count(array_keys($productVariant)) > 1 ? $productVariant : [],
            ];
        }, $productVariants));
    }

    public static function productCategories(array $productCategories): ArrayCollection
    {
        return new ArrayCollection(array_map(function($productCategory) {
            return (object) [
                'platformId' => $productCategory['id'],
                'platformCreatedAt' => isset($productCategory['created']) ? Carbon::parse($productCategory['created']) : null,
                'channel' => Channels::netsuite->value,
                'data' => count(array_keys($productCategory)) > 1 ? $productCategory : [],
                'isSmartCollection' => false,
            ];
        }, $productCategories));
    }

    public static function vendors(array $vendors): ArrayCollection
    {
        return new ArrayCollection(array_map(function($vendor) {
            return (object) [
                'platformId' => $vendor['id'] ?? '',
                'name' => $vendor['name'] ?? '',
                'platformCreatedAt' => isset($productCategory['created']) ? Carbon::parse($productCategory['created']) : null,
                'channel' => Channels::netsuite->value,
                'data' => count(array_keys($vendor)) > 1 ? $vendor : [],
            ];
        }, $vendors));
    }
}