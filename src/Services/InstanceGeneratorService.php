<?php

namespace Services;

use DateTime;
use DateTimeImmutable;
use Exception;

class InstanceGeneratorService
{
    
    /**
     * @param bool $useDependencies
     * @param int $basePort
     * @return array
     * @throws Exception
     */
    public function generate(bool $useDependencies = true, int $basePort = 8080): array
    {
        $instances = [];
        \Helpers\Helpers::getProjectConfig(); // Asegurar que el entorno está cargado
        $today = new DateTimeImmutable('today');
        $yesterday = $today->modify('-1 day');
        
        if (getenv('APP_ENV') === 'demo' || \Helpers\Helpers::isDemo()) {
            return [[
                'name' => 'demo-entities-sync',
                'port' => $basePort,
                'channel' => 'none',
                'entity' => 'none',
                'frequency' => '0 0 * * *'
            ]];
        }

        $projectConfig = \Helpers\Helpers::getProjectConfig();
        $overrides = $projectConfig['rules'] ?? [];
        $registry = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::getRegistry();

        $currentPort = $basePort;

        foreach ($registry as $channelName => $config) {
            $driverClass = $config['driver'] ?? null;
            if (!$driverClass || !class_exists($driverClass)) {
                continue;
            }

            // Get default rules from driver
            $rules = [];
            if (method_exists($driverClass, 'getInstanceRules')) {
                $rules = $driverClass::getInstanceRules();
            }

            // Merge with local overrides
            if (!isset($overrides[$channelName]) || (isset($overrides[$channelName]['enabled']) && !$overrides[$channelName]['enabled'])) {
                continue;
            }
            $rules = array_merge($rules, $overrides[$channelName]);

            if (empty($rules)) {
                continue;
            }

            $channel = $channelName;
            $channelInstances = [];
            
            // 0. Get Caching Limits for this channel
            $chanConfig = [];
            try {
                $chanConfig = \Classes\DriverInitializer::validateConfig($channel);
            } catch (Exception $e) { /* ignore missing drivers for now */ }

            $limitDate = null;
            if (!empty($chanConfig['cache_history_range'])) {
                try {
                    $limitDate = $today->modify('-' . $chanConfig['cache_history_range']);
                } catch (Exception $e) { /* ignore malformed ranges */ }
            }

            // 1. Entities Sync (if applicable)
            $entitiesSyncValue = $rules['entities_sync'] ?? null;
            if ($entitiesSyncValue) {
                $instanceName = str_replace('_', '-', $channel) . '-entities-sync';
                $channelInstances[] = [
                    'name' => $instanceName,
                    'port' => $currentPort++,
                    'channel' => $channel,
                    'entity' => $entitiesSyncValue,
                    'frequency' => '0 2 * * *'
                ];
            }

            // 2. Historics and Recent (always created if channel is enabled)
            // 2. Historics (Quarters)
            $historyMonths = $rules['history_months'] ?? 1;
            $historyStart = $today->modify("-{$historyMonths} months");
            
            // Limit history start by cache history range if stricter
            if ($limitDate && $historyStart < $limitDate) {
                $historyStart = $limitDate;
            }

                $tempStart = $historyStart;
                
                while ($tempStart < $yesterday) {
                    // Calculate end of quarter or just before yesterday
                    $quarterEnd = $this->getEndOfQuarter($tempStart);
                    if ($quarterEnd >= $yesterday) {
                        $quarterEnd = $yesterday;
                    }

                    // If the entire quarter is before the limit date, skip it
                    if ($limitDate && $quarterEnd < $limitDate) {
                        $tempStart = $quarterEnd->modify('+1 day');
                        if ($tempStart >= $yesterday) break;
                        continue;
                    }

                    $year = $tempStart->format('Y');
                    $quarterNumber = ceil($tempStart->format('n') / 3);
                    $instanceName = sprintf('%s-%s-%s', str_replace('_', '-', $channel), $year, $quarterNumber);

                    $channelInstances[] = [
                        'name' => $instanceName,
                        'port' => $currentPort++,
                        'channel' => $channel,
                        'entity' => 'metric',
                        'start_date' => $tempStart->format('Y-m-d'),
                        'end_date' => $quarterEnd->format('Y-m-d')
                    ];

                    $tempStart = $quarterEnd->modify('+1 day');
                    if ($tempStart >= $yesterday) break;
                }

                // 3. Recent Instance
                $recentName = str_replace('_', '-', $channel) . '-recent';
                $channelInstances[] = [
                    'name' => $recentName,
                    'port' => $currentPort++,
                    'channel' => $channel,
                    'entity' => 'metric',
                    'start_date' => '-3 days',
                    'end_date' => 'yesterday',
                    'frequency' => sprintf('%d %d * * *', $rules['recent_cron_minute'], $rules['recent_cron_hour'])
                ];

            // 4. Handle dependencies (Optional)
            if ($useDependencies) {
                for ($i = 1; $i < count($channelInstances); $i++) {
                    // Recent jobs should run independently to avoid blocking daily updates
                    if (str_ends_with($channelInstances[$i]['name'], '-recent')) {
                        continue;
                    }
                    $channelInstances[$i]['requires'] = $channelInstances[$i - 1]['name'];
                }
            }

            $instances = array_merge($instances, $channelInstances);
        }

        return $instances;
    }

    /**
     * @param string $channelName
     * @return bool
     */
    private function hasActiveEntities(string $channelName): bool
    {
        $chanKey = $channelName;

        $registryConfig = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::getChannelConfig($chanKey);
        $resourceKey = $registryConfig['resource_key'] ?? null;

        // If we don't have a resource key for entity-level validation, 
        // we default to true to allow standard processing based on top-level rules.
        if (!$resourceKey) {
            return true;
        }

        try {
            $config = \Classes\DriverInitializer::validateConfig($chanKey);
        } catch (Exception $e) {
            return false;
        }

        // If the configuration for the channel is missing or explicitly disabled
        if (!$config || (isset($config['enabled']) && !$config['enabled'])) {
            return false;
        }

        // If cache_all is enabled, the channel is considered active even with an empty entity list
        if (isset($config['cache_all']) && $config['cache_all']) {
            return true;
        }

        // Check the specific list of entities
        $entities = $config[$resourceKey] ?? [];
        if (empty($entities)) {
            return false;
        }

        // Search for at least one entity that is enabled: true
        foreach ($entities as $entity) {
            if (isset($entity['enabled']) && $entity['enabled']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param DateTimeImmutable $date
     * @return DateTimeImmutable
     */
    private function getEndOfQuarter(DateTimeImmutable $date): DateTimeImmutable
    {
        $month = (int)$date->format('n');
        $year = (int)$date->format('Y');
        
        $currentQuarter = (int)ceil($month / 3);
        $endMonth = $currentQuarter * 3;
        
        return $date->setDate($year, $endMonth, 1)->modify('last day of this month');
    }
}
