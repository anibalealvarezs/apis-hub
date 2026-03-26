<?php

namespace Controllers;

use Classes\Requests\MetricRequests;
use Exception;
use Helpers\Helpers;
use Services\CacheStrategyService;
use Services\ConfigSchemaRegistryService;
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
        
        $projectName = strtolower(getenv('PROJECT_NAME') ?: ($_ENV['PROJECT_NAME'] ?? ''));
        $suffix = $projectName ? '.' . $projectName : '';

        $this->gscConfigPath = __DIR__ . '/../../config/channels/google_search_console.yaml' . $suffix;
        $this->fbConfigPath = __DIR__ . '/../../config/channels/facebook.yaml'; // Global config usually doesn't have suffix
        $this->fbOrganicPath = __DIR__ . '/../../config/channels/facebook_organic.yaml' . $suffix;
        $this->fbMarketingPath = __DIR__ . '/../../config/channels/facebook_marketing.yaml' . $suffix;
        $this->assetsBackupPath = __DIR__ . '/../../config/assets_backup.yaml' . $suffix;

        // Fallback for paths if suffix files do not exist (optional but recommended for robustness)
        if ($suffix) {
            if (!file_exists($this->gscConfigPath)) { $this->gscConfigPath = str_replace($suffix, '', $this->gscConfigPath); }
            if (!file_exists($this->fbOrganicPath)) { $this->fbOrganicPath = str_replace($suffix, '', $this->fbOrganicPath); }
            if (!file_exists($this->fbMarketingPath)) { $this->fbMarketingPath = str_replace($suffix, '', $this->fbMarketingPath); }
        }
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
            
            $allAssets = [
                'gsc' => [],
                'facebook_pages' => [],
                'facebook_ad_accounts' => [],
            ];
            $lastUpdated = null;

            // 1. Load effective config from system source of truth (merging all Yaml + Env)
            $systemConfig = Helpers::getProjectConfig();
            $gsc = $systemConfig['channels']['google_search_console'] ?? [];
            $fbGlobal = $systemConfig['channels']['facebook'] ?? [];
            $fbOrganic = $systemConfig['channels']['facebook_organic'] ?? [];
            $fbMarketing = $systemConfig['channels']['facebook_marketing'] ?? [];

            $fbConf = array_replace_recursive($fbGlobal, $fbOrganic, $fbMarketing);

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
            ];

            // Re-map GSC sites with Hydration
            foreach (($gsc['sites'] ?? []) as $site) {
                $url = $site['url'];
                $currentConfig['gsc'][$url] = ConfigSchemaRegistryService::hydrate('google_search_console', 'entity', $site);
            }

            // Re-map FB entities
            if (!empty($fbConf)) {
                $entities = ['PAGE', 'POST', 'IG_ACCOUNT', 'IG_MEDIA', 'CAMPAIGN', 'ADSET', 'AD', 'CREATIVE'];
                foreach ($entities as $e) {
                    $currentConfig['fb_entity_filters'][$e] = $fbConf[$e]['cache_include'] ?? '';
                }

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
                    $gscConfig = MetricRequests::validateGoogleConfig($logger);
                    $gscApi = MetricRequests::initializeSearchConsoleApi($gscConfig, $logger);
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

                if (!isset($appConf['analytics'])) {
                    $appConf['analytics'] = [];
                }
                $appConf['analytics']['cache_raw_metrics'] = filter_var($data['cache_raw_metrics'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $appConf['analytics']['marketing_debug_logs'] = filter_var($data['marketing_debug_logs'] ?? false, FILTER_VALIDATE_BOOLEAN);

                // Explicit Guard: Strip any attempts to modify system-level infrastructure via UI
                unset($appConf['db_host'], $appConf['db_name'], $appConf['app_mode']);

                file_put_contents($appConfigPath, Yaml::dump($appConf, 10, 2));
                $logger->info("Global config updated successfully");
            } else {
                return new Response(json_encode(['error' => 'Invalid type: ' . $type]), 400, ['Content-Type' => 'application/json']);
            }

            $logger->info("Config updated successfully for type: " . $type);
            return new Response(json_encode(['success' => true]), 200, ['Content-Type' => 'application/json']);
        } catch (Exception $e) {
            $logger->error("Error updating config: " . $e->getMessage());
            return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
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
                    $gscConfig = MetricRequests::validateGoogleConfig($logger);
                    $isEnabled = $gscConfig['google_search_console']['enabled'] ?? false;
                    
                    if ($isEnabled) {
                        $gscApi = MetricRequests::initializeSearchConsoleApi($gscConfig, $logger);
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
                    $results['facebook'] = ['status' => 'valid', 'message' => 'Facebook token is valid and working.'];
                } catch (Exception $e) {
                    $results['facebook'] = ['status' => 'error', 'message' => 'Facebook Error: ' . $e->getMessage()];
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

            // Add newly selected accounts
            $existingAccIds = array_map('strval', array_column($currentAccs, 'id'));
            foreach ($assets['ad_accounts'] as $newAcc) {
                if (!in_array((string)$newAcc['id'], $existingAccIds)) {
                    $newAccsList[] = ConfigSchemaRegistryService::getEntitySchema('facebook_marketing', [
                        'id' => (string)$newAcc['id'],
                        'name' => $newAcc['name'] ?? '',
                    ]);
                }
            }
            $markConfig['channels']['facebook_marketing']['ad_accounts'] = $newAccsList;
        }
        file_put_contents($this->fbMarketingPath, Yaml::dump($markConfig, 10, 2));
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

}
