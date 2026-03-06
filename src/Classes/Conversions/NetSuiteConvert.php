<?php

namespace Classes\Conversions;

use Carbon\Carbon;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use Helpers\Helpers;

class NetSuiteConvert
{
    public static function customers(array $customers): ArrayCollection
    {
        $addressLineKeys = [
            strtolower('AddressAddr1'),
            strtolower('AddressAddr2'),
            strtolower('AddressCity'),
            strtolower('AddressCountry'),
            strtolower('AddressState'),
            strtolower('AddressDropdownState'),
            strtolower('AddressZip'),
            strtolower('AddressAddressee'),
            strtolower('AddressPhone'),
            strtolower('AddressText'),
            strtolower('AddressAttention'),
            strtolower('AddressOverride'),
            strtolower('AddressRecordOwner'),
        ];

        $customersArray = [];
        foreach ($customers as $customer) {
            $entityId = $customer['entityid'] ?? null;
            if (!$entityId) {
                continue;
            }
            // Identify Customers
            if (!isset($customersArray[$entityId])) {
                $customerWithoutAddress = array_diff_key($customer, array_flip($addressLineKeys));
                $customerWithoutAddress['addresses'] = [];
                $customersArray[$entityId] = [
                    'entityid' => $entityId,
                    'email' => $customerWithoutAddress['email'] ?? '',
                    'datecreated' => $customerWithoutAddress['datecreated'] ?? '',
                    'data' => $customerWithoutAddress,
                ];
            }
            $address = array_intersect_key($customer, array_flip($addressLineKeys));
            $customersArray[$entityId]['data']['addresses'][] = $address;
        }

        return new ArrayCollection(array_map(function ($customer) {
            return (object) [
                'platformId' => $customer['entityid'] ?? null,
                'platformCreatedAt' => !empty($customer['datecreated']) ? Carbon::parse($customer['datecreated']) : null,
                'channel' => Channel::netsuite->value,
                'email' => $customer['email'] ?? '',
                'data' => $customer['data'] ?? [],
            ];
        }, $customersArray));
    }

    /**
     * @param array $discounts
     * @return ArrayCollection
     */
    public static function discounts(array $discounts): ArrayCollection
    {
        return new ArrayCollection(array_map(function ($discount) {
            return (object) [
                'platformId' => $discount['id'] ?? null,
                'platformCreatedAt' => !empty($discount['created_at']) ? Carbon::parse($discount['created_at']) : Carbon::now(),
                'channel' => Channel::netsuite->value,
                'code' => $discount['code'] ?? '',
                'data' => $discount,
            ];
        }, $discounts));
    }

    public static function priceRules(array $priceRules): ArrayCollection
    {
        return new ArrayCollection(array_map(function ($priceRule) {
            return (object) [
                'platformId' => $priceRule['id'] ?? null,
                'platformCreatedAt' => !empty($priceRule['created_at']) ? Carbon::parse($priceRule['created_at']) : Carbon::now(),
                'channel' => Channel::netsuite->value,
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
            $orderId = $order['id'] ?? null;
            if (!$orderId) {
                continue;
            }
            // Identify Orders
            if (!isset($ordersArray[$orderId])) {
                $orderWithoutTransactionLine = array_diff_key($order, array_flip($transactionLineKeys));
                $ordersArray[$orderId] = [
                    'id' => $orderId,
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
                    'discounts' => ($order[strtolower('PromotionCodeName')] ?? null) ? [$order[strtolower('PromotionCodeName')]] : [],
                ];
            }
            $transactionLine = array_intersect_key($order, array_flip($transactionLineKeys));
            $itemType = $transactionLine[strtolower('TransactionLineItemType')] ?? null;
            switch ($itemType) {
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
                    $ordersArray[$orderId]['data']['line_items'][] = $transactionLine;
                    $productArray = [
                        'id' => $transactionLine[strtolower('ItemID')] ?? null,
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
                        $ordersArray[$orderId]['line_items'][] = [
                            'product_id' => $products[array_keys($products)[0]]->platformId,
                            'variant_id' => count($variants) > 0 ? $variants[array_keys($variants)[0]]->platformId : null,
                        ];
                    }
                    break;
                default:
                    break;
            }
            $ordersArray[$orderId]['data']['subtotalAfterDiscounts'] = ($ordersArray[$orderId]['data']['foreigntotal'] ?? 0) - $ordersArray[$orderId]['data']['taxTotal'] - $ordersArray[$orderId]['data']['shippingHandlingTotal'];
            $ordersArray[$orderId]['data']['subtotalBeforeDiscounts'] = $ordersArray[$orderId]['data']['subtotalAfterDiscounts'] + $ordersArray[$orderId]['data']['discountTotal'];
        }

        return new ArrayCollection(array_map(function ($order) {
            return (object) [
                'platformId' => $order['id'] ?? null,
                'platformCreatedAt' => !empty($order['created_at']) ? Carbon::parse($order['created_at']) : null,
                'channel' => Channel::netsuite->value,
                'data' => $order['data'] ?? [],
                'customer' => (object) [
                    'id' => $order['data']['entity'] ?? '',
                    'email' => $order['data'][strtolower('CustomerEmail')] ?? '',
                ],
                'discountCodes' => $order['discounts'] ?? [],
                'lineItems' => $order['line_items'] ?? [],
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
                if (!isset($productsArray[$product['id']])) {
                    $productsArray[$product['id']] = [
                        'id' => $product['id'],
                        'sku' => $product['itemid'] ?? '',
                        'created_at' => $product['createddate'] ?? '',
                        'design_id' => $product['custitem_design_code'] ?? '',
                        'vendors' => isset($product['vendorname']) && $product['vendorname'] ? [
                            [
                                'name' => $product['vendorname'],
                                'data' => [],
                            ]
                        ] : [],
                        'variants' => [],
                        'categories' => isset($product['commercecategoryid']) ? [
                            [
                                'id' => $product['commercecategoryid'],
                                'data' => [],
                            ],
                        ] : [],
                        'data' => $product,
                    ];
                }
                continue;
            }
            $webStoreDesignItem = $product['custitem_web_store_design_item'] ?? null;
            // Create dummy parent if not exists
            if (($product['itemtype'] === 'Assembly') && $webStoreDesignItem && !isset($productsArray[$webStoreDesignItem])) {
                $productsArray[$webStoreDesignItem] = [
                    'id' => $webStoreDesignItem,
                    'sku' => '',
                    'created_at' => '',
                    'design_id' => $product['custitem_design_code'] ?? '',
                    'vendors' => isset($product['vendorname']) && $product['vendorname'] ? [
                        [
                            'name' => $product['vendorname'],
                            'data' => [],
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
                if (!isset($productsArray[$webStoreDesignItem])) {
                    continue;
                }
                $productsArray[$webStoreDesignItem]['variants'][] = $product;
            }
        }

        $fixedProductsArray = [];
        foreach ($productsArray as $key => $product) {
            // Remove duplicates
            $product['variants'] = Helpers::multiDimensionalArrayUnique($product['variants']);
            $fixedProductsArray[$key] = $product;
        }

        return new ArrayCollection(array_map(function ($product) {
            return (object) [
                'platformId' => $product['id'] ?? null,
                'sku' => $product['sku'] ?? '',
                'platformCreatedAt' => !empty($product['created_at']) ? Carbon::parse($product['created_at']) : Carbon::now(),
                'channel' => Channel::netsuite->value,
                'data' => $product['data'] ?? [],
                'vendor' => self::vendors($product['vendors'])[0] ?? [],
                'variants' => self::productVariants($product['variants'] ?? []),
                'categories' => self::productCategories($product['categories'] ?? []),
            ];
        }, $fixedProductsArray));
    }

    public static function productVariants(array $productVariants): ArrayCollection
    {
        return new ArrayCollection(array_map(function ($productVariant) {
            return (object) [
                'platformId' => $productVariant['id'] ?? null,
                'sku' => $productVariant['itemid'] ?? '',
                'platformCreatedAt' => isset($productVariant['createddate']) ? Carbon::parse($productVariant['createddate']) : null,
                'channel' => Channel::netsuite->value,
                'data' => $productVariant['data'] ?? [],
            ];
        }, $productVariants));
    }

    public static function productCategories(array $productCategories): ArrayCollection
    {
        return new ArrayCollection(array_map(function ($productCategory) {
            return (object) [
                'platformId' => $productCategory['id'] ?? null,
                'platformCreatedAt' => isset($productCategory['created']) ? Carbon::parse($productCategory['created']) : null,
                'channel' => Channel::netsuite->value,
                'data' => $productCategory['data'] ?? [],
                'isSmartCollection' => false,
            ];
        }, $productCategories));
    }

    public static function vendors(array $vendors): ArrayCollection
    {
        return new ArrayCollection(array_map(function ($vendor) {
            return (object) [
                'platformId' => $vendor['id'] ?? '',
                'name' => $vendor['name'] ?? '',
                'platformCreatedAt' => isset($vendor['created']) ? Carbon::parse($vendor['created']) : null,
                'channel' => Channel::netsuite->value,
                'data' => $vendor['data'],
            ];
        }, $vendors));
    }
}
