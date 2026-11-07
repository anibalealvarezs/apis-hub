<?php

declare(strict_types=1);

namespace Services\Aggregation;

use InvalidArgumentException;

/**
 * Registry defining measurable fields, reducers, supported dimensions,
 * currency metadata, and scope paths for unaggregated entity tables.
 */
final class EntityMeasureCatalog
{
    /**
     * Aliases mapping plural/hyphenated Facade entity slugs to canonical catalog keys.
     */
    private const ENTITY_ALIASES = [
        'promotions'  => 'discounts',
        'promo-codes' => 'discounts',
        'promo_codes' => 'discounts',
        'ad-sets'     => 'ad-groups',
        'ad_sets'     => 'ad-groups',
        'ad_groups'   => 'ad-groups',
    ];

    /**
     * Monetary measures subject to the strict multi-currency safety guard.
     */
    private const MONETARY_MEASURES = [
        'revenue',
        'subtotal',
        'total_tax',
        'total_discount',
        'aov',
        'average_price',
        'min_price',
        'max_price',
        'product_revenue',
        'customer_ltv',
        'avg_revenue_per_customer',
        'store_revenue',
        'store_aov',
        'total_budget',
        'average_budget',
        'discounted_revenue',
        'total_discount_amount',
    ];

    /**
     * Map of supported entities, their tables, ownership resolution path,
     * measurable columns/attributes, and allowed groupings.
     */
    private const ENTITY_DEFINITIONS = [
        'orders' => [
            'label' => 'Orders',
            'table' => 'channeled_orders',
            'alias' => 'o',
            'has_channel_column' => true,
            'date_column' => 'o.platform_created_at',
            'currency_json_fields' => ['currency_code', 'currency'],
            'scope_join' => [
                'table' => 'channeled_stores',
                'alias' => 'store',
                'condition' => 'o.channeled_store_id = store.id',
                'account_column' => 'COALESCE(o.channeled_account_id, store.channeled_account_id)',
            ],
            'measures' => [
                'order_count' => [
                    'label' => 'Order Count',
                    'reducer' => 'count',
                    'expression' => 'o.id',
                    'distinct' => true,
                    'type' => 'integer',
                ],
                'canonical_order_count' => [
                    'label' => 'Distinct Canonical Orders',
                    'reducer' => 'count',
                    'expression' => 'COALESCE(CAST(o.order_id AS CHAR), CAST(o.id AS CHAR))',
                    'distinct' => true,
                    'type' => 'integer',
                ],
                'unique_customers' => [
                    'label' => 'Unique Buying Customers',
                    'reducer' => 'count',
                    'expression' => 'o.channeled_customer_id',
                    'distinct' => true,
                    'type' => 'integer',
                ],
                'revenue' => [
                    'label' => 'Total Revenue',
                    'reducer' => 'sum',
                    'json_field' => 'total_amount',
                    'fallback_json_fields' => ['order_total'],
                    'type' => 'float',
                    'monetary' => true,
                ],
                'subtotal' => [
                    'label' => 'Subtotal',
                    'reducer' => 'sum',
                    'json_field' => 'subtotal_price',
                    'type' => 'float',
                    'monetary' => true,
                ],
                'total_tax' => [
                    'label' => 'Total Tax',
                    'reducer' => 'sum',
                    'json_field' => 'total_tax',
                    'type' => 'float',
                    'monetary' => true,
                ],
                'total_discount' => [
                    'label' => 'Total Discount',
                    'reducer' => 'sum',
                    'json_field' => 'total_discount',
                    'fallback_json_fields' => ['discount_amount'],
                    'type' => 'float',
                    'monetary' => true,
                ],
                'aov' => [
                    'label' => 'Average Order Value (AOV)',
                    'reducer' => 'ratio',
                    'numerator' => 'revenue',
                    'denominator' => 'order_count',
                    'type' => 'float',
                    'monetary' => true,
                ],
            ],
            'dimensions' => [
                'store' => [
                    'label' => 'Store',
                    'column' => 'o.channeled_store_id',
                    'name_expression' => 'store.name',
                ],
                'channel' => [
                    'label' => 'Channel',
                    'is_channel' => true,
                ],
                'account' => [
                    'label' => 'Owning Account',
                    'column' => 'COALESCE(o.channeled_account_id, store.channeled_account_id)',
                ],
                'product' => [
                    'label' => 'Product',
                    'join' => [
                        'table' => 'channeled_order_channeled_products',
                        'alias' => 'ord_prod',
                        'condition' => 'ord_prod.channeled_order_id = o.id',
                    ],
                    'column' => 'ord_prod.channeled_product_id',
                ],
                'discount_code' => [
                    'label' => 'Discount Code',
                    'join' => [
                        'table' => 'channeled_order_channeled_discounts',
                        'alias' => 'ord_disc',
                        'condition' => 'ord_disc.channeled_order_id = o.id',
                        'extra_join' => 'INNER JOIN channeled_discounts disc_ref ON disc_ref.id = ord_disc.channeled_discount_id',
                    ],
                    'column' => 'disc_ref.code',
                ],
                'financial_status' => [
                    'label' => 'Financial Status',
                    'json_field' => 'financial_status',
                ],
                'fulfillment_status' => [
                    'label' => 'Fulfillment Status',
                    'json_field' => 'fulfillment_status',
                ],
                'currency' => [
                    'label' => 'Currency',
                    'json_field' => 'currency_code',
                    'fallback_json_fields' => ['currency'],
                ],
                'shipping_country' => [
                    'label' => 'Shipping Country',
                    'json_field' => 'shipping_country',
                ],
            ],
        ],
        'customers' => [
            'label' => 'Customers',
            'table' => 'channeled_customers',
            'alias' => 'c',
            'has_channel_column' => true,
            'date_column' => 'c.platform_created_at',
            'scope_join' => [
                'table' => 'channeled_stores',
                'alias' => 'cust_store',
                'condition' => 'c.channeled_store_id = cust_store.id',
                'account_column' => 'COALESCE(c.channeled_account_id, cust_store.channeled_account_id)',
            ],
            'measures' => [
                'customer_count' => [
                    'label' => 'Source Profile Count',
                    'reducer' => 'count',
                    'expression' => 'c.id',
                    'distinct' => true,
                    'type' => 'integer',
                ],
                'canonical_customer_count' => [
                    'label' => 'Distinct Canonical Customers',
                    'reducer' => 'count',
                    'expression' => 'COALESCE(c.customer_id, c.id)',
                    'distinct' => true,
                    'type' => 'integer',
                ],
                'customer_ltv' => [
                    'label' => 'Cross-Store Customer Lifetime Value (LTV)',
                    'reducer' => 'custom_customer_commerce',
                    'type' => 'float',
                    'monetary' => true,
                ],
                'avg_revenue_per_customer' => [
                    'label' => 'Average Revenue per Customer',
                    'reducer' => 'custom_customer_commerce',
                    'type' => 'float',
                    'monetary' => true,
                ],
                'repeat_customer_count' => [
                    'label' => 'Repeat Buyers (>1 Order)',
                    'reducer' => 'custom_customer_commerce',
                    'type' => 'integer',
                ],
                'high_frequency_customer_count' => [
                    'label' => 'High-Frequency Buyers (>=3 Orders)',
                    'reducer' => 'custom_customer_commerce',
                    'type' => 'integer',
                ],
                'repeat_buyer_rate' => [
                    'label' => 'Repeat Buyer Rate (%)',
                    'reducer' => 'custom_customer_commerce',
                    'type' => 'float',
                ],
                'repeat_frequency' => [
                    'label' => 'Customer Purchase Frequency Distribution',
                    'reducer' => 'custom',
                    'type' => 'distribution',
                ],
                'pre_purchase_touches' => [
                    'label' => 'Pre-Purchase Touchpoints Attribution',
                    'reducer' => 'custom',
                    'type' => 'attribution',
                ],
            ],
            'dimensions' => [
                'store' => [
                    'label' => 'Store',
                    'column' => 'c.channeled_store_id',
                    'name_expression' => 'cust_store.name',
                ],
                'channel' => [
                    'label' => 'Channel',
                    'is_channel' => true,
                ],
                'status' => [
                    'label' => 'Subscription / Lead Status',
                    'json_field' => 'status',
                ],
                'account' => [
                    'label' => 'Owning Account',
                    'column' => 'COALESCE(c.channeled_account_id, cust_store.channeled_account_id)',
                ],
            ],
        ],
        'stores' => [
            'label' => 'Stores',
            'table' => 'channeled_stores',
            'alias' => 's',
            'has_channel_column' => true,
            'date_column' => 's.platform_created_at',
            'account_column' => 's.channeled_account_id',
            'currency_json_fields' => ['currency_code', 'currency'],
            'measures' => [
                'store_count' => [
                    'label' => 'Connected Stores Count',
                    'reducer' => 'count',
                    'expression' => 's.id',
                    'distinct' => true,
                    'type' => 'integer',
                ],
            ],
            'dimensions' => [
                'channel' => [
                    'label' => 'Channel',
                    'is_channel' => true,
                ],
                'currency' => [
                    'label' => 'Store Currency',
                    'json_field' => 'currency_code',
                    'fallback_json_fields' => ['currency'],
                ],
                'account' => [
                    'label' => 'Owning Account',
                    'column' => 's.channeled_account_id',
                ],
            ],
        ],
        'products' => [
            'label' => 'Products',
            'table' => 'channeled_products',
            'alias' => 'p',
            'has_channel_column' => true,
            'date_column' => 'p.platform_created_at',
            'currency_json_fields' => ['currency_code', 'currency'],
            'scope_join' => [
                'table' => 'channeled_stores',
                'alias' => 'prod_store',
                'condition' => 'p.channeled_store_id = prod_store.id',
                'account_column' => 'COALESCE(p.channeled_account_id, prod_store.channeled_account_id)',
            ],
            'measures' => [
                'product_count' => [
                    'label' => 'Product Count',
                    'reducer' => 'count',
                    'expression' => 'p.id',
                    'distinct' => true,
                    'type' => 'integer',
                ],
                'canonical_product_count' => [
                    'label' => 'Distinct Canonical Products',
                    'reducer' => 'count',
                    'expression' => 'COALESCE(p.product_id, p.id)',
                    'distinct' => true,
                    'type' => 'integer',
                ],
                'average_price' => [
                    'label' => 'Average Price',
                    'reducer' => 'avg',
                    'json_field' => 'price',
                    'type' => 'float',
                    'monetary' => true,
                ],
                'min_price' => [
                    'label' => 'Minimum Price',
                    'reducer' => 'min',
                    'json_field' => 'price',
                    'type' => 'float',
                    'monetary' => true,
                ],
                'max_price' => [
                    'label' => 'Maximum Price',
                    'reducer' => 'max',
                    'json_field' => 'price',
                    'type' => 'float',
                    'monetary' => true,
                ],
            ],
            'dimensions' => [
                'store' => [
                    'label' => 'Store',
                    'column' => 'p.channeled_store_id',
                    'name_expression' => 'prod_store.name',
                ],
                'channel' => [
                    'label' => 'Channel',
                    'is_channel' => true,
                ],
                'vendor' => [
                    'label' => 'Vendor',
                    'column' => 'p.vendor',
                ],
                'product_type' => [
                    'label' => 'Product Type',
                    'column' => 'p.product_type',
                ],
                'sku' => [
                    'label' => 'SKU',
                    'column' => 'p.sku',
                ],
                'currency' => [
                    'label' => 'Currency',
                    'json_field' => 'currency_code',
                    'fallback_json_fields' => ['currency'],
                ],
            ],
        ],
        'discounts' => [
            'label' => 'Discounts & Promo Codes',
            'table' => 'channeled_discounts',
            'alias' => 'd',
            'has_channel_column' => true,
            'date_column' => 'd.platform_created_at',
            'scope_join' => [
                'table' => 'channeled_stores',
                'alias' => 'disc_store',
                'condition' => 'd.channeled_store_id = disc_store.id',
                'account_column' => 'COALESCE(d.channeled_account_id, disc_store.channeled_account_id)',
            ],
            'measures' => [
                'discount_count' => [
                    'label' => 'Discount Codes Count',
                    'reducer' => 'count',
                    'expression' => 'd.id',
                    'distinct' => true,
                    'type' => 'integer',
                ],
                'total_usage' => [
                    'label' => 'Total Usage Count',
                    'reducer' => 'sum',
                    'json_field' => 'usage_count',
                    'type' => 'integer',
                ],
            ],
            'dimensions' => [
                'store' => [
                    'label' => 'Store',
                    'column' => 'd.channeled_store_id',
                    'name_expression' => 'disc_store.name',
                ],
                'channel' => [
                    'label' => 'Channel',
                    'is_channel' => true,
                ],
                'code' => [
                    'label' => 'Promo Code',
                    'column' => 'd.code',
                ],
                'status' => [
                    'label' => 'Status',
                    'json_field' => 'status',
                ],
            ],
        ],
        'campaigns' => [
            'label' => 'Campaigns',
            'table' => 'channeled_campaigns',
            'alias' => 'camp',
            'has_channel_column' => true,
            'date_column' => 'camp.platform_created_at',
            'account_column' => 'camp.channeled_account_id',
            'currency_json_fields' => ['currency', 'account_currency'],
            'measures' => [
                'campaign_count' => [
                    'label' => 'Campaign Count',
                    'reducer' => 'count',
                    'expression' => 'camp.id',
                    'distinct' => true,
                    'type' => 'integer',
                ],
                'total_budget' => [
                    'label' => 'Total Budget',
                    'reducer' => 'sum',
                    'expression' => 'camp.budget',
                    'type' => 'float',
                    'monetary' => true,
                ],
                'average_budget' => [
                    'label' => 'Average Budget',
                    'reducer' => 'avg',
                    'expression' => 'camp.budget',
                    'type' => 'float',
                    'monetary' => true,
                ],
            ],
            'dimensions' => [
                'status' => [
                    'label' => 'Status',
                    'column' => 'camp.status',
                ],
                'objective' => [
                    'label' => 'Objective',
                    'column' => 'camp.objective',
                ],
                'buying_type' => [
                    'label' => 'Buying Type',
                    'column' => 'camp.buying_type',
                ],
                'channel' => [
                    'label' => 'Channel',
                    'is_channel' => true,
                ],
                'account' => [
                    'label' => 'Owning Account',
                    'column' => 'camp.channeled_account_id',
                ],
            ],
        ],
        'ad-groups' => [
            'label' => 'Ad Sets & Ad Groups',
            'table' => 'channeled_ad_groups',
            'alias' => 'ag',
            'has_channel_column' => true,
            'date_column' => 'ag.platform_created_at',
            'account_column' => 'ag.channeled_account_id',
            'measures' => [
                'ad_group_count' => [
                    'label' => 'Ad Sets / Groups Count',
                    'reducer' => 'count',
                    'expression' => 'ag.id',
                    'distinct' => true,
                    'type' => 'integer',
                ],
            ],
            'dimensions' => [
                'status' => [
                    'label' => 'Status',
                    'column' => 'ag.status',
                ],
                'campaign' => [
                    'label' => 'Campaign',
                    'column' => 'ag.channeled_campaign_id',
                ],
                'optimization_goal' => [
                    'label' => 'Optimization Goal',
                    'column' => 'ag.optimization_goal',
                ],
                'billing_event' => [
                    'label' => 'Billing Event',
                    'column' => 'ag.billing_event',
                ],
                'channel' => [
                    'label' => 'Channel',
                    'is_channel' => true,
                ],
                'account' => [
                    'label' => 'Owning Account',
                    'column' => 'ag.channeled_account_id',
                ],
            ],
        ],
        'ads' => [
            'label' => 'Ads',
            'table' => 'channeled_ads',
            'alias' => 'ad',
            'has_channel_column' => true,
            'date_column' => 'ad.platform_created_at',
            'account_column' => 'ad.channeled_account_id',
            'measures' => [
                'ad_count' => [
                    'label' => 'Ad Count',
                    'reducer' => 'count',
                    'expression' => 'ad.id',
                    'distinct' => true,
                    'type' => 'integer',
                ],
            ],
            'dimensions' => [
                'status' => [
                    'label' => 'Status',
                    'column' => 'ad.status',
                ],
                'campaign' => [
                    'label' => 'Campaign',
                    'column' => 'ad.channeled_campaign_id',
                ],
                'ad_group' => [
                    'label' => 'Ad Set / Group',
                    'column' => 'ad.channeled_ad_group_id',
                ],
                'channel' => [
                    'label' => 'Channel',
                    'is_channel' => true,
                ],
                'account' => [
                    'label' => 'Owning Account',
                    'column' => 'ad.channeled_account_id',
                ],
            ],
        ],
        'posts' => [
            'label' => 'Posts (Organic)',
            'table' => 'posts',
            'alias' => 'pst',
            'has_channel_column' => false,
            'date_column' => 'pst.created_at',
            'account_column' => 'pst.channeled_account_id',
            'measures' => [
                'post_count' => [
                    'label' => 'Published Posts Count',
                    'reducer' => 'count',
                    'expression' => 'pst.id',
                    'distinct' => true,
                    'type' => 'integer',
                ],
            ],
            'dimensions' => [
                'page' => [
                    'label' => 'Page',
                    'column' => 'pst.page_id',
                ],
                'media_type' => [
                    'label' => 'Media Type',
                    'json_field' => 'media_type',
                    'fallback_json_fields' => ['type'],
                ],
                'account' => [
                    'label' => 'Owning Account',
                    'column' => 'pst.channeled_account_id',
                ],
            ],
        ],
        'events' => [
            'label' => 'Events',
            'table' => 'channeled_events',
            'alias' => 'ev',
            'has_channel_column' => true,
            'date_column' => 'ev.platform_created_at',
            'account_column' => 'ev.channeled_account_id',
            'measures' => [
                'event_occurrence_count' => [
                    'label' => 'Event Occurrences Count',
                    'reducer' => 'count',
                    'expression' => 'ev.id',
                    'distinct' => true,
                    'type' => 'integer',
                ],
                'distinct_event_names' => [
                    'label' => 'Distinct Event Names',
                    'reducer' => 'count',
                    'expression' => 'ev.event_id',
                    'distinct' => true,
                    'type' => 'integer',
                ],
            ],
            'dimensions' => [
                'channel' => [
                    'label' => 'Channel',
                    'is_channel' => true,
                ],
                'action' => [
                    'label' => 'Event Action',
                    'json_field' => 'action',
                ],
                'account' => [
                    'label' => 'Owning Account',
                    'column' => 'ev.channeled_account_id',
                ],
            ],
        ],
    ];

    /**
     * Normalize an entity type slug using aliases.
     */
    public function normalizeEntityType(string $entityType): string
    {
        $clean = strtolower(trim($entityType));
        return self::ENTITY_ALIASES[$clean] ?? $clean;
    }

    /**
     * Check whether a measure is monetary and requires currency safety validation.
     */
    public function isMonetaryMeasure(string $measure): bool
    {
        return in_array($measure, self::MONETARY_MEASURES, true);
    }

    /**
     * Get all supported entity definitions.
     */
    public function getDefinitions(): array
    {
        return self::ENTITY_DEFINITIONS;
    }

    /**
     * Get definition for a specific entity type.
     */
    public function getDefinition(string $entityType): array
    {
        $normalized = $this->normalizeEntityType($entityType);
        if (!isset(self::ENTITY_DEFINITIONS[$normalized])) {
            throw new InvalidArgumentException("Unsupported entity type: '{$entityType}'. Supported: " . implode(', ', array_keys(self::ENTITY_DEFINITIONS)));
        }

        return self::ENTITY_DEFINITIONS[$normalized];
    }

    /**
     * Check if an entity type is supported.
     */
    public function supports(string $entityType): bool
    {
        $normalized = $this->normalizeEntityType($entityType);
        return isset(self::ENTITY_DEFINITIONS[$normalized]);
    }

    /**
     * Get a specific measure definition for an entity.
     */
    public function getMeasure(string $entityType, string $measure): array
    {
        $def = $this->getDefinition($entityType);
        if (!isset($def['measures'][$measure])) {
            throw new InvalidArgumentException("Unsupported measure '{$measure}' for entity '{$entityType}'. Supported: " . implode(', ', array_keys($def['measures'])));
        }

        return $def['measures'][$measure];
    }

    /**
     * Get a specific dimension definition for an entity.
     */
    public function getDimension(string $entityType, string $dimension): array
    {
        $def = $this->getDefinition($entityType);
        if (!isset($def['dimensions'][$dimension])) {
            throw new InvalidArgumentException("Unsupported dimension '{$dimension}' for entity '{$entityType}'. Supported: " . implode(', ', array_keys($def['dimensions'])));
        }

        return $def['dimensions'][$dimension];
    }

    /**
     * Format capabilities payload for API consumers (Facade / Dashboard Builder).
     */
    public function getCapabilities(): array
    {
        $capabilities = [];
        foreach (self::ENTITY_DEFINITIONS as $entityKey => $def) {
            $measures = [];
            foreach ($def['measures'] as $mKey => $mDef) {
                $measures[$mKey] = [
                    'label'    => $mDef['label'],
                    'type'     => $mDef['type'],
                    'reducer'  => $mDef['reducer'],
                    'monetary' => !empty($mDef['monetary']),
                ];
            }

            $dimensions = [];
            foreach ($def['dimensions'] as $dKey => $dDef) {
                $dimensions[$dKey] = [
                    'label' => $dDef['label'],
                ];
            }

            $capabilities[$entityKey] = [
                'label' => $def['label'],
                'measures' => $measures,
                'dimensions' => $dimensions,
                'supports_time_buckets' => !empty($def['date_column']),
                'time_buckets' => !empty($def['date_column']) ? ['day', 'week', 'month', 'year'] : [],
            ];
        }

        return $capabilities;
    }
}
