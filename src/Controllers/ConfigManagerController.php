<?php

namespace Controllers;

use Classes\Requests\MetricRequests;
use Exception;
use Helpers\Helpers;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

class ConfigManagerController
{
    private string $gscConfigPath;
    private string $fbConfigPath;
    private string $fbOrganicPath;
    private string $fbMarketingPath;
    private string $assetsBackupPath;

    public function __construct()
    {
        $this->gscConfigPath = __DIR__ . '/../../config/channels/google_search_console.yaml';
        $this->fbConfigPath = __DIR__ . '/../../config/channels/facebook.yaml';
        $this->fbOrganicPath = __DIR__ . '/../../config/channels/facebook_organic.yaml';
        $this->fbMarketingPath = __DIR__ . '/../../config/channels/facebook_marketing.yaml';
        $this->assetsBackupPath = __DIR__ . '/../../config/assets_backup.yaml';
    }

    public function index(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/config_manager.html');
        return new Response($html, 200, ['Content-Type' => 'text/html']);
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

            // Try to load from backup first if not forcing refresh
            if (!$forceRefresh && file_exists($this->assetsBackupPath)) {
                $backup = Yaml::parseFile($this->assetsBackupPath);
                $allAssets = $backup['assets'] ?? $allAssets;
                $lastUpdated = filemtime($this->assetsBackupPath);
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
                } catch (Exception $e) {
                    $logger->error("Error fetching GSC sites: " . $e->getMessage());
                    if ($requestedType === 'gsc') throw $e;
                }
            }

            if ($needsFbRefresh) {
                try {
                    $fbConfig = MetricRequests::validateFacebookConfig($logger);
                    $fbApi = MetricRequests::initializeFacebookGraphApi($fbConfig, $logger);
                    
                    // Get Pages
                    $pagesResponse = $fbApi->performRequest(
                        'GET',
                        'v25.0/me/accounts',
                        ['fields' => 'id,name,link,instagram_business_account']
                    );
                    $pagesData = json_decode($pagesResponse->getBody()->getContents(), true);
                    if (isset($pagesData['data'])) {
                        $allAssets['facebook_pages'] = [];
                        foreach ($pagesData['data'] as $page) {
                            $allAssets['facebook_pages'][] = [
                                'id' => $page['id'],
                                'title' => $page['name'],
                                'url' => $page['link'] ?? '',
                                'hostname' => isset($page['link']) ? $this->deriveHostnameFromUrl($page['link']) : '',
                                'ig_account' => $page['instagram_business_account']['id'] ?? null,
                            ];
                        }
                    }

                    // Get Ad Accounts
                    $adAccountsResponse = $fbApi->getMyAdAccounts();
                    if (isset($adAccountsResponse['data'])) {
                        $allAssets['facebook_ad_accounts'] = [];
                        foreach ($adAccountsResponse['data'] as $acc) {
                            $allAssets['facebook_ad_accounts'][] = [
                                'id' => $acc['id'],
                                'name' => $acc['name'] ?? ('Ad Account ' . $acc['id']),
                            ];
                        }
                    }
                } catch (Exception $e) {
                    $logger->error("Error fetching Facebook assets: " . $e->getMessage());
                    if ($requestedType === 'facebook') throw $e;
                }
            }

            // Save to backup if any refresh happened
            if ($needsGscRefresh || $needsFbRefresh) {
                file_put_contents($this->assetsBackupPath, Yaml::dump(['assets' => $allAssets], 10, 2));
                $lastUpdated = time();
            }

            // Load current config for sync state
            $currentConfig = [
                'gsc' => [], 
                'gsc_cache_history_range' => '16 months',
                'gsc_enabled' => true,
                'fb_page_ids' => [],
                'fb_ad_account_ids' => [],
                'fb_cache_chunk_size' => '1 week',
                'fb_organic_history_range' => '2 years',
                'fb_marketing_history_range' => '2 years',
                'fb_entity_filters' => [], // entity => include_string
                'fb_organic_enabled' => true,
                'fb_marketing_enabled' => true,
                'jobs_timeout_hours' => 6,
                'cache_raw_metrics' => false,
            ];

            $appConfigPath = __DIR__ . '/../../config/app.yaml';
            if (file_exists($appConfigPath)) {
                $appConf = Yaml::parseFile($appConfigPath);
                $currentConfig['jobs_timeout_hours'] = $appConf['jobs']['timeout_hours'] ?? 6;
                $currentConfig['cache_raw_metrics'] = filter_var($appConf['analytics']['cache_raw_metrics'] ?? false, FILTER_VALIDATE_BOOLEAN);
            }

            if (file_exists($this->gscConfigPath)) {
                $gscConf = Yaml::parseFile($this->gscConfigPath);
                $gsc = $gscConf['channels']['google_search_console'] ?? [];
                $currentConfig['gsc_cache_history_range'] = $gsc['cache_history_range'] ?? '16 months';
                $currentConfig['gsc_enabled'] = $gsc['enabled'] ?? true;
                $sites = $gsc['sites'] ?? [];
                foreach ($sites as $site) {
                    $currentConfig['gsc'][$site['url']] = [
                        'target_countries' => $site['target_countries'] ?? [],
                        'target_keywords' => $site['target_keywords'] ?? [],
                    ];
                }
            }

            // Merge all Facebook config files to get complete state
            $fbGlobal = file_exists($this->fbConfigPath) ? Yaml::parseFile($this->fbConfigPath) : [];
            $fbOrganic = file_exists($this->fbOrganicPath) ? Yaml::parseFile($this->fbOrganicPath) : [];
            $fbMarketing = file_exists($this->fbMarketingPath) ? Yaml::parseFile($this->fbMarketingPath) : [];
            
            // Extract from their respective keys
            $fbGlobalConfig = $fbGlobal['channels']['facebook'] ?? [];
            $fbOrganicConfig = $fbOrganic['channels']['facebook_organic'] ?? [];
            $fbMarketingConfig = $fbMarketing['channels']['facebook_marketing'] ?? [];
            
            $fbConf = array_replace_recursive($fbGlobalConfig, $fbOrganicConfig, $fbMarketingConfig);
            
            if (!empty($fbConf)) {
                $currentConfig['fb_cache_chunk_size'] = $fbGlobalConfig['cache_chunk_size'] ?? '1 week';
                $currentConfig['fb_organic_history_range'] = $fbOrganicConfig['cache_history_range'] ?? '2 years';
                $currentConfig['fb_marketing_history_range'] = $fbMarketingConfig['cache_history_range'] ?? '2 years';
                $currentConfig['fb_organic_enabled'] = $fbOrganicConfig['enabled'] ?? true;
                $currentConfig['fb_marketing_enabled'] = $fbMarketingConfig['enabled'] ?? true;
                
                $entities = ['PAGE', 'POST', 'IG_ACCOUNT', 'IG_MEDIA', 'CAMPAIGN', 'ADSET', 'AD', 'CREATIVE'];
                foreach ($entities as $e) {
                    $currentConfig['fb_entity_filters'][$e] = $fbConf[$e]['cache_include'] ?? '';
                }

                $pages = $fbConf['pages'] ?? [];
                foreach ($pages as $p) {
                    $currentConfig['fb_page_ids'][] = (string)$p['id'];
                }
                $ads = $fbConf['ad_accounts'] ?? [];
                foreach ($ads as $a) {
                    $currentConfig['fb_ad_account_ids'][] = (string)$a['id'];
                }

                $fbOrganicFeatures = [
                    'page_metrics' => false,
                    'posts' => true,
                    'post_metrics' => false,
                    'ig_accounts' => true,
                    'ig_account_metrics' => false,
                    'ig_account_media' => true,
                    'ig_account_media_metrics' => false,
                ];
                $fbMarketingFeatures = [
                    'ad_account_metrics' => true,
                    'campaigns' => true,
                    'campaign_metrics' => true,
                    'adsets' => false,
                    'adset_metrics' => false,
                    'ads' => false,
                    'ad_metrics' => false,
                    'creatives' => false,
                    'creative_metrics' => false,
                ];
                
                $currentConfig['fb_feature_toggles'] = [];
                // From Organic (PAGE defaults)
                foreach ($fbOrganicFeatures as $f => $default) {
                    $currentConfig['fb_feature_toggles'][$f] = $fbOrganicConfig['PAGE'][$f] ?? $default;
                }
                // From Marketing (AD_ACCOUNT defaults)
                foreach ($fbMarketingFeatures as $f => $default) {
                    $currentConfig['fb_feature_toggles'][$f] = $fbMarketingConfig['AD_ACCOUNT'][$f] ?? $default;
                }
            }

            return new Response(json_encode([
                'assets' => $allAssets,
                'config' => $currentConfig,
                'countries' => self::getCountryList(),
                'last_updated' => $lastUpdated,
                'last_updated_human' => $lastUpdated ? date('Y-m-d H:i:s', $lastUpdated) : 'Never'
            ]), 200, ['Content-Type' => 'application/json']);

        } catch (Exception $e) {
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
                $this->updateGscConfig($assets, $data['cache_history_range'] ?? null, $data['enabled'] ?? true);
            } elseif ($type === 'facebook' || $type === 'facebook-organic' || $type === 'facebook-marketing') {
                $this->updateFacebookConfig(
                    assets: $assets, 
                    cacheChunkSize: $data['cache_chunk_size'] ?? null,
                    organicHistoryRange: $data['organic_history_range'] ?? $data['cache_history_range'] ?? null,
                    marketingHistoryRange: $data['marketing_history_range'] ?? $data['cache_history_range'] ?? null,
                    entityFilters: $data['entity_filters'] ?? [],
                    featureToggles: $data['feature_toggles'] ?? [],
                    enabled: $data['enabled'] ?? true,
                    type: $type
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

    private function updateGscConfig(array $selectedSites, ?string $historyRange = null, bool $enabled = true): void
    {
        $config = file_exists($this->gscConfigPath) ? (Yaml::parseFile($this->gscConfigPath) ?: []) : [];
        if (!isset($config['channels']['google_search_console'])) {
            $config['channels']['google_search_console'] = [];
        }
        if ($historyRange) {
            $config['channels']['google_search_console']['cache_history_range'] = $historyRange;
        }
        $config['channels']['google_search_console']['enabled'] = $enabled;
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
                $newSitesList[] = [
                    'url' => $newSite['url'],
                    'title' => $newSite['title'],
                    'hostname' => $newSite['hostname'],
                    'include_keywords' => [],
                    'exclude_keywords' => [],
                    'include_countries' => [],
                    'exclude_countries' => [],
                    'include_pages' => [],
                    'exclude_pages' => [],
                    'target_countries' => $newSite['target_countries'] ?? [],
                    'target_keywords' => $newSite['target_keywords'] ?? [],
                    'enabled' => true,
                ];
            }
        }

        $config['channels']['google_search_console']['sites'] = $newSitesList;
        file_put_contents($this->gscConfigPath, Yaml::dump($config, 10, 2));
    }

    private function normalizeGscUrl(string $url): string
    {
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
        string $type = 'facebook'
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

        $marketingEntities = ['CAMPAIGN', 'ADSET', 'AD', 'CREATIVE'];
        foreach ($marketingEntities as $e) {
            if (isset($entityFilters[$e])) {
                $markConfig['channels']['facebook_marketing'][$e]['cache_include'] = $entityFilters[$e];
            }
        }

        // 4. Update Feature Toggles (Global Defaults)
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
            $selectedPageIds = array_column($assets['pages'], 'id');
            $currentPages = $orgConfig['channels']['facebook_organic']['pages'] ?? [];
            $newPagesList = [];

            // Keep existing pages still selected
            foreach ($currentPages as $page) {
                if (in_array((string)$page['id'], $selectedPageIds)) {
                    $newPagesList[] = $page;
                }
            }

            // Add newly selected pages
            $existingPageIds = array_map('strval', array_column($currentPages, 'id'));
            foreach ($assets['pages'] as $newPage) {
                if (!in_array((string)$newPage['id'], $existingPageIds)) {
                    $newPagesList[] = [
                        'id' => $newPage['id'],
                        'url' => $newPage['url'],
                        'title' => $newPage['title'],
                        'hostname' => $newPage['hostname'],
                        'ig_account' => $newPage['ig_account'],
                        'enabled' => true,
                        'exclude_from_caching' => false,
                    ];
                }
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
                    $newAccsList[] = [
                        'id' => (string)$newAcc['id'],
                        'name' => $newAcc['name'] ?? '',
                        'enabled' => true,
                        'exclude_from_caching' => false,
                    ];
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
