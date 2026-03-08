<?php

declare(strict_types=1);

namespace Enums;

enum Channel: int
{
    case shopify = 1;
    case klaviyo = 2;
    case facebook = 3;
    case bigcommerce = 4;
    case netsuite = 5;
    case amazon = 6;
    case instagram = 7;
    case google_search_console = 8;
    case google_ads = 9;
    case google_analytics = 10;
    case bing_webmaster_tools = 11;
    case pinterest = 12;
    case linkedin = 13;
    case x = 14;

    public function getName(): string
    {
        return $this->name;
    }

    public function getCommonName(): string
    {
        return match($this) {
            self::shopify => 'Shopify',
            self::klaviyo => 'Klaviyo',
            self::facebook => 'Facebook',
            self::bigcommerce => 'BigCommerce',
            self::netsuite => 'Netsuite',
            self::amazon => 'Amazon',
            self::instagram => 'Instagram',
            self::google_search_console => 'GoogleSearchConsole',
            self::google_ads => 'GoogleAds',
            self::google_analytics => 'GoogleAnalytics',
            self::bing_webmaster_tools => 'BingWebmasterTools',
            self::pinterest => 'Pinterest',
            self::linkedin => 'LinkedIn',
            self::x => 'X',
        };
    }

    public static function tryFromName(string $name): ?self
    {
        return match (strtolower($name)) {
            'shopify' => self::shopify,
            'klaviyo' => self::klaviyo,
            'facebook', 'facebook-ads', 'fb-ads' => self::facebook,
            'bigcommerce' => self::bigcommerce,
            'netsuite' => self::netsuite,
            'amazon' => self::amazon,
            'instagram' => self::instagram,
            'google_search_console', 'gsc' => self::google_search_console,
            'google_ads', 'googleads' => self::google_ads,
            'google_analytics', 'ga' => self::google_analytics,
            'bing_webmaster_tools', 'bing' => self::bing_webmaster_tools,
            'pinterest' => self::pinterest,
            'linkedin' => self::linkedin,
            'x', 'twitter' => self::x,
            default => null,
        };
    }

    public function getCooldown(): int
    {
        return match($this) {
            self::facebook, self::instagram => 3600, // 1 hour
            default => 600, // 10 minutes
        };
    }
}
