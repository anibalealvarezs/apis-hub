<?php

declare(strict_types=1);

namespace Classes;

use Core\Drivers\DriverFactory;
use Enums\Channel;
use Exception;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;

class DriverInitializer
{
    private static array $instances = [];
    private static array $configs = [];

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

        // Common merging patterns (google_*, facebook_*)
        if (str_starts_with($channel, 'google_') && isset($allConfigs['google'])) {
            $config = array_replace_recursive($allConfigs['google'], $config);
        }

        if (str_starts_with($channel, 'facebook_') && isset($allConfigs['facebook'])) {
            $config = array_replace_recursive($allConfigs['facebook'], $config);
            
            // Legacy Facebook defaults logic
            $config['organic_enabled'] = (bool) ($allConfigs['facebook_organic']['enabled'] ?? false);
            $config['marketing_enabled'] = (bool) ($allConfigs['facebook_marketing']['enabled'] ?? false);

            $globalExclude = $config['exclude_from_caching'] ?? [];
            if (!is_array($globalExclude)) {
                $globalExclude = [$globalExclude];
            }

            if (isset($config['PAGE'])) {
                $globalPageDefaults = $config['PAGE'];
                $config['pages'] = array_map(function ($page) use ($globalPageDefaults, $globalExclude) {
                    $merged = array_merge($globalPageDefaults, $page);
                    if (in_array((string)($merged['id'] ?? ''), array_map('strval', $globalExclude))) {
                        $merged['exclude_from_caching'] = true;
                    }
                    return $merged;
                }, $config['pages'] ?? []);
            }

            if (isset($config['AD_ACCOUNT'])) {
                $globalAdAccountDefaults = $config['AD_ACCOUNT'];
                $config['ad_accounts'] = array_map(function ($adAccount) use ($globalAdAccountDefaults, $globalExclude) {
                    $merged = array_merge($globalAdAccountDefaults, $adAccount);
                    if (in_array((string)($merged['id'] ?? ''), array_map('strval', $globalExclude))) {
                        $merged['exclude_from_caching'] = true;
                    }
                    return $merged;
                }, $config['ad_accounts'] ?? []);
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
        $forceNew = !empty($config['force_new']);
        $cacheKey = $channel;

        if (!$forceNew && isset(self::$instances[$cacheKey])) {
            return self::$instances[$cacheKey];
        }

        $driver = DriverFactory::get($channel, $logger);
        
        // Merge config into the driver if needed, but getApi usually takes it
        $api = $driver->getApi($config);

        if ($logger && method_exists($api, 'setLogger')) {
            $api->setLogger($logger);
        }

        if (!$forceNew) {
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
}
