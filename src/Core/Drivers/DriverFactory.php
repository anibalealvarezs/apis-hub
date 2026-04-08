<?php

namespace Core\Drivers;

use Anibalealvarezs\ApiSkeleton\Interfaces\SyncDriverInterface;
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
            'driver' => \Anibalealvarezs\GoogleHubDriver\Drivers\SearchConsoleDriver::class,
            'auth' => \Anibalealvarezs\GoogleHubDriver\Auth\GoogleAuthProvider::class,
            'processor' => [\Classes\Requests\MetricRequests::class, 'processGSCSite'],
        ],
        'google_analytics' => [
            'driver' => \Anibalealvarezs\GoogleHubDriver\Drivers\GoogleAnalyticsDriver::class,
            'auth' => \Anibalealvarezs\GoogleHubDriver\Auth\GoogleAuthProvider::class,
        ],
        'facebook_marketing' => [
            'driver' => \Anibalealvarezs\MetaHubDriver\Drivers\FacebookMarketingDriver::class,
            'auth' => \Anibalealvarezs\MetaHubDriver\Auth\FacebookAuthProvider::class,
            'processor' => [\Classes\Requests\MetricRequests::class, 'processFacebookMarketingChunk'],
        ],
        'facebook_organic' => [
            'driver' => \Anibalealvarezs\MetaHubDriver\Drivers\FacebookOrganicDriver::class,
            'auth' => \Anibalealvarezs\MetaHubDriver\Auth\FacebookAuthProvider::class,
            'processor' => [\Classes\Requests\MetricRequests::class, 'processFacebookOrganicChunk'],
        ],
        'shopify' => [
            'driver' => \Anibalealvarezs\ShopifyHubDriver\Drivers\ShopifyDriver::class,
            'auth' => \Anibalealvarezs\ShopifyHubDriver\Auth\ShopifyAuthProvider::class,
            'processor' => [\Classes\Requests\MetricRequests::class, 'processShopifyChunk'],
        ],
        'klaviyo' => [
            'driver' => \Anibalealvarezs\KlaviyoHubDriver\Drivers\KlaviyoDriver::class,
            'auth' => \Anibalealvarezs\KlaviyoHubDriver\Auth\KlaviyoAuthProvider::class,
            'processor' => [\Classes\Requests\MetricRequests::class, 'processKlaviyoChunk'],
        ],
        'netsuite' => [
            'driver' => \Anibalealvarezs\NetSuiteHubDriver\Drivers\NetSuiteDriver::class,
            'auth' => \Anibalealvarezs\NetSuiteHubDriver\Auth\NetSuiteAuthProvider::class,
            'processor' => [\Classes\Requests\MetricRequests::class, 'processNetSuiteChunk'],
        ],
        'amazon' => [
            'driver' => \Anibalealvarezs\AmazonHubDriver\Drivers\AmazonDriver::class,
            'auth' => \Anibalealvarezs\AmazonHubDriver\Auth\AmazonAuthProvider::class,
        ],
        'bigcommerce' => [
            'driver' => \Anibalealvarezs\BigCommerceHubDriver\Drivers\BigCommerceDriver::class,
            'auth' => \Anibalealvarezs\BigCommerceHubDriver\Auth\BigCommerceAuthProvider::class,
        ],
        'pinterest' => [
            'driver' => \Anibalealvarezs\PinterestHubDriver\Drivers\PinterestDriver::class,
            'auth' => \Anibalealvarezs\PinterestHubDriver\Auth\PinterestAuthProvider::class,
        ],
        'linkedin' => [
            'driver' => \Anibalealvarezs\LinkedInHubDriver\Drivers\LinkedInDriver::class,
            'auth' => \Anibalealvarezs\LinkedInHubDriver\Auth\LinkedInAuthProvider::class,
        ],
        'x' => [
            'driver' => \Anibalealvarezs\XHubDriver\Drivers\XDriver::class,
            'auth' => \Anibalealvarezs\XHubDriver\Auth\XAuthProvider::class,
        ],
        'tiktok' => [
            'driver' => \Anibalealvarezs\TikTokHubDriver\Drivers\TikTokDriver::class,
            'auth' => \Anibalealvarezs\TikTokHubDriver\Auth\TikTokAuthProvider::class,
        ],
        'triplewhale' => [
            'driver' => \Anibalealvarezs\TripleWhaleHubDriver\Drivers\TripleWhaleDriver::class,
            'auth' => \Anibalealvarezs\TripleWhaleHubDriver\Auth\TripleWhaleAuthProvider::class,
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
        $channelConfig = \Helpers\Helpers::getChannelsConfig()[$channel] ?? [];
        $authProvider = new $authClass(null, $channelConfig);
        $driver = new $driverClass($authProvider, $logger);

        // Inject data processor if defined and supported by driver
        if (isset($config['processor']) && method_exists($driver, 'setDataProcessor')) {
            $driver->setDataProcessor($config['processor']);
        }

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



