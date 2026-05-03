<?php

namespace Anibalealvarezs\ApiSkeleton\Enums;

enum Channel: string
{
    case shopify = 'shopify';
    case netsuite = 'netsuite';
    case klaviyo = 'klaviyo';
    case google_search_console = 'google_search_console';
    case facebook_organic = 'facebook_organic';
    case facebook_marketing = 'facebook_marketing';
    case x_organic = 'x_organic';
    case x_marketing = 'x_marketing';
    case tiktok_organic = 'tiktok_organic';
    case tiktok_marketing = 'tiktok_marketing';
    case pinterest_organic = 'pinterest_organic';
    case pinterest_marketing = 'pinterest_marketing';
    case linkedin_organic = 'linkedin_organic';
    case linkedin_marketing = 'linkedin_marketing';
    case amazon_ecommerce = 'amazon_ecommerce';
    case amazon_marketing = 'amazon_marketing';
}
