<?php

namespace Classes\Conversions;

use Carbon\Carbon;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channels;
use Helpers\Helpers;

class NetSuiteConvert
{
    public static function customers(array $customers): ArrayCollection
    {
        return new ArrayCollection(array_map(function($customer) {
            return (object) [
                'platformId' => $customer['entityid'],
                'platformCreatedAt' => isset($customer['datecreated']) && $customer['datecreated'] ? Carbon::parse($customer['datecreated']) : null,
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
        $transactionLineKeys = [
            strtolower('ItemID'),
            strtolower('ItemWebStoreDesignItem'),
            strtolower('ItemParent'),
            strtolower('ItemSku'),
            strtolower('TransactionLineActualShipDate'),
            strtolower('TransactionLineCloseDate'),
            strtolower('TransactionLineCostEstimate'),
            strtolower('TransactionLineCostEstimateRate'),
            strtolower('TransactionLineCostEstimateType'),
            strtolower('TransactionLineCreditForeignAmount'),
            strtolower('TransactionLineDesignCode'),
            strtolower('TransactionLineDesignMarket'),
            strtolower('TransactionLinePromoCode'),
            strtolower('TransactionLineEstGrossProfit'),
            strtolower('TransactionLineEstGrossProfitPercent'),
            strtolower('TransactionLineExpenseAccount'),
            strtolower('TransactionLineExpectedShipDate'),
            strtolower('TransactionLineForeignAmount'),
            strtolower('TransactionLineID'),
            strtolower('TransactionLineIsClosed'),
            strtolower('TransactionLineIsFullyShipped'),
            strtolower('TransactionLineItemType'),
            strtolower('TransactionLineLastModifiedDate'),
            strtolower('TransactionLineSequenceNumber'),
            strtolower('TransactionLineMemo'),
            strtolower('TransactionLineNetAmount'),
            strtolower('TransactionLinePrice'),
            strtolower('TransactionLineQuantity'),
            strtolower('TransactionLineQuantityBackordered'),
            strtolower('TransactionLineQuantityBilled'),
            strtolower('TransactionLineQuantityPacked'),
            strtolower('TransactionLineQuantityPicked'),
            strtolower('TransactionLineQuantityRejected'),
            strtolower('TransactionLineQuantityShipRecv'),
            strtolower('TransactionLineRate'),
            strtolower('TransactionLineRateAmount'),
            strtolower('TransactionLineUniqueKey'),
        ];

        $ordersArray = [];
        foreach ($orders as $order) {
            // Identify Orders
            if (!isset($ordersArray[$order['id']])){
                $orderWithoutTransactionLine = array_diff_key($order, array_flip($transactionLineKeys));
                $ordersArray[$order['id']] = [
                    'id' => $orderWithoutTransactionLine['id'],
                    'created_at' => $orderWithoutTransactionLine['createddate'] ?? '',
                    'data' => [
                        ...$orderWithoutTransactionLine,
                        ...[
                            'line_items' => [],
                            'taxTotal' => 0,
                            'shippingHandlingTotal' => 0,
                            'discountTotal' => 0,
                            'subtotalBeforeDiscounts' => 0,
                            'subtotalAfterDiscounts' => 0,
                        ]
                    ],
                    'line_items' => [],
                    'discounts' => $order[strtolower('PromotionCodeName')] ? [$order[strtolower('PromotionCodeName')]] : [],
                ];
            }
            $transactionLine = array_intersect_key($order, array_flip($transactionLineKeys));
            switch($transactionLine[strtolower('TransactionLineItemType')]) {
                case 'TaxItem':
                    $ordersArray[$order['id']]['data']['taxTotal'] -= $transactionLine[strtolower('TransactionLineForeignAmount')];
                    break;
                case 'ShipItem':
                    $ordersArray[$order['id']]['data']['shippingHandlingTotal'] -= $transactionLine[strtolower('TransactionLineForeignAmount')];
                    break;
                case 'Discount':
                    $ordersArray[$order['id']]['data']['discountTotal'] += $transactionLine[strtolower('TransactionLineForeignAmount')];
                    break;
                case 'Assembly':
                case 'NonInvtPart':
                    $ordersArray[$order['id']]['data']['line_items'][] = $transactionLine;
                    $productArray = [
                        'id' => $transactionLine[strtolower('ItemID')],
                        'itemid' => $transactionLine[strtolower('ItemSku')] ?? '',
                        'createddate' => '',
                        'itemtype' => $transactionLine[strtolower('TransactionLineItemType')],
                    ];
                    if (isset($transactionLine[strtolower('ItemWebStoreDesignItem')])) {
                        $productArray['custitem_web_store_design_item'] = $transactionLine[strtolower('ItemWebStoreDesignItem')];
                    }
                    if (isset($transactionLine[strtolower('ItemParent')])) {
                        $productArray['parent'] = $transactionLine[strtolower('ItemParent')];
                    }
                    if ($products = self::products([$productArray])->toArray()) {
                        $product = $products[array_keys($products)[0]];
                        $variants = $product->variants->toArray();
                        $ordersArray[$order['id']]['line_items'][] = [
                            'product_id' => $products[array_keys($products)[0]]->platformId,
                            'variant_id' => count($variants) > 0 ? $variants[array_keys($variants)[0]]->platformId : null,
                        ];
                    }
                    break;
                default:
                    break;
            }
            $ordersArray[$order['id']]['data']['subtotalAfterDiscounts'] = $ordersArray[$order['id']]['data']['foreigntotal'] - $ordersArray[$order['id']]['data']['taxTotal'] - $ordersArray[$order['id']]['data']['shippingHandlingTotal'];
            $ordersArray[$order['id']]['data']['subtotalBeforeDiscounts'] = $ordersArray[$order['id']]['data']['subtotalAfterDiscounts'] + $ordersArray[$order['id']]['data']['discountTotal'];
        }

        return new ArrayCollection(array_map(function($order) {
            return (object) [
                'platformId' => $order['id'],
                'platformCreatedAt' => isset($order['created_at']) && $order['created_at'] ? Carbon::parse($order['created_at']) : null,
                'channel' => Channels::netsuite->value,
                'data' => $order['data'],
                'customer' => (object) [
                    'id' => $order['data']['entity'] ?? '',
                    'email' => $order['data'][strtolower('CustomerEmail')] ?? '',
                ],
                'discountCodes' => $order['discounts'],
                'lineItems' => $order['line_items'] ?? '',
            ];
        }, $ordersArray));
    }

    public static function products(array $products): ArrayCollection
    {
        $productsArray = [];
        foreach ($products as $product) {
            // Ignore Assembly intermediate parents => !isset($product['parent'])
            // Ignore Assembly children if parent is not identified => !isset($product['custitem_web_store_design_item'])
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
            // Create dummy parent if not exists
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
            $product['variants'] = Helpers::multiDimensionalArrayUnique($product['variants']);
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