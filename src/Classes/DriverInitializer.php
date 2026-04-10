<?php

declare(strict_types=1);

namespace Classes;

use Core\Drivers\DriverFactory;
use Anibalealvarezs\ApiDriverCore\Enums\Channel;
use Exception;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;

class DriverInitializer
{
    private static array $instances = [];
    private static array $configs = [];

    /**
     * Limpia todas las instancias y configuraciones (útil para testing).
     */
    public static function reset(): void
    {
        self::$instances = [];
        self::$configs = [];
    }

    /**
     * @param string $channel
     * @param LoggerInterface|null $logger
     * @return array
     * @throws Exception
     */
    public static function validateConfig(string $channel, ?LoggerInterface $logger = null): array
    {
        if (isset(self::$configs[$channel])) {
            return self::$configs[$channel];
        }

        $allConfigs = Helpers::getChannelsConfig();
        $config = $allConfigs[$channel] ?? [];

        // Dynamic merging based on parent key in drivers.yaml
        $registryConfig = DriverFactory::getChannelConfig($channel);
        if (isset($registryConfig['parent'])) {
            $parentKey = $registryConfig['parent'];
            if (isset($allConfigs[$parentKey])) {
                $config = array_replace_recursive($allConfigs[$parentKey], $config);
            }
        }

        // Delegate channel-specific validation and normalization to the driver
        try {
            $driver = DriverFactory::get($channel, $logger);
            $config = $driver->validateConfig($config);
        } catch (Exception $e) {
            if ($logger) {
                $logger->warning("Driver validation failed for $channel: " . $e->getMessage());
            }
        }

        self::$configs[$channel] = $config;

        return $config;
    }

    /**
     * @param string $channel
     * @param array $config
     * @param LoggerInterface|null $logger
     * @return mixed
     * @throws Exception
     */
    public static function initializeApi(string $channel, array $config = [], ?LoggerInterface $logger = null): mixed
    {
        $forceNew = ! empty($config['force_new']);
        $cacheKey = $channel;

        if (! $forceNew && isset(self::$instances[$cacheKey])) {
            return self::$instances[$cacheKey];
        }

        $driver = DriverFactory::get($channel, $logger);

        // Merge config into the driver if needed, but getApi usually takes it
        $api = $driver->getApi($config);

        if ($logger && method_exists($api, 'setLogger')) {
            $api->setLogger($logger);
        }

        if (! $forceNew) {
            self::$instances[$cacheKey] = $api;
        }

        return $api;
    }

    /**
     * @deprecated Use validateConfig('facebook_marketing') or similar
     */
    public static function validateFacebookConfig(?LoggerInterface $logger = null, Channel|string|null $channel = null): array
    {
        $chanKey = ($channel instanceof Channel) ? $channel->name : (string)($channel ?: 'facebook_marketing');

        return self::validateConfig($chanKey, $logger);
    }

    /**
     * @deprecated Use initializeApi('facebook_marketing', $config)
     */
    public static function initializeFacebookGraphApi(array $config, ?LoggerInterface $logger = null): mixed
    {
        return self::initializeApi('facebook_marketing', $config, $logger);
    }

    /**
     * @deprecated Use validateConfig('google_search_console')
     */
    public static function validateGoogleConfig(?LoggerInterface $logger = null): array
    {
        $config = self::validateConfig('google_search_console', $logger);

        return [
            'google' => $config, // In legacy, it returned this structure
            'google_search_console' => $config,
        ];
    }

    /**
     * @deprecated Use initializeApi('google_search_console', $config)
     */
    public static function initializeGoogleSearchConsoleApi(array $config, ?LoggerInterface $logger = null): mixed
    {
        return self::initializeApi('google_search_console', $config, $logger);
    }

    /**
     * Boot all registered drivers.
     * Registers asset patterns and calls boot() on each driver.
     *
     * @param LoggerInterface|null $logger
     * @return void
     */
    public static function bootDrivers(?LoggerInterface $logger = null): void
    {
        $registry = DriverFactory::getRegistry();
        foreach (array_keys($registry) as $channel) {
            try {
                $driver = DriverFactory::get($channel, $logger);
                
                // 1. Register asset patterns
                $patterns = $driver->getAssetPatterns();
                foreach ($patterns as $type => $pConfig) {
                    \Anibalealvarezs\ApiDriverCore\Classes\AssetRegistry::register($type, $pConfig);
                }

                // 2. Call boot sequence
                $driver->boot();

            } catch (Exception $e) {
                if ($logger) {
                    $logger->error("Failed to boot driver for $channel: " . $e->getMessage());
                }
            }
        }
    }
}
