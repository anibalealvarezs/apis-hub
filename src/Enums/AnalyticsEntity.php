<?php

declare(strict_types=1);

namespace Enums;

enum AnalyticsEntity: string
{
    case metrics = 'metric';
    case channeled_metrics = 'channeled_metric';
    case orders = 'order';
    case channeled_orders = 'channeled_order';
    case customers = 'customer';
    case channeled_customers = 'channeled_customer';
    case products = 'product';
    case channeled_products = 'channeled_product';
    case discounts = 'discount';
    case channeled_discounts = 'channeled_discount';
    case price_rules = 'price_rule';
    case channeled_price_rules = 'channeled_price_rule';
    case product_categories = 'product_category';
    case channeled_product_categories = 'channeled_product_category';
    case product_variants = 'product_variant';
    case channeled_product_variants = 'channeled_product_variant';
    case vendors = 'vendor';
    case channeled_vendors = 'channeled_vendor';
    case posts = 'post';
    case channeled_posts = 'channeled_post';
    case queries = 'query';
    case channeled_queries = 'channeled_query';
    case pages = 'page';
    case channeled_pages = 'channeled_page';
    case campaigns = 'campaign';
    case channeled_campaigns = 'channeled_campaign';
    case channeled_ad_groups = 'channeled_ad_group';
    case channeled_ads = 'channeled_ad';
    case facebook_entities = 'facebook_entities';
    case facebook_marketing_entities = 'facebook_marketing_entities';
    case facebook_organic_entities = 'facebook_organic_entities';

    public function getRequestsClassName(): string
    {
        return match ($this) {
            self::metrics, self::channeled_metrics => '\Classes\Requests\MetricRequests',
            self::orders, self::channeled_orders => '\Classes\Requests\OrderRequests',
            self::customers, self::channeled_customers => '\Classes\Requests\CustomerRequests',
            self::products, self::channeled_products => '\Classes\Requests\ProductRequests',
            self::discounts, self::channeled_discounts => '\Classes\Requests\DiscountRequests',
            self::price_rules, self::channeled_price_rules => '\Classes\Requests\PriceRuleRequests',
            self::product_categories, self::channeled_product_categories => '\Classes\Requests\ProductCategoryRequests',
            self::product_variants, self::channeled_product_variants => '\Classes\Requests\ProductVariantRequests',
            self::vendors, self::channeled_vendors => '\Classes\Requests\VendorRequests',
            self::posts, self::channeled_posts => '\Classes\Requests\PostRequests',
            self::queries, self::channeled_queries => '\Classes\Requests\QueryRequests',
            self::pages, self::channeled_pages => '\Classes\Requests\PageRequests',
            self::campaigns, self::channeled_campaigns => '\Classes\Requests\CampaignRequests',
            self::channeled_ad_groups => '\Classes\Requests\AdGroupRequests',
            self::channeled_ads => '\Classes\Requests\AdRequests',
            self::facebook_entities, self::facebook_marketing_entities, self::facebook_organic_entities => '\Classes\Requests\FacebookEntityRequests',
        };
    }
}
