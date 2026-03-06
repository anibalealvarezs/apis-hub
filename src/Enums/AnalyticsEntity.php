<?php

declare(strict_types=1);

namespace Enums;

enum AnalyticsEntity: string
{
    case metrics = 'metric';
    case orders = 'order';
    case customers = 'customer';
    case products = 'product';
    case discounts = 'discount';
    case price_rules = 'price_rule';
    case product_categories = 'product_category';
    case product_variants = 'product_variant';
    case vendors = 'vendor';
    case posts = 'post';
    case queries = 'query';
    case pages = 'page';
    case campaigns = 'campaign';
    case channeled_campaigns = 'channeled_campaign';
    case channeled_ad_groups = 'channeled_ad_group';
    case channeled_ads = 'channeled_ad';

    public function getRequestsClassName(): string
    {
        return match ($this) {
            self::metrics => '\Classes\Requests\MetricRequests',
            self::orders => '\Classes\Requests\OrderRequests',
            self::customers => '\Classes\Requests\CustomerRequests',
            self::products => '\Classes\Requests\ProductRequests',
            self::discounts => '\Classes\Requests\DiscountRequests',
            self::price_rules => '\Classes\Requests\PriceRuleRequests',
            self::product_categories => '\Classes\Requests\ProductCategoryRequests',
            self::product_variants => '\Classes\Requests\ProductVariantRequests',
            self::vendors => '\Classes\Requests\VendorRequests',
            self::posts => '\Classes\Requests\PostRequests',
            self::queries => '\Classes\Requests\QueryRequests',
            self::pages => '\Classes\Requests\PageRequests',
            self::campaigns => '\Classes\Requests\CampaignRequests',
            self::channeled_campaigns => '\Classes\Requests\ChanneledCampaignRequests',
            self::channeled_ad_groups => '\Classes\Requests\ChanneledAdGroupRequests',
            self::channeled_ads => '\Classes\Requests\ChanneledAdRequests',
        };
    }
}
