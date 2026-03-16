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
                'gsc' => [], // url => [target_countries, target_keywords]
                'fb_page_ids' => [],
                'fb_ad_account_ids' => [],
                'fb_cache_chunk_size' => '1 week',
            ];

            if (file_exists($this->gscConfigPath)) {
                $gscConf = Yaml::parseFile($this->gscConfigPath);
                $sites = $gscConf['channels']['google_search_console']['sites'] ?? [];
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
                $currentConfig['fb_cache_chunk_size'] = $fbConf['cache_chunk_size'] ?? '1 week';
                $pages = $fbConf['pages'] ?? [];
                foreach ($pages as $p) {
                    $currentConfig['fb_page_ids'][] = (string)$p['id'];
                }
                $ads = $fbConf['ad_accounts'] ?? [];
                foreach ($ads as $a) {
                    $currentConfig['fb_ad_account_ids'][] = (string)$a['id'];
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
            $data = json_decode($request->getContent(), true);
            $type = $data['type'] ?? ''; // 'gsc' or 'facebook'
            $assets = $data['assets'] ?? [];

            if ($type === 'gsc') {
                $this->updateGscConfig($assets);
            } elseif ($type === 'facebook') {
                $this->updateFacebookConfig($assets, $data['cache_chunk_size'] ?? null);
            } else {
                return new Response(json_encode(['error' => 'Invalid type']), 400, ['Content-Type' => 'application/json']);
            }

            return new Response(json_encode(['success' => true]), 200, ['Content-Type' => 'application/json']);
        } catch (Exception $e) {
            return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
        }
    }

    private function updateGscConfig(array $selectedSites): void
    {
        $config = Yaml::parseFile($this->gscConfigPath);
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

    private function updateFacebookConfig(array $assets, ?string $cacheChunkSize = null): void
    {
        // Ensure directory exists
        $configDir = dirname($this->fbConfigPath);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        // 1. Update Global Config
        if ($cacheChunkSize) {
            $config = file_exists($this->fbConfigPath) ? Yaml::parseFile($this->fbConfigPath) : ['channels' => ['facebook' => []]];
            $config['channels']['facebook']['cache_chunk_size'] = $cacheChunkSize;
            file_put_contents($this->fbConfigPath, Yaml::dump($config, 10, 2));
        }
        
        // 2. Handle Pages Sync (Organic)
        if (isset($assets['pages'])) {
            $config = file_exists($this->fbOrganicPath) ? Yaml::parseFile($this->fbOrganicPath) : ['channels' => ['facebook_organic' => []]];
            $selectedPageIds = array_column($assets['pages'], 'id');
            $currentPages = $config['channels']['facebook_organic']['pages'] ?? [];
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
            $config['channels']['facebook_organic']['pages'] = $newPagesList;
            file_put_contents($this->fbOrganicPath, Yaml::dump($config, 10, 2));
        }

        // 3. Handle Ad Accounts Sync (Marketing)
        if (isset($assets['ad_accounts'])) {
            $config = file_exists($this->fbMarketingPath) ? Yaml::parseFile($this->fbMarketingPath) : ['channels' => ['facebook_marketing' => []]];
            $selectedAccIds = array_column($assets['ad_accounts'], 'id');
            $currentAccs = $config['channels']['facebook_marketing']['ad_accounts'] ?? [];
            $newAccsList = [];

            // Keep existing accounts still selected
            foreach ($currentAccs as $acc) {
                if (in_array((string)$acc['id'], $selectedAccIds)) {
                    // Update name if provided in assets
                    foreach ($assets['ad_accounts'] as $newAcc) {
                        if ((string)$newAcc['id'] === (string)$acc['id'] && isset($newAcc['name'])) {
                            $acc['name'] = $newAcc['name'];
                            break;
                        }
                    }
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
            $config['channels']['facebook_marketing']['ad_accounts'] = $newAccsList;
            file_put_contents($this->fbMarketingPath, Yaml::dump($config, 10, 2));
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
