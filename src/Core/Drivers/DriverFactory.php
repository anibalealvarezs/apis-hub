<?php

namespace Core\Drivers;

use Interfaces\SyncDriverInterface;
use Psr\Log\LoggerInterface;
use Exception;

class DriverFactory
{
    private static array $instances = [];
    
    /**
     * Mapeo de canales a sus respectivas clases de Driver y AuthProvider.
     * En una fase posterior, este mapa se poblará dinámicamente.
     */
    private static array $registry = [
        'google_search_console' => [
            'driver' => \Channels\Google\SearchConsole\SearchConsoleDriver::class,
            'auth' => \Core\Auth\GoogleAuthProvider::class,
        ],
        'google_analytics' => [
            'driver' => \Channels\Google\Analytics\GoogleAnalyticsDriver::class,
            'auth' => \Core\Auth\GoogleAuthProvider::class,
        ],
        'facebook_marketing' => [
            'driver' => \Channels\Meta\Marketing\FacebookMarketingDriver::class,
            'auth' => \Core\Auth\FacebookAuthProvider::class,
        ],
        'facebook_organic' => [
            'driver' => \Channels\Meta\Organic\FacebookOrganicDriver::class,
            'auth' => \Core\Auth\FacebookAuthProvider::class,
        ],
        'shopify' => [
            'driver' => \Channels\Shopify\ShopifyDriver::class,
            'auth' => \Core\Auth\ShopifyAuthProvider::class,
        ],
        'klaviyo' => [
            'driver' => \Channels\Klaviyo\KlaviyoDriver::class,
            'auth' => \Core\Auth\KlaviyoAuthProvider::class,
        ],
        'netsuite' => [
            'driver' => \Channels\NetSuite\NetSuiteDriver::class,
            'auth' => \Core\Auth\NetSuiteAuthProvider::class,
        ],
        'amazon' => [
            'driver' => \Channels\Amazon\AmazonDriver::class,
            'auth' => \Core\Auth\AmazonAuthProvider::class,
        ],
        'bigcommerce' => [
            'driver' => \Channels\BigCommerce\BigCommerceDriver::class,
            'auth' => \Core\Auth\BigCommerceAuthProvider::class,
        ],
        'pinterest' => [
            'driver' => \Channels\Pinterest\PinterestDriver::class,
            'auth' => \Core\Auth\PinterestAuthProvider::class,
        ],
        'linkedin' => [
            'driver' => \Channels\LinkedIn\LinkedInDriver::class,
            'auth' => \Core\Auth\LinkedInAuthProvider::class,
        ],
        'x' => [
            'driver' => \Channels\X\XDriver::class,
            'auth' => \Core\Auth\XAuthProvider::class,
        ],
        'tiktok' => [
            'driver' => \Channels\TikTok\TikTokDriver::class,
            'auth' => \Core\Auth\TikTokAuthProvider::class,
        ],
    ];

    /**
     * Obtiene una instancia del driver para el canal especificado.
     *
     * @param string $channel
     * @param LoggerInterface|null $logger
     * @return SyncDriverInterface
     * @throws Exception
     */
    public static function get(string $channel, ?LoggerInterface $logger = null): SyncDriverInterface
    {
        if (isset(self::$instances[$channel])) {
            return self::$instances[$channel];
        }

        if (!isset(self::$registry[$channel])) {
            throw new Exception("Driver not found for channel: $channel");
        }

        $config = self::$registry[$channel];
        $driverClass = $config['driver'];
        $authClass = $config['auth'];

        if (!class_exists($driverClass)) {
            throw new Exception("Driver class not found: $driverClass");
        }

        // Instanciación dinámica
        $authProvider = new $authClass();
        $driver = new $driverClass($authProvider, $logger);

        self::$instances[$channel] = $driver;
        return $driver;
    }

    /**
     * Registra manualmente un nuevo driver (útil para extensiones externas).
     *
     * @param string $channel
     * @param string $driverClass
     * @param string $authClass
     */
    public static function register(string $channel, string $driverClass, string $authClass): void
    {
        self::$registry[$channel] = [
            'driver' => $driverClass,
            'auth' => $authClass,
        ];
    }

    /**
     * Fuerza una instancia para un canal (útil para testing).
     *
     * @param string $channel
     * @param SyncDriverInterface $instance
     */
    public static function setInstance(string $channel, SyncDriverInterface $instance): void
    {
        self::$instances[$channel] = $instance;
    }
}
