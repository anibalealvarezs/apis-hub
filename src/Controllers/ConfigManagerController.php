<?php

namespace Controllers;

use Anibalealvarezs\ApiDriverCore\Services\CacheStrategyService;
use Entities\Analytics\Account;
use Entities\Analytics\Channel;
use Entities\Analytics\Channeled\ChanneledAccount;
use Exception;
use Helpers\Helpers;
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
        $this->assetsBackupPath = $configDir . '/assets_backup.yaml';
    }

    private function getChannelAttributes(string $channel): array
    {
        $configDir = getenv('CONFIG_DIR') ?: __DIR__ . '/../../config';

        try {
            $driver = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::get($channel);
            // We use the common config key from the driver if it exists
            $common = $driver::getCommonConfigKey();

            return [
                'path' => $configDir . "/channels/{$channel}.yaml",
                'common' => $common,
            ];
        } catch (Exception $e) {
            return [
                'path' => $configDir . "/channels/{$channel}.yaml",
                'common' => null,
            ];
        }
    }

    public function index(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/config_manager.html');

        return $this->renderWithEnv($html);
    }

    public function fetchAssets(Request $request): Response
    {
        // Force refresh of configuration cache (critical for Swoole/Long-lived environments)
        Helpers::resetConfigs();

        try {
            $logger = Helpers::setLogger('config-manager.log');
            $requestedType = $request->query->get('type');
            $forceRefresh = $request->query->get('refresh') === '1';

            $availableChannels = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::getAvailableChannels();
            $systemConfig = Helpers::getProjectConfig();

            // Initial generic config
            $currentConfig = [
                'jobs_timeout_hours' => $systemConfig['jobs']['timeout_hours'] ?? 6,
                'cache_raw_metrics' => filter_var($systemConfig['analytics']['cache_raw_metrics'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'cron_entities_hour' => $systemConfig['cron']['entities_hour'] ?? 2,
                'cron_entities_minute' => $systemConfig['cron']['entities_minute'] ?? 0,
                'cron_recent_hour' => $systemConfig['cron']['recent_hour'] ?? 5,
                'cron_recent_minute' => $systemConfig['cron']['recent_minute'] ?? 0,
                'effective_schedules' => $this->getEffectiveCronSchedules(),
                'available_channels' => $availableChannels,
                'db_host' => getenv('DB_HOST') ?: 'localhost',
                'db_name' => getenv('DB_DATABASE') ?: 'apis_hub',
                'app_mode' => getenv('APP_ENV') ?: 'production',
            ];

            $allAssets = [];
            $lastUpdated = null;

            // Load previous backup ONLY if force refresh is requested OR explicitly enabled
            $previousAssets = [];
            if ($forceRefresh && file_exists($this->assetsBackupPath)) {
                try {
                    $backup = Yaml::parseFile($this->assetsBackupPath);
                    $previousAssets = $backup['assets'] ?? [];
                } catch (\Throwable $e) {
                    $logger->warning("Failed to load assets backup: " . $e->getMessage());
                }
            }

            foreach ($availableChannels as $chan) {
                try {
                    $chanConfig = $systemConfig['channels'][$chan] ?? [];

                    // Always call prepareUiConfig if the channel has a configuration
                    $isRequestedType = $requestedType && ($chan === $requestedType || str_contains($chan, $requestedType));

                    if (! empty($chanConfig) || $isRequestedType || $forceRefresh) {
                        $driver = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::get($chan);
                        $uiConfig = $driver->prepareUiConfig($chanConfig);
                        $currentConfig = array_replace_recursive($currentConfig, $uiConfig);
                    }

                    // Fast Load Logic for Assets: Populate basic asset lists without full discovery
                    if (! $forceRefresh) {
                        if ($chan === 'google_search_console') {
                            if (isset($chanConfig['sites'])) {
                                $allAssets['gsc'] = array_values($chanConfig['sites']);
                            }
                        } elseif (str_contains($chan, 'facebook_organic')) {
                            if (isset($chanConfig['pages'])) {
                                $allAssets['facebook_pages'] = array_merge($allAssets['facebook_pages'] ?? [], array_values($chanConfig['pages']));
                            }
                        } elseif ($chan === 'facebook_marketing') {
                            if (isset($chanConfig['ad_accounts'])) {
                                $allAssets['facebook_ad_accounts'] = array_values($chanConfig['ad_accounts']);
                            }
                        }

                        // If it's not a requested type for asset discovery, we skip the slow Discovery part
                        if (! $isRequestedType) {
                            continue;
                        }
                    }

                    // 2. Fetch Assets if needed (Discovery)
                    if ($isRequestedType && $forceRefresh) {
                        $driverAssets = $driver->fetchAvailableAssets();
                        // Mix with previous assets to detect "NEW" and "LOST ACCESS"
                        foreach ($driverAssets as $assetKey => $assetList) {
                            $prevList = $previousAssets[$assetKey] ?? [];
                            $prevIds = array_map(fn ($a) => (string)($a['id'] ?? ''), $prevList);
                            $freshIds = array_map(fn ($a) => (string)($a['id'] ?? ''), $assetList);

                            // Mark newly discovered assets
                            foreach ($assetList as &$asset) {
                                $asset['is_new'] = ! empty($prevIds) && ! in_array((string)($asset['id'] ?? ''), $prevIds);
                            }
                            unset($asset);

                            // Append previously-known assets that Meta no longer returns → lost_access
                            foreach ($prevList as $prevAsset) {
                                $prevId = (string)($prevAsset['id'] ?? '');
                                if ($prevId && ! in_array($prevId, $freshIds)) {
                                    $prevAsset['lost_access'] = true;
                                    $prevAsset['enabled'] = false;
                                    $prevAsset['is_new'] = false;
                                    $assetList[] = $prevAsset;
                                }
                            }

                            $allAssets[$assetKey] = $assetList;
                        }
                    }
                } catch (Exception $e) {
                    $logger->error("Error processing assets/config for $chan: " . $e->getMessage());
                }
            }

            // Save backup if refreshed
            if ($forceRefresh) {
                file_put_contents($this->assetsBackupPath, Yaml::dump(['assets' => $allAssets], 4));
            }

            return new Response(json_encode([
                'success' => true,
                'config' => $currentConfig,
                'assets' => $allAssets,
                'available_channels' => $availableChannels,
                'last_updated' => $lastUpdated ? date('Y-m-d H:i:s', $lastUpdated) : null,
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
            $type = $data['type'] ?? '';
            $assets = $data['assets'] ?? [];

            $logger->info("Update config request received for type: " . $type, ['payload' => $data]);

            if ($type === 'global') {
                $appConfigPath = __DIR__ . '/../../config/app.yaml';
                $appConf = file_exists($appConfigPath) ? (Yaml::parseFile($appConfigPath) ?: []) : [];
                if (! isset($appConf['jobs'])) {
                    $appConf['jobs'] = [];
                }
                $appConf['jobs']['timeout_hours'] = (int) ($data['jobs_timeout_hours'] ?? 6);

                $appConf['analytics']['cache_raw_metrics'] = filter_var($data['cache_raw_metrics'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $appConf['analytics']['marketing_debug_logs'] = filter_var($data['marketing_debug_logs'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if (! isset($appConf['cron'])) {
                    $appConf['cron'] = [];
                }
                if (isset($data['cron_entities_hour'])) {
                    $appConf['cron']['entities_hour'] = (int) $data['cron_entities_hour'];
                }
                if (isset($data['cron_entities_minute'])) {
                    $appConf['cron']['entities_minute'] = (int) $data['cron_entities_minute'];
                }
                if (isset($data['cron_recent_hour'])) {
                    $appConf['cron']['recent_hour'] = (int) $data['cron_recent_hour'];
                }
                if (isset($data['cron_recent_minute'])) {
                    $appConf['cron']['recent_minute'] = (int) $data['cron_recent_minute'];
                }

                unset($appConf['db_host'], $appConf['db_name'], $appConf['app_mode']);
                file_put_contents($appConfigPath, Yaml::dump($appConf, 10, 2));
                $logger->info("Global config updated successfully");
            } else {
                // GENERIC CHANNEL UPDATE (MODULAR)
                $availableChannels = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::getAvailableChannels();
                $channels = array_filter($availableChannels, function ($c) use ($type) {
                    $cNorm = str_replace('-', '_', $c);
                    $typeNorm = str_replace('-', '_', $type);

                    return str_contains($cNorm, $typeNorm) || $cNorm === $typeNorm;
                });

                if (empty($channels) && $type !== 'all') {
                    $channels = [$type];
                }

                foreach ($channels as $chan) {
                    try {
                        $driver = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::get($chan);
                        $attrs = $this->getChannelAttributes($chan);

                        // 1. Update Common Config if applicable
                        if ($attrs['common'] && isset($data['cache_chunk_size'])) {
                            $commonPath = dirname($attrs['path']) . "/" . $attrs['common'] . ".yaml";
                            $commonConfig = file_exists($commonPath) ? (Yaml::parseFile($commonPath) ?: []) : [];
                            $commonConfig['channels'][$attrs['common']]['cache_chunk_size'] = $data['cache_chunk_size'];
                            file_put_contents($commonPath, Yaml::dump($commonConfig, 10, 2));
                        }

                        // 2. Delegate YAML processing to Driver
                        $currentConfig = file_exists($attrs['path']) ? (Yaml::parseFile($attrs['path']) ?: []) : [];
                        $updatedConfig = $driver->updateConfiguration($data, $currentConfig);
                        file_put_contents($attrs['path'], Yaml::dump($updatedConfig, 10, 2));

                        // 2b. Auto-enable infrastructure rule if channel is enabled
                        // (Now handled implicitly by the enabled flag in the same file)

                        // 3. Optional: Database Provisioning (Monolith concern)
                        $this->syncAssetsToDatabase($chan, $updatedConfig, $logger);

                        $logger->info("Config updated successfully for channel: " . $chan);
                    } catch (Exception $e) {
                        $logger->error("Error updating channel {$chan}: " . $e->getMessage());
                        if (count($channels) === 1) {
                            throw $e;
                        }
                    }
                }
            }

            $logger->info("Config updated successfully for type: " . $type);

            // Reset cached configs within the current process (critical for Swoole/Long-lived environments)
            Helpers::resetConfigs();

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
            $type = $data['type'] ?? 'all';
            $logger->info("Validation request received for type: " . $type, ['payload' => $data]);
            $availableChannels = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::getAvailableChannels();

            $results = [];

            if ($type === 'all') {
                $channelsToValidate = $availableChannels;
            } else {
                $channelsToValidate = array_filter($availableChannels, fn ($c) => str_contains($c, $type) || $c === $type);
                if (empty($channelsToValidate)) {
                    $channelsToValidate = [$type];
                }
            }

            $allConfigs = \Helpers\Helpers::getChannelsConfig();
            $providerResults = [];

            foreach ($channelsToValidate as $chan) {
                try {
                    // Identify the provider (commonKey)
                    $registryConfig = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::getChannelConfig($chan);
                    $driverClass = $registryConfig['driver'] ?? null;
                    $commonKey = ($driverClass && class_exists($driverClass)) ? $driverClass::getCommonConfigKey() : $chan;

                    // If we already have a result for this provider, reuse it
                    if (isset($providerResults[$commonKey])) {
                        $results[$chan] = $providerResults[$commonKey];

                        continue;
                    }

                    $chanConfig = $allConfigs[$chan] ?? [];
                    if ($driverClass && class_exists($driverClass)) {
                        if ($commonKey && isset($allConfigs[$commonKey])) {
                            $chanConfig = array_replace_recursive($allConfigs[$commonKey], $chanConfig);
                        }
                    }

                    $driver = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::get($chan, $logger, $chanConfig);
                    $validation = $driver->validateAuthentication();

                    $result = [
                        'status' => $validation['success'] ? 'valid' : 'error',
                        'message' => $validation['message'],
                        'details' => $validation['details'] ?? [],
                    ];

                    // Store result for this provider to avoid re-validation
                    $providerResults[$commonKey] = $result;
                    $results[$chan] = $result;

                } catch (Exception $e) {
                    $results[$chan] = ['status' => 'error', 'message' => $e->getMessage()];
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

            if (! $secretToken || $providedToken !== $secretToken) {
                return new Response(json_encode(['error' => 'Unauthorized']), 401, ['Content-Type' => 'application/json']);
            }

            $configs = [];
            $allChannels = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::getAvailableChannels();
            $configDir = getenv('CONFIG_DIR') ?: __DIR__ . '/../../config';

            foreach ($allChannels as $chan) {
                $path = $this->getChannelAttributes($chan)['path'];
                $common = $this->getChannelAttributes($chan)['common'];
                if (file_exists($path)) {
                    $configs[basename($path)] = file_get_contents($path);
                }
                if ($common && file_exists($configDir . "/channels/{$common}.yaml")) {
                    $configs["{$common}.yaml"] = file_get_contents($configDir . "/channels/{$common}.yaml");
                }
            }
            $configs['instances_rules.yaml'] = file_exists($configDir . '/instances_rules.yaml') ? file_get_contents($configDir . '/instances_rules.yaml') : null;

            return new Response(json_encode(['success' => true, 'configs' => $configs]), 200, ['Content-Type' => 'application/json']);
        } catch (Exception $e) {
            return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
        }
    }

    private function syncAssetsToDatabase(string $channel, array $config, $logger): void
    {
        $logger->info("DEBUG: syncAssetsToDatabase START for $channel");

        try {
            $driver = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::get($channel);
            $patterns = $driver->getAssetPatterns();
            $logger->info("DEBUG: Patterns loaded: " . implode(', ', array_keys($patterns)));
            // Read channel config directly from disk (already saved before this method is called).
            // Do NOT call resetConfigs() here — it nulls the EntityManager and corrupts the persistence flow.
            $configDir = getenv('CONFIG_DIR') ?: __DIR__ . '/../../config';
            $chanYaml = $configDir . '/channels/' . $channel . '.yaml';
            $rawConfig = file_exists($chanYaml) ? (\Symfony\Component\Yaml\Yaml::parseFile($chanYaml) ?: []) : [];
            $chanConfig = $rawConfig['channels'][$channel] ?? $rawConfig;
            $logger->info("DEBUG: Config loaded from disk for $channel");

            // 1. Get Channel Entity (Fast lookup)
            $channelEntity = $this->em->getRepository(Channel::class)->findOneBy(['name' => $channel]);
            if (! $channelEntity) {
                $logger->warning("Unknown channel encountered during database sync: $channel");

                return;
            }

            // 2. Bulk Load ALL ChanneledAccounts for this channel to avoid N+1 queries
            $existingEntities = $this->em->getRepository(ChanneledAccount::class)->findBy(['channel' => $channelEntity->getId()]);
            $entitiesMap = [];
            foreach ($existingEntities as $e) {
                $entitiesMap[(string)$e->getPlatformId()] = $e;
            }

            // 3. Identify common account group
            $commonKey = $driver::getCommonConfigKey();
            $defaultGroupName = method_exists($driver, 'getChannelLabel') ? $driver::getChannelLabel() : "Default Group";
            $groupName = $chanConfig['accounts_group_name'] ?? ($rawConfig['channels'][$commonKey]['accounts_group_name'] ?? $defaultGroupName);
            $logger->info("DEBUG: Resolving account group: $groupName");
            $accountEntity = $this->getOrCreateAccount($groupName);
            $logger->info("DEBUG: Account entity resolved: " . $accountEntity->getName());

            foreach ($patterns as $assetKey => $pattern) {
                $configKey = $pattern['key'] ?? $assetKey;
                $assets = $chanConfig[$configKey] ?? ($chanConfig['assets'][$configKey] ?? []);
                if (empty($assets)) {
                    continue;
                }
                $typeMark = $pattern['type'] ?? null;
                if (! $typeMark) {
                    continue;
                }
                foreach ($assets as $asset) {
                    $idValue = (string)($asset['id'] ?? ($asset['url'] ?? ''));
                    $urlValue = (string)($asset['url'] ?? '');
                    
                    // Platform ID: MD5 only for URLs or GSC domain properties. Raw for IDs (like act_...)
                    $isUrl = (str_contains($idValue, '://') || str_contains($idValue, '.') || str_contains($idValue, 'sc-domain:'));
                    $platformId = ($isUrl && !is_numeric($idValue)) ? md5(rtrim($idValue, '/')) : $idValue;

                    $name = $asset['name'] ?? $asset['title'] ?? ("Asset " . $idValue);
                    $dbChanneled = $entitiesMap[$platformId] ?? null;

                    if (! $dbChanneled) {
                        $dbChanneled = new ChanneledAccount();
                        $dbChanneled->addPlatformId($platformId)
                            ->addAccount($accountEntity)
                            ->addType($typeMark)
                            ->addChannel($channelEntity)
                            ->addName($name)
                            ->addPlatformCreatedAt(isset($asset['created_at']) ? new \DateTime($asset['created_at']) : null)
                            ->addData([]);
                        $this->em->persist($dbChanneled);
                        $entitiesMap[$platformId] = $dbChanneled;
                    } else {
                        $dbChanneled->addAccount($accountEntity);
                        if ($dbChanneled->getName() !== $name) {
                            $dbChanneled->addName($name);
                        }
                    }

                    // Prepare for Page processing: Specific logic for each type
                    $targetsForPages = [];
                    $isPage = (
                        str_contains($urlValue, '://') || 
                        str_contains($urlValue, 'sc-domain:') || 
                        ($pattern['type'] ?? '') === 'facebook_page' || 
                        ($pattern['type'] ?? '') === 'instagram_account'
                    );

                    if ($isPage && ($pattern['type'] ?? '') !== 'facebook_ad_account') {
                        $hostname = $asset['hostname'] ?? null;
                        if (!$hostname && $urlValue) {
                            if (str_contains($urlValue, 'sc-domain:')) {
                                $hostname = str_replace('sc-domain:', '', $urlValue);
                            } else {
                                $hostname = parse_url($urlValue, PHP_URL_HOST);
                            }
                        }

                        // GSC has a double prefix in your DB (site:domain:sc-domain:...)
                        $prefix = $pattern['prefix'] ?? 'site:domain';
                        $pageSuffix = $hostname ?? $platformId;
                        if (str_contains($urlValue, 'sc-domain:')) {
                            $pageSuffix = $urlValue;
                        }

                        $targetsForPages[] = [
                            'pId' => $platformId,
                            'name' => $name,
                            'url' => $urlValue,
                            'prefix' => $prefix,
                            'suffix' => $pageSuffix,
                            'hostname' => $hostname
                        ];
                    }

                    // Nested children (Instagram, etc)
                    if (isset($pattern['children'])) {
                        foreach ($pattern['children'] as $childPattern) {
                            $childIdRaw = (string)($asset[$childPattern['id_key']] ?? '');
                            if (! $childIdRaw) {
                                continue;
                            }

                            $childPlatformId = is_numeric($childIdRaw) ? $childIdRaw : md5(rtrim($childIdRaw, '/'));
                            $childName = $asset[$childPattern['name_key']] ?? $name;
                            $childType = $childPattern['type'];

                            $dbChild = $entitiesMap[$childPlatformId] ?? null;
                            if (! $dbChild) {
                                $dbChild = new ChanneledAccount();
                                $dbChild->addPlatformId($childPlatformId)
                                    ->addAccount($accountEntity)
                                    ->addType($childType)
                                    ->addChannel($channelEntity)
                                    ->addName($childName)
                                    ->addPlatformCreatedAt(isset($asset['created_at']) ? new \DateTime($asset['created_at']) : null)
                                    ->addData([]);
                                $this->em->persist($dbChild);
                                $entitiesMap[$childPlatformId] = $dbChild;
                            } else {
                                $dbChild->addAccount($accountEntity);
                                if ($dbChild->getName() !== $childName) {
                                    $dbChild->addName($childName);
                                }
                            }

                            // If child pattern defines a prefix, it gets a Page record
                            if (isset($childPattern['prefix'])) {
                                $targetsForPages[] = [
                                    'pId' => $childPlatformId,
                                    'name' => $childName,
                                    'url' => null,
                                    'prefix' => $childPattern['prefix'],
                                    'suffix' => $childPattern['hostname'] ?? $childPlatformId,
                                    'hostname' => $childPattern['hostname'] ?? null,
                                ];
                            }
                        }
                    }

                    // Persist Pages (Using site:domain:suffix convention)
                    foreach ($targetsForPages as $target) {
                        $canonicalId = "{$target['prefix']}:{$target['suffix']}";
                        $dbPage = $this->em->getRepository(\Entities\Analytics\Page::class)->findOneBy(['canonicalId' => $canonicalId]);

                        $pageUrl = (string)$target['url'];
                        if (is_numeric($pageUrl) || empty($pageUrl)) {
                            $pageUrl = (string)$target['pId'];
                        }

                        if (! $dbPage) {
                            $dbPage = new \Entities\Analytics\Page();
                            $dbPage->addCanonicalId($canonicalId);
                        }
                        
                        $dbPage->addUrl($pageUrl)
                                ->addTitle($target['name'])
                                ->addAccount($accountEntity)
                                ->addPlatformId($target['pId'])
                                ->addHostname($target['hostname'] ?? null)
                                ->addData([]);
                        
                        $this->em->persist($dbPage);
                    }
                }
            }
            $logger->info("DEBUG: Attempting final flush for $channel");
            $this->em->flush();
            $logger->info("DEBUG: syncAssetsToDatabase FINISHED for $channel");
        } catch (Exception $e) {
            $logger->error("Sync Assets Error for $channel: " . $e->getMessage());
        }
    }

    public function flushCache(Request $request): Response
    {
        $channel = $request->request->get('channel');
        if (! $channel) {
            $content = json_decode($request->getContent(), true);
            $channel = $content['channel'] ?? null;
        }

        if (! $channel) {
            return new Response(json_encode(['error' => 'Missing channel']), 400);
        }

        CacheStrategyService::clearChannel($channel);

        return new Response(json_encode([
            'success' => true,
            'message' => "All aggregation caches for '$channel' cleared.",
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
            $output = [];
            exec('crontab -l 2>/dev/null', $output);
            foreach ($output as $line) {
                if (empty($line) || str_starts_with($line, '#') || ! str_contains($line, 'apis-hub:cache')) {
                    continue;
                }
                $parts = preg_split('/\s+/', $line);
                if (count($parts) < 5) {
                    continue;
                }
                $minute = $parts[0];
                $hour = $parts[1];
                $key = 'unknown';
                if (preg_match('/instance_name=([^&"\s]+)/', $line, $matches)) {
                    $key = $matches[1];
                } elseif (preg_match('/apis-hub:cache\s+"([^"]+)"\s+"([^"]+)"/', $line, $matches)) {
                    $key = $matches[1] . '_' . $matches[2];
                }
                $schedules[$key] = ['minute' => $minute, 'hour' => $hour, 'time' => "$minute $hour"];
            }
        } catch (\Throwable $e) {
        }

        return $schedules;
    }

    private function getOrCreateAccount(string $name): Account
    {
        $accountEntity = $this->em->getRepository(Account::class)->findOneBy(['name' => $name]);
        if (! $accountEntity) {
            $accountEntity = new Account();
            $accountEntity->addName($name);
            $this->em->persist($accountEntity);
            $this->em->flush();
        }

        return $accountEntity;
    }
}
