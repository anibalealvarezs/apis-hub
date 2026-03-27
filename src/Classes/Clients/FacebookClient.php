<?php

namespace Classes\Clients;

use Classes\Overrides\FacebookGraphApiOverride;
use Exception;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;

class FacebookClient
{
    private static ?FacebookGraphApiOverride $instance = null;
    private static ?array $config = null;

    /**
     * Retrieves the Singleton instance of FacebookGraphApiOverride.
     * Allows optional injection of specific config, but ideally uses the unified config.
     *
     * @param LoggerInterface|null $logger
     * @param array|null $overrideConfig
     * @return FacebookGraphApiOverride
     * @throws Exception
     */
    public static function getInstance(?LoggerInterface $logger = null, ?array $overrideConfig = null): FacebookGraphApiOverride
    {
        if (self::$instance !== null && $overrideConfig === null) {
            return self::$instance;
        }

        $config = $overrideConfig ?? self::getConfig($logger);

        // --- DYNAMIC DETECTION START ---
        // Let's try to detect the active user/token from the JSON file to be dynamic
        $tokenPath = (string) ($config['graph_token_path'] ?? '');
        if ($tokenPath && file_exists($tokenPath)) {
            try {
                $storedTokens = json_decode(file_get_contents($tokenPath), true) ?? [];
                // If we have a 'facebook_marketing' node (our master reference)
                if (isset($storedTokens['facebook_marketing'])) {
                    $ref = $storedTokens['facebook_marketing'];
                    // Use the user_id from the token file if current is missing or we want to be 100% dynamic
                    if (!empty($ref['user_id'])) {
                        $logger?->info("Using dynamic Facebook User ID from storage: " . $ref['user_id']);
                        $config['user_id'] = $ref['user_id'];
                    }
                    if (!empty($ref['access_token'])) {
                        $logger?->info("Using dynamic Facebook Access Token from storage");
                        // Inject into longLivedUserAccessToken to override old ENV-based ones
                        $config['graph_long_lived_user_access_token'] = $ref['access_token'];
                    }
                }
            } catch (\Throwable $e) {
                $logger?->warning("Failed to perform dynamic Facebook detection: " . $e->getMessage());
            }
        }
        // --- DYNAMIC DETECTION END ---

        $maxApiRetries = 3;
        $apiRetryCount = 0;

        while ($apiRetryCount < $maxApiRetries) {
            try {
                $appSecret = $config['app_secret'] ?? null;
                if ($appSecret === null) {
                    $keysFound = implode(', ', array_keys($config));
                    $logger?->error("Facebook app_secret is missing or null. Keys found: $keysFound");
                    throw new Exception("Facebook app_secret is missing or null.");
                }

                $apiInstance = new FacebookGraphApiOverride(
                    userId: (string) ($config['user_id'] ?? ''),
                    appId: (string) ($config['app_id'] ?? ''),
                    appSecret: (string) $appSecret,
                    redirectUrl: (string) ($config['app_redirect_uri'] ?? ''),
                    userAccessToken: (string) ($config['graph_user_access_token'] ?? ''),
                    longLivedUserAccessToken: (string) ($config['graph_long_lived_user_access_token'] ?? ''),
                    appAccessToken: (string) ($config['graph_app_access_token'] ?? ''),
                    pageAccesstoken: (string) ($config['graph_page_access_token'] ?? ''),
                    longLivedPageAccesstoken: (string) ($config['graph_long_lived_page_access_token'] ?? ''),
                    clientAccesstoken: (string) ($config['graph_client_access_token'] ?? ''),
                    longLivedClientAccesstoken: (string) ($config['graph_long_lived_client_access_token'] ?? ''),
                    tokenPath: (string) ($config['graph_token_path'] ?? ''),
                );

                $logger?->info("Initialized FacebookGraphApi via FacebookClient Singleton");
                
                if ($overrideConfig === null) {
                    self::$instance = $apiInstance;
                }

                return $apiInstance;
            } catch (Exception $e) {
                $apiRetryCount++;
                if ($apiRetryCount >= $maxApiRetries) {
                    $logger?->error("Failed to initialize FacebookGraphApi after $maxApiRetries retries: " . $e->getMessage());
                    throw new Exception("Failed to initialize FacebookGraphApi after $maxApiRetries retries: " . $e->getMessage());
                }
                $logger?->warning("FacebookGraphApi initialization failed, retry $apiRetryCount/$maxApiRetries: " . $e->getMessage());
                usleep(100000 * $apiRetryCount);
            }
        }

        throw new Exception("Failed to initialize FacebookGraphApi");
    }

    /**
     * Validates and returns the Facebook configuration with global defaults.
     *
     * @param LoggerInterface|null $logger
     * @param string|null $channel Optional channel name (facebook_organic or facebook_marketing)
     * @return array
     * @throws Exception
     */
    public static function getConfig(?LoggerInterface $logger = null, ?string $channel = null): array
    {
        // For BC and general usage, we keep the static cache, but only if no specific channel is requested
        if ($channel === null && self::$config !== null) {
            return self::$config;
        }

        $channels = Helpers::getChannelsConfig();
        
        $config = $channels['facebook'] ?? [];
        $organic = $channels['facebook_organic'] ?? [];
        $marketing = $channels['facebook_marketing'] ?? [];

        // Handle wrapped configuration if directly passed
        if (isset($config['facebook']) && is_array($config['facebook']) && !isset($config['app_id'])) {
            $config = $config['facebook'];
        }

        // Merge specialized files into the main config array selectively
        if ($channel === 'facebook_organic') {
            $config = array_replace_recursive($config, $organic);
        } elseif ($channel === 'facebook_marketing') {
            $config = array_replace_recursive($config, $marketing);
        } else {
            // Legacy behavior: merge all
            $config = array_replace_recursive($config, $organic, $marketing);
        }

        // Explicitly preserve enablement flags to help with scope detection
        $config['organic_enabled'] = (bool) ($organic['enabled'] ?? false);
        $config['marketing_enabled'] = (bool) ($marketing['enabled'] ?? false);

        if (empty($config)) {
            $logger?->error("Facebook configuration not found in channels config.");
            throw new \RuntimeException("Facebook configuration not found in channels config.");
        }

        // Global exclusion list
        $globalExclude = $config['exclude_from_caching'] ?? [];
        if (!is_array($globalExclude)) {
            $globalExclude = [$globalExclude];
        }

        // Apply global defaults for PAGES
        if (isset($config['PAGE'])) {
            $globalPageDefaults = $config['PAGE'];
            $config['pages'] = array_map(function ($page) use ($globalPageDefaults, $globalExclude) {
                $merged = array_merge($globalPageDefaults, $page);
                if (in_array((string) $merged['id'], array_map('strval', $globalExclude))) {
                    $merged['exclude_from_caching'] = true;
                }
                return $merged;
            }, $config['pages'] ?? []);
        }

        // Apply global defaults for AD_ACCOUNTS
        if (isset($config['AD_ACCOUNT'])) {
            $globalAdAccountDefaults = $config['AD_ACCOUNT'];
            $config['ad_accounts'] = array_map(function ($adAccount) use ($globalAdAccountDefaults, $globalExclude) {
                $merged = array_merge($globalAdAccountDefaults, $adAccount);
                if (in_array((string) $merged['id'], array_map('strval', $globalExclude))) {
                    $merged['exclude_from_caching'] = true;
                }
                return $merged;
            }, $config['ad_accounts'] ?? []);
        }

        if (!isset($config['pages']) || !is_array($config['pages'])) {
             $config['pages'] = []; 
        }

        if (!isset($config['ad_accounts']) || !is_array($config['ad_accounts'])) {
             $config['ad_accounts'] = [];
        }

        self::$config = $config;
        return self::$config;
    }
}
