<?php

declare(strict_types=1);

namespace Classes;

use Anibalealvarezs\ApiDriverCore\Classes\AccountTypeRegistry;
use Anibalealvarezs\ApiDriverCore\Classes\AssetRegistry;
use Anibalealvarezs\ApiDriverCore\Classes\EntityRegistry;
use Anibalealvarezs\ApiDriverCore\Classes\PageTypeRegistry;
use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
use Exception;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;

class DriverInitializer
{
    private static array $instances = [];
    private static array $configs = [];

    /**
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

        // Dynamic merging based on driver-defined common key
        try {
            $registryConfig = DriverFactory::getChannelConfig($channel);
            $driverClass = $registryConfig['driver'] ?? null;
            if ($driverClass && class_exists($driverClass)) {
                $commonKey = $driverClass::getCommonConfigKey();
                if ($commonKey && isset($allConfigs[$commonKey])) {
                    $config = array_replace_recursive($allConfigs[$commonKey], $config);
                }
            }
        } catch (Exception $e) {
            // Fallback to parent key if driver discovery fails
            if (isset($registryConfig['parent'])) {
                $parentKey = $registryConfig['parent'];
                if (isset($allConfigs[$parentKey])) {
                    $config = array_replace_recursive($allConfigs[$parentKey], $config);
                }
            }
        }

        // Add sibling flags for shared OAuth flows
        $parent = $registryConfig['parent'] ?? null;
        if ($parent) {
            $registry = DriverFactory::getRegistry();
            foreach ($registry as $chan => $reg) {
                if (($reg['parent'] ?? null) === $parent) {
                    $propName = str_replace($parent . '_', '', $chan) . '_enabled';
                    $config[$propName] = (bool)($allConfigs[$chan]['enabled'] ?? false);
                }
            }
        }

        // Delegate channel-specific validation and normalization to the driver
        try {
            $driver = DriverFactory::get($channel, $logger);
            $config = $driver->validateConfig($config);
        } catch (Exception $e) {
            $logger?->warning("Driver validation failed for $channel: " . $e->getMessage());
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

        $config = $config ?: self::validateConfig($channel, $logger);
        $driver = DriverFactory::get($channel, $logger, $config);

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
     * Boot all registered drivers.
     * Registers asset patterns and calls boot() on each driver.
     *
     * @param LoggerInterface|null $logger
     * @return void
     */
    public static function bootDrivers(?LoggerInterface $logger = null): void
    {
        $registry = DriverFactory::getRegistry();
        foreach ($registry as $channel => $config) {
            if (! isset($config['driver'])) {
                continue;
            }

            try {
                $chanConfig = self::validateConfig($channel, $logger);
                $driver = DriverFactory::get($channel, $logger, $chanConfig);

                // 1. Register asset patterns
                $patterns = $driver->getAssetPatterns();
                foreach ($patterns as $type => $pConfig) {
                    AssetRegistry::register($type, $pConfig);
                }

                // 2. Register page types
                PageTypeRegistry::register($driver::getPageTypes());

                // 3. Register account types
                AccountTypeRegistry::register($driver::getAccountTypes());

                // 4. Register entity paths
                EntityRegistry::register($driver::getEntityPaths());

                // 5. Call boot sequence
                $driver->boot();

            } catch (Exception $e) {
                $logger?->error("Failed to boot driver for $channel: " . $e->getMessage());
            }
        }
    }
}
