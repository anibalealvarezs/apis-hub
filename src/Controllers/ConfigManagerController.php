<?php

namespace Controllers;

use Classes\Requests\MetricRequests;
use Exception;
use Helpers\Helpers;
use Services\CacheStrategyService;
use Services\ConfigSchemaRegistryService;
use Entities\Analytics\Account;
use Entities\Analytics\Channeled\ChanneledAccount;
use Enums\Channel;
use Enums\Account as AccountEnum;
use DateTime;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

class ConfigManagerController extends BaseController
{
    private string $gscConfigPath;
    private string $fbConfigPath;
    private string $fbOrganicPath;
    private string $fbMarketingPath;
    private string $assetsBackupPath;

    public function __construct()
    {
        parent::__construct();
        
        $configDir = getenv('CONFIG_DIR') ?: __DIR__ . '/../../config';
        $this->gscConfigPath = $configDir . '/channels/google_search_console.yaml';
        $this->fbConfigPath = $configDir . '/channels/facebook.yaml';
        $this->fbOrganicPath = $configDir . '/channels/facebook_organic.yaml';
        $this->fbMarketingPath = $configDir . '/channels/facebook_marketing.yaml';
        $this->assetsBackupPath = $configDir . '/assets_backup.yaml';
    }

    public function index(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/config_manager.html');
        return $this->renderWithEnv($html);
    }

    public function fetchAssets(Request $request): Response
    {
        try {
            $logger = Helpers::setLogger('config-manager.log');
            $requestedType = $request->query->get('type');
            $forceRefresh = $request->query->get('refresh') === '1';
            
            $availableChannels = \Core\Drivers\DriverFactory::getAvailableChannels();
            $allAssets = [
                'gsc' => [],
                'facebook_pages' => [],
                'facebook_ad_accounts' => [],
            ];
            foreach ($availableChannels as $chan) {
                if (!isset($allAssets[$chan])) {
                    $allAssets[$chan] = [];
                }
            }
            $lastUpdated = null;

            // STEP 0: Post-Singleton Load
            $systemConfig = Helpers::getProjectConfig();
            $logger->info("STEP 0: Global Config CAMPAIGN Filter: " . json_encode($systemConfig['channels']['facebook_marketing']['CAMPAIGN']['cache_include'] ?? 'NOT_FOUND'));

            $gsc = $systemConfig['channels']['google_search_console'] ?? [];
            $fbGlobal = $systemConfig['channels']['facebook'] ?? [];
            $fbOrganic = $systemConfig['channels']['facebook_organic'] ?? [];
            
            // STEP 1: Channel Extraction
            $fbMarketing = $systemConfig['channels']['facebook_marketing'] ?? [];
            $logger->info("STEP 1: fbMarketing CAMPAIGN Filter: " . json_encode($fbMarketing['CAMPAIGN']['cache_include'] ?? 'NOT_FOUND'));

            // STEP 2: Mixed Config
            $fbConf = array_replace_recursive($fbGlobal, $fbOrganic, $fbMarketing);
            $logger->info("STEP 2: fbConf CAMPAIGN Filter: " . json_encode($fbConf['CAMPAIGN']['cache_include'] ?? 'NOT_FOUND'));

            $currentConfig = [
                'gsc' => [], 
                'gsc_cache_history_range' => $gsc['cache_history_range'] ?? '16 months',
                'gsc_enabled' => $gsc['enabled'] ?? true,
                'gsc_feature_toggles' => [
                    'cache_aggregations' => $gsc['cache_aggregations'] ?? false
                ],
                'fb_page_ids' => [],
                'fb_ad_account_ids' => [],
                'fb_cache_chunk_size' => $fbGlobal['cache_chunk_size'] ?? '1 week',
                'fb_organic_history_range' => $fbOrganic['cache_history_range'] ?? '2 years',
                'fb_marketing_history_range' => $fbMarketing['cache_history_range'] ?? '2 years',
                'fb_entity_filters' => [],
                'fb_organic_enabled' => $fbOrganic['enabled'] ?? true,
                'fb_marketing_enabled' => $fbMarketing['enabled'] ?? true,
                'fb_metrics_strategy' => $fbMarketing['metrics_strategy'] ?? 'default',
                'fb_metrics_config' => ConfigSchemaRegistryService::hydrate('facebook_marketing', 'metrics', $fbMarketing['metrics_config'] ?? []),
                'jobs_timeout_hours' => $systemConfig['jobs']['timeout_hours'] ?? 6,
                'cache_raw_metrics' => filter_var($systemConfig['analytics']['cache_raw_metrics'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'marketing_debug_logs' => filter_var($systemConfig['analytics']['marketing_debug_logs'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'cron_entities_hour' => $systemConfig['cron']['entities_hour'] ?? 2,
                'cron_entities_minute' => $systemConfig['cron']['entities_minute'] ?? 0,
                'cron_recent_hour' => $systemConfig['cron']['recent_hour'] ?? 5,
                'cron_recent_minute' => $systemConfig['cron']['recent_minute'] ?? 0,
                'gsc_cron_entities_hour' => $gsc['cron_entities_hour'] ?? null,
                'gsc_cron_entities_minute' => $gsc['cron_entities_minute'] ?? null,
                'gsc_cron_recent_hour' => $gsc['cron_recent_hour'] ?? null,
                'gsc_cron_recent_minute' => $gsc['cron_recent_minute'] ?? null,
                'fb_organic_cron_entities_hour' => $fbOrganic['cron_entities_hour'] ?? null,
                'fb_organic_cron_entities_minute' => $fbOrganic['cron_entities_minute'] ?? null,
                'fb_organic_cron_recent_hour' => $fbOrganic['cron_recent_hour'] ?? null,
                'fb_organic_cron_recent_minute' => $fbOrganic['cron_recent_minute'] ?? null,
                'fb_marketing_cron_entities_hour' => $fbMarketing['cron_entities_hour'] ?? null,
                'fb_marketing_cron_entities_minute' => $fbMarketing['cron_entities_minute'] ?? null,
                'fb_marketing_cron_recent_hour' => $fbMarketing['cron_recent_hour'] ?? null,
                'fb_marketing_cron_recent_minute' => $fbMarketing['cron_recent_minute'] ?? null,
                'effective_schedules' => $this->getEffectiveCronSchedules(),
                'available_channels' => $availableChannels,
            ];

            // Initialize configs for all channels
            foreach ($availableChannels as $chan) {
                if (!isset($currentConfig[$chan . '_enabled'])) {
                    $chanConfig = $systemConfig['channels'][$chan] ?? [];
                    $currentConfig[$chan . '_enabled'] = $chanConfig['enabled'] ?? false;
                    $currentConfig[$chan . '_history_range'] = $chanConfig['cache_history_range'] ?? '1 year';
                }
            }

            $logger->info("DEBUG: Project Name detected: " . (getenv('PROJECT_NAME') ?: "NOT SET"));
            $logger->info("DEBUG: FB Marketing Campaign Filter: " . json_encode($fbMarketing['CAMPAIGN'] ?? 'NOT_FOUND'));
            $logger->info("DEBUG: FB Conf Campaign Filter: " . json_encode($fbConf['CAMPAIGN'] ?? 'NOT_FOUND'));

            // Re-map GSC sites with Hydration
            foreach (($gsc['sites'] ?? []) as $site) {
                $url = $site['url'];
                $currentConfig['gsc'][$url] = ConfigSchemaRegistryService::hydrate('google_search_console', 'entity', $site);
            }

            // Re-map FB entities
            if (!empty($fbConf)) {
                $entities = ['PAGE', 'POST', 'IG_ACCOUNT', 'IG_MEDIA', 'CAMPAIGN', 'ADSET', 'AD', 'CREATIVE'];
                foreach ($entities as $e) {
                    $currentConfig['fb_entity_filters'][$e] = $fbConf[$e]['cache_include'] ?? ($fbMarketing[$e]['cache_include'] ?? '');
                }
                
                // STEP 3: Post-Mapping
                $logger->info("STEP 3: currentConfig CAMPAIGN Filter Output: " . ($currentConfig['fb_entity_filters']['CAMPAIGN'] ?: 'EMPTY_STRING'));

                foreach (($fbOrganic['pages'] ?? []) as $p) {
                    $currentConfig['fb_page_ids'][] = (string)$p['id'];
                }
                $currentConfig['fb_pages_full_config'] = $fbOrganic['pages'] ?? [];

                foreach (($fbMarketing['ad_accounts'] ?? []) as $a) {
                    $currentConfig['fb_ad_account_ids'][] = (string)$a['id'];
                }

                $fbOrganicFeatures = ['page_metrics', 'posts', 'post_metrics', 'ig_accounts', 'ig_account_metrics', 'ig_account_media', 'ig_account_media_metrics'];
                $fbMarketingFeatures = ['ad_account_metrics', 'campaigns', 'campaign_metrics', 'adsets', 'adset_metrics', 'ads', 'ad_metrics', 'creatives', 'creative_metrics'];
                
                $currentConfig['fb_feature_toggles'] = [];
                // From Organic (PAGE section)
                foreach ($fbOrganicFeatures as $f) {
                    $currentConfig['fb_feature_toggles'][$f] = $fbOrganic['PAGE'][$f] ?? false; 
                }
                // From Marketing (AD_ACCOUNT section)
                foreach ($fbMarketingFeatures as $f) {
                    $currentConfig['fb_feature_toggles'][$f] = $fbMarketing['AD_ACCOUNT'][$f] ?? false;
                }
                $currentConfig['fb_feature_toggles']['cache_aggregations'] = ($fbOrganic['cache_aggregations'] ?? false) || ($fbMarketing['cache_aggregations'] ?? false);
            }

            // 2. Fetch Assets from APIs (with isolated Try-Catch)
            // Load previous backup for comparison (to know what's truly "NEW")
            $previousAssets = ['gsc' => [], 'facebook_pages' => [], 'facebook_ad_accounts' => []];
            if (file_exists($this->assetsBackupPath)) {
                try {
                    $backup = Yaml::parseFile($this->assetsBackupPath);
                    $previousAssets = $backup['assets'] ?? $previousAssets;
                    if (!$forceRefresh) {
                        $allAssets = $previousAssets;
                        $lastUpdated = filemtime($this->assetsBackupPath);
                    }
                } catch (\Throwable $e) {
                    $logger->warning("Failed to load assets backup: " . $e->getMessage());
                }
            }

            // If we need to fetch from APIs (either force refresh or no backup found for requested type)
            $needsGscRefresh = $forceRefresh || (empty($allAssets['gsc']) && (!$requestedType || $requestedType === 'gsc'));
            $needsFbRefresh = $forceRefresh || ((empty($allAssets['facebook_pages']) && empty($allAssets['facebook_ad_accounts'])) && (!$requestedType || $requestedType === 'facebook'));

            if ($needsGscRefresh) {
                try {
                    $channelsConfig = Helpers::getChannelsConfig();
                    $authProvider = new \Anibalealvarezs\GoogleHubDriver\Auth\GoogleAuthProvider($channelsConfig['google_search_console']['token_path'] ?? "");
                    $gscApi = new \Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi(
                        redirectUrl: $channelsConfig['google_search_console']['redirect_uri'] ?? $channelsConfig['google']['redirect_uri'] ?? '',
                        clientId: $channelsConfig['google_search_console']['client_id'] ?? $channelsConfig['google']['client_id'] ?? $_ENV['GOOGLE_CLIENT_ID'] ?? '',
                        clientSecret: $channelsConfig['google_search_console']['client_secret'] ?? $channelsConfig['google']['client_secret'] ?? $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
                        refreshToken: $channelsConfig['google_search_console']['refresh_token'] ?? $channelsConfig['google']['refresh_token'] ?? '',
                        userId: $channelsConfig['google_search_console']['user_id'] ?? $channelsConfig['google']['user_id'] ?? '',
                        scopes: $authProvider->getScopes(),
                        token: $authProvider->getAccessToken(),
                        tokenPath: $channelsConfig['google_search_console']['token_path'] ?? $channelsConfig['google']['token_path'] ?? ""
                    );
                    $sitesResponse = $gscApi->getSites();
                    if (isset($sitesResponse['siteEntry'])) {
                        $allAssets['gsc'] = [];
                        foreach ($sitesResponse['siteEntry'] as $entry) {
                            $url = $entry['siteUrl'];
                            $allAssets['gsc'][] = [
                                'url' => $url,
                                'title' => $this->deriveTitleFromUrl($url),
                                'hostname' => $this->deriveHostnameFromUrl($url),
                            ];
                        }
                    }
                } catch (\Throwable $e) {
                    $logger->error("Error fetching GSC sites: " . $e->getMessage());
                    if ($requestedType === 'gsc') throw $e;
                }
            }

            if ($needsFbRefresh) {
                try {
                    $fbConfig = MetricRequests::validateFacebookConfig($logger);
                    $fbApi = MetricRequests::initializeFacebookGraphApi($fbConfig, $logger);
                    
                    // Get Pages using SDK with explicit User ID and custom fields
                    $userId = $fbApi->getUserId();
                    $pagesData = $fbApi->getPages(
                        userId: $userId,
                        permissions: [], 
                        limit: 100, 
                        fields: 'id,name,instagram_business_account{id,name,username}'
                    );

                    if (!empty($pagesData['data'])) {
                        $allAssets['facebook_pages'] = [];
                        foreach ($pagesData['data'] as $page) {
                            $allAssets['facebook_pages'][] = [
                                'id' => $page['id'],
                                'title' => $page['name'],
                                'url' => '', // 'link' was removed as it might require extra permissions
                                'hostname' => '',
                                'ig_account' => $page['instagram_business_account']['id'] ?? null,
                                'ig_account_name' => $page['instagram_business_account']['username'] ?? $page['instagram_business_account']['name'] ?? null,
                            ];
                        }
                    }

                    // Get Ad Accounts using SDK with explicit User ID and custom fields
                    $adAccountsData = $fbApi->getAdAccounts(
                        userId: $userId,
                        limit: 100, 
                        fields: 'id,name,account_id,account_status,currency'
                    );

                    if (!empty($adAccountsData['data'])) {
                        $allAssets['facebook_ad_accounts'] = [];
                        foreach ($adAccountsData['data'] as $acc) {
                            $allAssets['facebook_ad_accounts'][] = [
                                'id' => $acc['id'],
                                'name' => $acc['name'] ?? ('Ad Account ' . $acc['id']),
                            ];
                        }
                    }
                } catch (\Throwable $e) {
                    $logger->error("Error fetching Facebook assets: " . $e->getMessage());
                    if ($requestedType === 'facebook') throw $e;
                }
            }

            // Save to backup if any refresh happened
            if ($needsGscRefresh || $needsFbRefresh) {
                file_put_contents($this->assetsBackupPath, Yaml::dump(['assets' => $allAssets], 10, 2));
                $lastUpdated = time();
            }

            // 3. Post-process Assets: GSC (Search Console)
            $configGscUrls = [];
            foreach (($currentConfig['gsc'] ?? []) as $url => $siteCfg) {
                $configGscUrls[] = $url;
            }
            
            // Mark lost access (in config but not in API results)
            foreach ($configGscUrls as $url) {
                $found = false;
                foreach ($allAssets['gsc'] as $fetched) {
                    if ($this->normalizeGscUrl($fetched['url']) === $this->normalizeGscUrl($url)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $allAssets['gsc'][] = [
                        'url' => $url,
                        'title' => $this->deriveTitleFromUrl($url),
                        'hostname' => $this->deriveHostnameFromUrl($url),
                        'lost_access' => true
                    ];
                }
            }

            // Mark NEW GSC sites
            foreach ($allAssets['gsc'] as &$asset) {
                if (!isset($asset['lost_access'])) {
                    $normalizedUrl = $this->normalizeGscUrl($asset['url']);
                    $isKnown = false;
                    foreach ($configGscUrls as $cU) {
                        if ($this->normalizeGscUrl($cU) === $normalizedUrl) {
                            $isKnown = true;
                            break;
                        }
                    }
                    if (!$isKnown) $asset['is_new'] = true;
                }
            }
            unset($asset);

            // 4. Post-process Assets: Facebook (Pages & Ad Accounts)
            foreach (($currentConfig['fb_page_ids'] ?? []) as $pId) {
                $found = false;
                foreach ($allAssets['facebook_pages'] as $known) {
                    if ((string)$known['id'] === (string)$pId) { $found = true; break; }
                }
                if (!$found) {
                    $allAssets['facebook_pages'][] = ['id' => $pId, 'title' => 'FB Page ' . $pId, 'hostname' => '', 'lost_access' => true];
                }
            }
            foreach (($currentConfig['fb_ad_account_ids'] ?? []) as $aId) {
                $found = false;
                foreach ($allAssets['facebook_ad_accounts'] as $known) {
                    if ((string)$known['id'] === (string)$aId) { $found = true; break; }
                }
                if (!$found) {
                    $allAssets['facebook_ad_accounts'][] = ['id' => $aId, 'name' => 'Ad Account ' . $aId, 'lost_access' => true];
                }
            }

            // Mark new assets (Neither in current config nor in previous backup)
            $prevGscUrls = array_map(fn($a) => $this->normalizeGscUrl($a['url']), $previousAssets['gsc'] ?? []);
            foreach ($allAssets['gsc'] as &$asset) {
                if (!isset($asset['lost_access'])) {
                    $normalizedUrl = $this->normalizeGscUrl($asset['url']);
                    $isKnown = in_array($normalizedUrl, $prevGscUrls) || isset($currentConfig['gsc'][$asset['url']]);
                    if (!$isKnown) $asset['is_new'] = true;
                }
            }
            unset($asset);

            $prevPageIds = array_map(fn($a) => (string)$a['id'], $previousAssets['facebook_pages'] ?? []);
            foreach ($allAssets['facebook_pages'] as &$asset) {
                if (!isset($asset['lost_access'])) {
                    $isKnown = in_array((string)$asset['id'], $prevPageIds) || in_array((string)$asset['id'], $currentConfig['fb_page_ids'] ?? []);
                    if (!$isKnown) $asset['is_new'] = true;
                }
            }
            unset($asset);

            $prevAdAccountIds = array_map(fn($a) => (string)$a['id'], $previousAssets['facebook_ad_accounts'] ?? []);
            foreach ($allAssets['facebook_ad_accounts'] as &$asset) {
                if (!isset($asset['lost_access'])) {
                    $isKnown = in_array((string)$asset['id'], $prevAdAccountIds) || in_array((string)$asset['id'], $currentConfig['fb_ad_account_ids'] ?? []);
                    if (!$isKnown) $asset['is_new'] = true;
                }
            }
            unset($asset);

            return new Response(json_encode([
                'assets' => $allAssets,
                'config' => $currentConfig,
                'countries' => self::getCountryList(),
                'last_updated' => $lastUpdated,
                'last_updated_human' => $lastUpdated ? date('Y-m-d H:i:s', $lastUpdated) : 'Never'
            ]), 200, ['Content-Type' => 'application/json']);

        } catch (\Throwable $e) {
            return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
        }
    }


    public function updateConfig(Request $request): Response
    {
        try {
            $logger = Helpers::setLogger('config-manager.log');
            $data = json_decode($request->getContent(), true);
            $type = $data['type'] ?? ''; // 'gsc' or 'facebook'
            $assets = $data['assets'] ?? [];

            $logger->info("Update config request received for type: " . $type);
            $logger->debug("Payload: " . json_encode($data));

            if ($type === 'gsc') {
                $this->updateGscConfig($assets['gsc'] ?? [], $data['cache_history_range'] ?? null, $data['enabled'] ?? true, $data['feature_toggles'] ?? []);
            } elseif ($type === 'facebook' || $type === 'facebook-organic' || $type === 'facebook-marketing') {
                $this->updateFacebookConfig(
                    assets: $assets, 
                    cacheChunkSize: $data['cache_chunk_size'] ?? null,
                    organicHistoryRange: $data['organic_history_range'] ?? $data['cache_history_range'] ?? null,
                    marketingHistoryRange: $data['marketing_history_range'] ?? $data['cache_history_range'] ?? null,
                    entityFilters: $data['entity_filters'] ?? [],
                    featureToggles: $data['feature_toggles'] ?? [],
                    enabled: $data['enabled'] ?? true,
                    type: $type,
                    metricsStrategy: $data['metrics_strategy'] ?? null,
                    metricsConfig: $data['metrics_config'] ?? null
                );
            } elseif ($type === 'global') {
                $appConfigPath = __DIR__ . '/../../config/app.yaml';
                $appConf = file_exists($appConfigPath) ? (Yaml::parseFile($appConfigPath) ?: []) : [];
                if (!isset($appConf['jobs'])) {
                    $appConf['jobs'] = [];
                }
                $appConf['jobs']['timeout_hours'] = (int) ($data['jobs_timeout_hours'] ?? 6);

                $appConf['analytics']['cache_raw_metrics'] = filter_var($data['cache_raw_metrics'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $appConf['analytics']['marketing_debug_logs'] = filter_var($data['marketing_debug_logs'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if (!isset($appConf['cron'])) {
                    $appConf['cron'] = [];
                }
                if (isset($data['cron_entities_hour'])) $appConf['cron']['entities_hour'] = (int) $data['cron_entities_hour'];
                if (isset($data['cron_entities_minute'])) $appConf['cron']['entities_minute'] = (int) $data['cron_entities_minute'];
                if (isset($data['cron_recent_hour'])) $appConf['cron']['recent_hour'] = (int) $data['cron_recent_hour'];
                if (isset($data['cron_recent_minute'])) $appConf['cron']['recent_minute'] = (int) $data['cron_recent_minute'];

                // Explicit Guard: Strip any attempts to modify system-level infrastructure via UI
                unset($appConf['db_host'], $appConf['db_name'], $appConf['app_mode']);

                file_put_contents($appConfigPath, Yaml::dump($appConf, 10, 2));
                $logger->info("Global config updated successfully");
            } elseif (in_array($type, \Core\Drivers\DriverFactory::getAvailableChannels())) {
                $this->updateGenericConfig($type, $data['enabled'] ?? true, $data['cache_history_range'] ?? null);
                $logger->info("Generic config updated successfully for channel: " . $type);
            } else {
                return new Response(json_encode(['error' => 'Invalid type: ' . $type]), 400, ['Content-Type' => 'application/json']);
            }

            $logger->info("Config updated successfully for type: " . $type);
            
            // Auto-trigger entity synchronization to ensure database reflects new config
            $this->triggerEntitySync($logger);

            return new Response(json_encode(['success' => true]), 200, ['Content-Type' => 'application/json']);
        } catch (Exception $e) {
            $logger->error("Error updating config: " . $e->getMessage());
            return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
        }
    }

    /**
     * Executes the entity initialization command in the background to avoid blocking the UI
     */
    private function triggerEntitySync($logger): void
    {
        try {
            $consolePath = realpath(__DIR__ . '/../../bin/cli.php');
            if ($consolePath) {
                $phpPath = PHP_BINARY ?: 'php';
                // Run in background to not block the UI response, but ensure it starts
                $command = "\"$phpPath\" \"$consolePath\" app:initialize-entities > /dev/null 2>&1 &";
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    $command = "start /B $phpPath \"$consolePath\" app:initialize-entities > NUL 2>&1";
                }
                exec($command);
                $logger->info("Entity synchronization triggered successfully via: $command");
            } else {
                $logger->error("Could not find console binary to trigger synchronization.");
            }
        } catch (Exception $e) {
            $logger->error("Failed to trigger entity synchronization: " . $e->getMessage());
        }
    }

    public function validateTokens(Request $request): Response
    {
        try {
            $logger = Helpers::setLogger('config-manager.log');
            $data = json_decode($request->getContent(), true);
            $type = $data['type'] ?? 'all'; // 'gsc', 'facebook', or 'all'
            
            $results = [];

            if ($type === 'gsc' || $type === 'all') {
                try {
                    $channelsConfig = Helpers::getChannelsConfig();
                    $isEnabled = $channelsConfig['google_search_console']['enabled'] ?? false;
                    
                    if ($isEnabled) {
                        $authProvider = new \Anibalealvarezs\GoogleHubDriver\Auth\GoogleAuthProvider($channelsConfig['google_search_console']['token_path'] ?? "");
                        $gscApi = new \Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi(
                            redirectUrl: $channelsConfig['google_search_console']['redirect_uri'] ?? $channelsConfig['google']['redirect_uri'] ?? '',
                            clientId: $channelsConfig['google_search_console']['client_id'] ?? $channelsConfig['google']['client_id'] ?? $_ENV['GOOGLE_CLIENT_ID'] ?? '',
                            clientSecret: $channelsConfig['google_search_console']['client_secret'] ?? $channelsConfig['google']['client_secret'] ?? $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
                            refreshToken: $channelsConfig['google_search_console']['refresh_token'] ?? $channelsConfig['google']['refresh_token'] ?? '',
                            userId: $channelsConfig['google_search_console']['user_id'] ?? $channelsConfig['google']['user_id'] ?? '',
                            scopes: $authProvider->getScopes(),
                            token: $authProvider->getAccessToken(),
                            tokenPath: $channelsConfig['google_search_console']['token_path'] ?? $channelsConfig['google']['token_path'] ?? ""
                        );
                        // This call will trigger a token refresh if the current one is expired
                        $gscApi->getSites();
                        $results['gsc'] = ['status' => 'valid', 'message' => 'GSC token is valid and working.'];
                    } else {
                        $results['gsc'] = ['status' => 'info', 'message' => 'GSC channel is disabled in configuration. Skipping.'];
                    }
                } catch (Exception $e) {
                    $results['gsc'] = ['status' => 'error', 'message' => 'GSC Error: ' . $e->getMessage()];
                }
            }

            if ($type === 'facebook' || $type === 'all') {
                try {
                    $fbConfig = MetricRequests::validateFacebookConfig($logger);
                    $fbApi = MetricRequests::initializeFacebookGraphApi($fbConfig, $logger);
                    // Test call to 'me'
                    $fbApi->performRequest('GET', 'me', ['fields' => 'id,name']);
                    $results['facebook'] = [
                        'status' => 'valid', 
                        'message' => 'Facebook token is valid and working.',
                        'access_token' => $fbConfig['facebook_marketing']['access_token'] ?? null,
                        'user_id' => $fbConfig['facebook_marketing']['user_id'] ?? null,
                    ];
                } catch (Exception $e) {
                    $results['facebook'] = [
                        'status' => 'error', 
                        'message' => 'Facebook Error: ' . $e->getMessage(),
                        'access_token' => $fbConfig['facebook_marketing']['access_token'] ?? null,
                    ];
                }
            }

            return new Response(json_encode(['success' => true, 'results' => $results]), 200, ['Content-Type' => 'application/json']);
        } catch (Exception $e) {
            return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
        }
    }

    public function exportConfig(Request $request): Response
    {
        try {
            $providedToken = $request->headers->get('X-Config-Token');
            $secretToken = $_ENV['CONFIG_SECRET_TOKEN'] ?? null;

            if (!$secretToken || $providedToken !== $secretToken) {
                return new Response(json_encode(['error' => 'Unauthorized']), 401, ['Content-Type' => 'application/json']);
            }

            $configs = [
                'google_search_console.yaml' => file_exists($this->gscConfigPath) ? file_get_contents($this->gscConfigPath) : null,
                'facebook.yaml' => file_exists($this->fbConfigPath) ? file_get_contents($this->fbConfigPath) : null,
                'facebook_organic.yaml' => file_exists($this->fbOrganicPath) ? file_get_contents($this->fbOrganicPath) : null,
                'facebook_marketing.yaml' => file_exists($this->fbMarketingPath) ? file_get_contents($this->fbMarketingPath) : null,
                'instances_rules.yaml' => file_exists(__DIR__ . '/../../config/instances_rules.yaml') ? file_get_contents(__DIR__ . '/../../config/instances_rules.yaml') : null,
            ];

            return new Response(json_encode(['success' => true, 'configs' => $configs]), 200, ['Content-Type' => 'application/json']);
        } catch (Exception $e) {
            return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
        }
    }

    private function updateGscConfig(array $selectedSites, ?string $historyRange = null, bool $enabled = true, array $featureToggles = []): void
    {
        $config = file_exists($this->gscConfigPath) ? (Yaml::parseFile($this->gscConfigPath) ?: []) : [];
        if (!isset($config['channels']['google_search_console'])) {
            $config['channels']['google_search_console'] = [];
        }
        if ($historyRange) {
            $config['channels']['google_search_console']['cache_history_range'] = $historyRange;
        }
        if (isset($featureToggles['cron_entities_hour'])) {
            $config['channels']['google_search_console']['cron_entities_hour'] = (int)$featureToggles['cron_entities_hour'];
        }
        if (isset($featureToggles['cron_entities_minute'])) {
            $config['channels']['google_search_console']['cron_entities_minute'] = (int)$featureToggles['cron_entities_minute'];
        }
        if (isset($featureToggles['cron_recent_hour'])) {
            $config['channels']['google_search_console']['cron_recent_hour'] = (int)$featureToggles['cron_recent_hour'];
        }
        if (isset($featureToggles['cron_recent_minute'])) {
            $config['channels']['google_search_console']['cron_recent_minute'] = (int)$featureToggles['cron_recent_minute'];
        }
        $config['channels']['google_search_console']['enabled'] = $enabled;

        // Handle Redis caching toggle
        if (isset($featureToggles['cache_aggregations'])) {
            $prevValue = (bool)($config['channels']['google_search_console']['cache_aggregations'] ?? false);
            $newValue = (bool)$featureToggles['cache_aggregations'];
            $config['channels']['google_search_console']['cache_aggregations'] = $newValue;
            
            if ($prevValue && !$newValue) {
                // Clear Redis for GSC
                CacheStrategyService::clearChannel('google_search_console');
            }
        }

        $currentSites = $config['channels']['google_search_console']['sites'] ?? [];
        $newSitesList = [];
        
        // Prepare normalized mapping of selected sites
        $selectedMap = [];
        foreach ($selectedSites as $sel) {
            $normUrl = $this->normalizeGscUrl($sel['url']);
            $selectedMap[$normUrl] = $sel;
        }

        // Keep existing sites that are still selected (preserves custom filters)
        $processedNormUrls = [];
        foreach ($currentSites as $site) {
            $normUrl = $this->normalizeGscUrl($site['url']);
            if (isset($selectedMap[$normUrl])) {
                // Update only target_countries and target_keywords from UI
                $site['target_countries'] = $selectedMap[$normUrl]['target_countries'] ?? [];
                $site['target_keywords'] = $selectedMap[$normUrl]['target_keywords'] ?? [];
                $newSitesList[] = $site;
                $processedNormUrls[] = $normUrl;
            }
        }

        // Add new selected sites that weren't in the config
        foreach ($selectedMap as $normUrl => $newSite) {
            if (!in_array($normUrl, $processedNormUrls)) {
                $newSitesList[] = ConfigSchemaRegistryService::getEntitySchema('google_search_console', [
                    'url' => $newSite['url'],
                    'title' => $newSite['title'],
                    'hostname' => $newSite['hostname'],
                    'target_countries' => $newSite['target_countries'] ?? [],
                    'target_keywords' => $newSite['target_keywords'] ?? [],
                ]);
            }
        }

        $config['channels']['google_search_console']['sites'] = $newSitesList;
        file_put_contents($this->gscConfigPath, Yaml::dump($config, 10, 2));
    }

    private function normalizeGscUrl(?string $url): string
    {
        if (!$url) return '';
        return rtrim(strtolower($url), '/');
    }

    private function updateFacebookConfig(
        array $assets, 
        ?string $cacheChunkSize = null,
        ?string $organicHistoryRange = null,
        ?string $marketingHistoryRange = null,
        array $entityFilters = [],
        array $featureToggles = [],
        bool $enabled = true,
        string $type = 'facebook',
        ?string $metricsStrategy = null,
        ?array $metricsConfig = null
    ): void {
        // Ensure directory exists
        $configDir = dirname($this->fbConfigPath);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        // 1. Update Global Config (Chunk Size)
        $globalConfig = file_exists($this->fbConfigPath) ? (Yaml::parseFile($this->fbConfigPath) ?: []) : [];
        if (!isset($globalConfig['channels']['facebook'])) {
            $globalConfig['channels']['facebook'] = [];
        }
        if ($cacheChunkSize) {
            $globalConfig['channels']['facebook']['cache_chunk_size'] = $cacheChunkSize;
            file_put_contents($this->fbConfigPath, Yaml::dump($globalConfig, 10, 2));
        }

        // 2. Update Organic Config
        $orgConfig = file_exists($this->fbOrganicPath) ? (Yaml::parseFile($this->fbOrganicPath) ?: []) : [];
        if (!isset($orgConfig['channels']['facebook_organic'])) {
            $orgConfig['channels']['facebook_organic'] = [];
        }
        if ($organicHistoryRange) {
            $orgConfig['channels']['facebook_organic']['cache_history_range'] = $organicHistoryRange;
        }
        if (isset($featureToggles['cron_entities_hour']) && ($type === 'facebook' || $type === 'facebook-organic')) {
            $orgConfig['channels']['facebook_organic']['cron_entities_hour'] = (int)$featureToggles['cron_entities_hour'];
        }
        if (isset($featureToggles['cron_entities_minute']) && ($type === 'facebook' || $type === 'facebook-organic')) {
            $orgConfig['channels']['facebook_organic']['cron_entities_minute'] = (int)$featureToggles['cron_entities_minute'];
        }
        if (isset($featureToggles['cron_recent_hour']) && ($type === 'facebook' || $type === 'facebook-organic')) {
            $orgConfig['channels']['facebook_organic']['cron_recent_hour'] = (int)$featureToggles['cron_recent_hour'];
        }
        if (isset($featureToggles['cron_recent_minute']) && ($type === 'facebook' || $type === 'facebook-organic')) {
            $orgConfig['channels']['facebook_organic']['cron_recent_minute'] = (int)$featureToggles['cron_recent_minute'];
        }
        if ($type === 'facebook' || $type === 'facebook-organic') {
            $orgConfig['channels']['facebook_organic']['enabled'] = $enabled;
        }

        // Handle Redis caching toggle (Organic)
        if (isset($featureToggles['cache_aggregations']) && ($type === 'facebook' || $type === 'facebook-organic')) {
            $prevValue = (bool)($orgConfig['channels']['facebook_organic']['cache_aggregations'] ?? false);
            $newValue = (bool)$featureToggles['cache_aggregations'];
            $orgConfig['channels']['facebook_organic']['cache_aggregations'] = $newValue;
            
            if ($prevValue && !$newValue) {
                CacheStrategyService::clearChannel('facebook_organic');
            }
        }

        $organicEntities = ['PAGE', 'POST', 'IG_ACCOUNT', 'IG_MEDIA'];
        foreach ($organicEntities as $e) {
            if (isset($entityFilters[$e])) {
                $orgConfig['channels']['facebook_organic'][$e]['cache_include'] = $entityFilters[$e];
            }
        }
        
        $markConfig = file_exists($this->fbMarketingPath) ? (Yaml::parseFile($this->fbMarketingPath) ?: []) : [];
        if (!isset($markConfig['channels']['facebook_marketing'])) {
            $markConfig['channels']['facebook_marketing'] = [];
        }
        if ($marketingHistoryRange) {
            $markConfig['channels']['facebook_marketing']['cache_history_range'] = $marketingHistoryRange;
        }
        if (isset($featureToggles['cron_entities_hour']) && ($type === 'facebook' || $type === 'facebook-marketing')) {
            $markConfig['channels']['facebook_marketing']['cron_entities_hour'] = (int)$featureToggles['cron_entities_hour'];
        }
        if (isset($featureToggles['cron_entities_minute']) && ($type === 'facebook' || $type === 'facebook-marketing')) {
            $markConfig['channels']['facebook_marketing']['cron_entities_minute'] = (int)$featureToggles['cron_entities_minute'];
        }
        if (isset($featureToggles['cron_recent_hour']) && ($type === 'facebook' || $type === 'facebook-marketing')) {
            $markConfig['channels']['facebook_marketing']['cron_recent_hour'] = (int)$featureToggles['cron_recent_hour'];
        }
        if (isset($featureToggles['cron_recent_minute']) && ($type === 'facebook' || $type === 'facebook-marketing')) {
            $markConfig['channels']['facebook_marketing']['cron_recent_minute'] = (int)$featureToggles['cron_recent_minute'];
        }
        if ($type === 'facebook' || $type === 'facebook-marketing') {
            $markConfig['channels']['facebook_marketing']['enabled'] = $enabled;
        }

        // Handle Redis caching toggle (Marketing)
        if (isset($featureToggles['cache_aggregations']) && ($type === 'facebook' || $type === 'facebook-marketing')) {
            $prevValue = (bool)($markConfig['channels']['facebook_marketing']['cache_aggregations'] ?? false);
            $newValue = (bool)$featureToggles['cache_aggregations'];
            $markConfig['channels']['facebook_marketing']['cache_aggregations'] = $newValue;
            
            if ($prevValue && !$newValue) {
                CacheStrategyService::clearChannel('facebook_marketing');
            }
        }
        
        if ($metricsStrategy) {
            $markConfig['channels']['facebook_marketing']['metrics_strategy'] = $metricsStrategy;
        }
        if ($metricsConfig !== null) {
            $markConfig['channels']['facebook_marketing']['metrics_config'] = $metricsConfig;
        }

        $marketingEntities = ['CAMPAIGN', 'ADSET', 'AD', 'CREATIVE'];
        foreach ($marketingEntities as $e) {
            if (isset($entityFilters[$e])) {
                $markConfig['channels']['facebook_marketing'][$e]['cache_include'] = $entityFilters[$e];
            }
        }

        // 4. Update Feature Toggles (Global Defaults for reference)
        $fbOrganicFeatures = ['page_metrics', 'posts', 'post_metrics', 'ig_accounts', 'ig_account_metrics', 'ig_account_media', 'ig_account_media_metrics'];
        foreach ($fbOrganicFeatures as $f) {
            if (isset($featureToggles[$f])) {
                $orgConfig['channels']['facebook_organic']['PAGE'][$f] = (bool)$featureToggles[$f];
            }
        }

        $fbMarketingFeatures = ['ad_account_metrics', 'campaigns', 'campaign_metrics', 'adsets', 'adset_metrics', 'ads', 'ad_metrics', 'creatives', 'creative_metrics'];
        foreach ($fbMarketingFeatures as $f) {
            if (isset($featureToggles[$f])) {
                $markConfig['channels']['facebook_marketing']['AD_ACCOUNT'][$f] = (bool)$featureToggles[$f];
            }
        }
        
        // 2. Handle Pages Sync (Organic)
        if (isset($assets['pages'])) {
            $newPagesList = [];
            foreach ($assets['pages'] as $pData) {
                // Ensure ID is string and boolean flags are casted
                $pageId = (string)$pData['id'];
                $item = [
                    'id' => $pageId,
                    'title' => $pData['title'] ?? null,
                    'url' => $pData['url'] ?? null,
                    'hostname' => $pData['hostname'] ?? null,
                    'enabled' => filter_var($pData['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'exclude_from_caching' => filter_var($pData['exclude_from_caching'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'ig_account' => $pData['ig_account'] ?? null,
                    'ig_account_name' => $pData['ig_account_name'] ?? null,
                    // Granularity Flags (Stored per page)
                    'page_metrics' => filter_var($pData['page_metrics'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'posts' => filter_var($pData['posts'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'post_metrics' => filter_var($pData['post_metrics'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'ig_accounts' => filter_var($pData['ig_accounts'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'ig_account_metrics' => filter_var($pData['ig_account_metrics'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'ig_account_media' => filter_var($pData['ig_account_media'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'ig_account_media_metrics' => filter_var($pData['ig_account_media_metrics'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ];
                $newPagesList[] = ConfigSchemaRegistryService::getEntitySchema('facebook_organic', $item);
            }
            $orgConfig['channels']['facebook_organic']['pages'] = $newPagesList;
        }
        file_put_contents($this->fbOrganicPath, Yaml::dump($orgConfig, 10, 2));

        // 3. Handle Ad Accounts Sync (Marketing)
        if (isset($assets['ad_accounts'])) {
            $selectedAccIds = array_column($assets['ad_accounts'], 'id');
            $currentAccs = $markConfig['channels']['facebook_marketing']['ad_accounts'] ?? [];
            $newAccsList = [];

            // Keep existing accounts still selected
            foreach ($currentAccs as $acc) {
                if (in_array((string)$acc['id'], $selectedAccIds)) {
                    $newAccsList[] = $acc;
                }
            }

            // Sync with DB
            $fbGroupName = $globalConfig['channels']['facebook']['accounts_group_name'] ?? $orgConfig['channels']['facebook_organic']['accounts_group_name'] ?? $markConfig['channels']['facebook_marketing']['accounts_group_name'] ?? "Default Group";
            $accountEntity = $this->getOrCreateAccount($fbGroupName);

            // Add newly selected accounts
            $existingAccIds = array_map('strval', array_column($currentAccs, 'id'));
            foreach ($assets['ad_accounts'] as $newAcc) {
                $accId = (string) $newAcc['id'];
                $accName = $newAcc['name'] ?? ("Ad Account " . $accId);
                
                if (!in_array($accId, $existingAccIds)) {
                    $newAccsList[] = ConfigSchemaRegistryService::getEntitySchema('facebook_marketing', [
                        'id' => $accId,
                        'name' => $accName,
                    ]);
                }
                
                // Sync to database physical entity
                $dbChanneled = $this->em->getRepository(ChanneledAccount::class)->findOneBy(['platformId' => $accId, 'channel' => Channel::facebook_marketing->value]);
                if (!$dbChanneled) {
                    $dbChanneled = new ChanneledAccount();
                    $dbChanneled->addPlatformId($accId)
                        ->addAccount($accountEntity)
                        ->addType(AccountEnum::META_AD_ACCOUNT)
                        ->addChannel(Channel::facebook_marketing->value)
                        ->addName($accName)
                        ->addPlatformCreatedAt(new DateTime('2010-10-06'))
                        ->addData([]);
                    $this->em->persist($dbChanneled);
                } else {
                    if ($dbChanneled->getName() !== $accName || empty($dbChanneled->getName())) {
                        $dbChanneled->addName($accName);
                        $this->em->persist($dbChanneled);
                    }
                }
            }
            $this->em->flush();
            $markConfig['channels']['facebook_marketing']['ad_accounts'] = $newAccsList;
        }
        file_put_contents($this->fbMarketingPath, Yaml::dump($markConfig, 10, 2));

        // Sync Pages to DB too
        if (isset($assets['pages'])) {
            $fbGroupName = $fbGroupName ?? "Default Group";
            $accountEntity = $this->getOrCreateAccount($fbGroupName);
            foreach ($assets['pages'] as $pData) {
                $pageId = (string)$pData['id'];
                $pageName = $pData['title'] ?? ("Page " . $pageId);
                $dbPage = $this->em->getRepository(ChanneledAccount::class)->findOneBy(['platformId' => $pageId, 'channel' => Channel::facebook_organic->value]);
                if (!$dbPage) {
                    $dbPage = new ChanneledAccount();
                    $dbPage->addPlatformId($pageId)
                        ->addAccount($accountEntity)
                        ->addType(AccountEnum::FACEBOOK_PAGE)
                        ->addChannel(Channel::facebook_organic->value)
                        ->addName($pageName)
                        ->addPlatformCreatedAt(new DateTime('2010-10-06'))
                        ->addData([]);
                    $this->em->persist($dbPage);
                } elseif ($dbPage->getName() !== $pageName || empty($dbPage->getName())) {
                    $dbPage->addName($pageName);
                    $this->em->persist($dbPage);
                }
                
                // IG sync if present
                if (!empty($pData['ig_account'])) {
                    $igId = (string)$pData['ig_account'];
                    $igName = $pData['ig_account_name'] ?? $pageName;
                    $dbIg = $this->em->getRepository(ChanneledAccount::class)->findOneBy(['platformId' => $igId, 'channel' => Channel::facebook_organic->value]);
                    if (!$dbIg) {
                        $dbIg = new ChanneledAccount();
                        $dbIg->addPlatformId($igId)
                            ->addAccount($accountEntity)
                            ->addType(AccountEnum::INSTAGRAM)
                            ->addChannel(Channel::facebook_organic->value)
                            ->addName($igName)
                            ->addPlatformCreatedAt(new DateTime('2010-10-06'))
                            ->addData([]);
                        $this->em->persist($dbIg);
                    } elseif ($dbIg->getName() !== $igName || empty($dbIg->getName())) {
                        $dbIg->addName($igName);
                        $this->em->persist($dbIg);
                    }
                }
            }
            $this->em->flush();
        }
    }

    private function deriveTitleFromUrl(string $url): string
    {
        $host = $this->deriveHostnameFromUrl($url);
        $parts = explode('.', $host);
        if (count($parts) > 1) {
            return ucwords($parts[count($parts) - 2]);
        }
        return ucwords($host);
    }

    private function deriveHostnameFromUrl(string $url): string
    {
        if (str_starts_with($url, 'sc-domain:')) {
            return substr($url, 10);
        }
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        return str_replace('www.', '', $host);
    }

    public function flushCache(Request $request): Response
    {
        $channel = $request->request->get('channel');
        if (!$channel) {
            $content = json_decode($request->getContent(), true);
            $channel = $content['channel'] ?? null;
        }

        if (!$channel) {
            return new Response(json_encode(['error' => 'Missing channel']), 400);
        }

        CacheStrategyService::clearChannel($channel);

        return new Response(json_encode([
            'success' => true,
            'message' => "All aggregation caches for '$channel' cleared."
        ]));
    }

    public static function getCountryList(): array
    {
        return [
            'usa' => 'United States',
            'esp' => 'Spain',
            'mex' => 'Mexico',
            'col' => 'Colombia',
            'arg' => 'Argentina',
            'ven' => 'Venezuela',
            'per' => 'Peru',
            'chi' => 'Chile',
            'ecu' => 'Ecuador',
            'gua' => 'Guatemala',
            'pan' => 'Panama',
            'dom' => 'Dominican Republic',
            'cos' => 'Costa Rica',
            'can' => 'Canada',
            'gbr' => 'United Kingdom',
            'fra' => 'France',
            'deu' => 'Germany',
            'ita' => 'Italy',
            'bra' => 'Brazil',
        ];
    }

    private function getEffectiveCronSchedules(): array
    {
        $schedules = [];
        try {
            // Read crontab for current user
            $output = [];
            exec('crontab -l 2>/dev/null', $output);
            
            foreach ($output as $line) {
                if (empty($line) || str_starts_with($line, '#') || !str_contains($line, 'apis-hub:cache')) {
                    continue;
                }
                
                // Example line: 0 2 * * * cd /app && php bin/cli.php apis-hub:cache "facebook_marketing" "facebook_marketing_entities" ...
                $parts = preg_split('/\s+/', $line);
                if (count($parts) < 5) continue;
                
                $minute = $parts[0];
                $hour = $parts[1];
                $cronTime = "$minute $hour";
                
                // Try to identify the instance or channel/entity
                $key = 'unknown';
                if (preg_match('/instance_name=([^&"\s]+)/', $line, $matches)) {
                    $key = $matches[1];
                } elseif (preg_match('/apis-hub:cache\s+"([^"]+)"\s+"([^"]+)"/', $line, $matches)) {
                    $key = $matches[1] . '_' . $matches[2];
                }
                
                $schedules[$key] = [
                    'minute' => $minute,
                    'hour' => $hour,
                    'time' => $cronTime
                ];
            }
        } catch (\Throwable $e) {
            // Silently fail if crontab not accessible
        }
        return $schedules;
    }

    private function getOrCreateAccount(string $name): Account
    {
        $accountEntity = $this->em->getRepository(Account::class)->getByName($name);
        if (!$accountEntity) {
            $accountEntity = new Account();
            $accountEntity->addName($name);
            $this->em->persist($accountEntity);
            $this->em->flush();
        }
        return $accountEntity;
    }

    private function updateGenericConfig(string $chan, bool $enabled, ?string $historyRange): void
    {
        $configPath = __DIR__ . "/../../config/channels/{$chan}.yaml";
        $config = file_exists($configPath) ? (\Symfony\Component\Yaml\Yaml::parseFile($configPath) ?: []) : [];
        if (!isset($config['channels'][$chan])) {
            $config['channels'][$chan] = [];
        }
        $config['channels'][$chan]['enabled'] = $enabled;
        if ($historyRange) {
            $config['channels'][$chan]['cache_history_range'] = $historyRange;
        }
        
        // Ensure directory exists
        $configDir = dirname($configPath);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        file_put_contents($configPath, \Symfony\Component\Yaml\Yaml::dump($config, 10, 2));
    }
}
