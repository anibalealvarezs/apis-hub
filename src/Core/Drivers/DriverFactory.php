<?php

namespace Core\Drivers;

use Interfaces\SyncDriverInterface;
use Channels\Google\SearchConsole\SearchConsoleDriver;
use Psr\Log\LoggerInterface;
use Exception;

class DriverFactory
{
    private static array $drivers = [];

    public static function get(string $channel, ?LoggerInterface $logger = null): SyncDriverInterface
    {
        if (isset(self::$drivers[$channel])) {
            return self::$drivers[$channel];
        }

        $driver = match ($channel) {
            'google_search_console' => new SearchConsoleDriver(
                new \Core\Auth\GoogleAuthProvider(),
                $logger
            ),
            'facebook_marketing' => new \Channels\Meta\Marketing\FacebookMarketingDriver(
                new \Core\Auth\FacebookAuthProvider(),
                $logger
            ),
            'facebook_organic' => new \Channels\Meta\Organic\FacebookOrganicDriver(
                new \Core\Auth\FacebookAuthProvider(),
                $logger
            ),
            'shopify' => new \Channels\Shopify\ShopifyDriver(
                new \Core\Auth\ShopifyAuthProvider(),
                $logger
            ),
            'klaviyo' => new \Channels\Klaviyo\KlaviyoDriver(
                new \Core\Auth\KlaviyoAuthProvider(),
                $logger
            ),
            'netsuite' => new \Channels\NetSuite\NetSuiteDriver(
                new \Core\Auth\NetSuiteAuthProvider(),
                $logger
            ),
            'amazon' => new \Channels\Amazon\AmazonDriver(
                new \Core\Auth\AmazonAuthProvider(),
                $logger
            ),
            'bigcommerce' => new \Channels\BigCommerce\BigCommerceDriver(
                new \Core\Auth\BigCommerceAuthProvider(),
                $logger
            ),
            'pinterest' => new \Channels\Pinterest\PinterestDriver(
                new \Core\Auth\PinterestAuthProvider(),
                $logger
            ),
            'linkedin' => new \Channels\LinkedIn\LinkedInDriver(
                new \Core\Auth\LinkedInAuthProvider(),
                $logger
            ),
            'x' => new \Channels\X\XDriver(
                new \Core\Auth\XAuthProvider(),
                $logger
            ),
            'tiktok' => new \Channels\TikTok\TikTokDriver(
                new \Core\Auth\TikTokAuthProvider(),
                $logger
            ),
            'google_analytics' => new \Channels\Google\Analytics\GoogleAnalyticsDriver(
                new \Core\Auth\GoogleAuthProvider(),
                $logger
            ),
            default => throw new Exception("Driver not found for channel: $channel")
        };

        self::$drivers[$channel] = $driver;
        return $driver;
    }
}
