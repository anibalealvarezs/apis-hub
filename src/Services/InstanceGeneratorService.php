<?php

namespace Services;

use DateTime;
use DateTimeImmutable;
use Exception;

class InstanceGeneratorService
{
    private const FB_CHANNELS = ['facebook_marketing', 'facebook_organic'];
    private const GSC_CHANNELS = ['gsc'];
    
    /**
     * @param bool $useDependencies
     * @param int $basePort
     * @return array
     * @throws Exception
     */
    public function generate(bool $useDependencies = true, int $basePort = 8080): array
    {
        $instances = [];
        $today = new DateTimeImmutable('today');
        $yesterday = $today->modify('-1 day');
        
        if (getenv('APP_ENV') === 'demo') {
            return [[
                'name' => 'demo-entities-sync',
                'port' => $basePort,
                'channel' => 'none',
                'entity' => 'none',
                'frequency' => '0 0 * * *'
            ]];
        }

        $projectConfig = \Helpers\Helpers::getProjectConfig();
        $channels = $projectConfig['rules'] ?? [];

        if (empty($channels)) {
            throw new Exception("No instance generation rules found in configuration (instances_rules.yaml)");
        }

        $currentPort = $basePort;

        foreach ($channels as $channelName => $rules) {
            if (isset($rules['enabled']) && !$rules['enabled']) {
                continue;
            }

            if (!$this->hasActiveEntities($channelName)) {
                continue;
            }

            $channel = $channelName;
            
            $channelInstances = [];
            
            // 0. Get Caching Limits for this channel
            $channelsConfig = \Helpers\Helpers::getChannelsConfig();
            $chanKey = $channel;
            if (!isset($channelsConfig[$chanKey])) {
                $chanKey = str_replace(['facebook_marketing', 'facebook_organic'], ['facebook', 'facebook'], $channel);
            }
            $chanConfig = $channelsConfig[$channel] ?? $channelsConfig[$chanKey] ?? null;
            $limitDate = null;
            if ($chanConfig && !empty($chanConfig['cache_history_range'])) {
                try {
                    $limitDate = $today->modify('-' . $chanConfig['cache_history_range']);
                } catch (\Exception $e) { /* ignore malformed ranges */ }
            }

            // 1. Entities Sync (if applicable)
            if ($rules['entities_sync']) {
                $instanceName = str_replace('_', '-', $channel) . '-entities-sync';
                $channelInstances[] = [
                    'name' => $instanceName,
                    'port' => $currentPort++,
                    'channel' => $channel,
                    'entity' => $rules['entities_sync'],
                    'frequency' => sprintf('0 %d * * *', $rules['recent_cron_hour'])
                ];
            }

            // 2. Historics (Quarters)
            $historyStart = $today->modify("-{$rules['history_months']} months");
            
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
        $channelConfig = \Helpers\Helpers::getChannelsConfig();
        
        // Map rules channel name (from rules section in instances_rules.yaml) 
        // to actual configuration keys and their target entity lists.
        $mapping = [
            'facebook_marketing' => ['channel' => 'facebook_marketing', 'key' => 'ad_accounts'],
            'facebook_organic'   => ['channel' => 'facebook_organic', 'key' => 'pages'],
            'gsc'                => ['channel' => 'google_search_console', 'key' => 'sites'],
            'google_search_console' => ['channel' => 'google_search_console', 'key' => 'sites'],
        ];

        // If we don't have a specific mapping for entity-level validation, 
        // we default to true to allow standard processing based on top-level rules.
        if (!isset($mapping[$channelName])) {
            return true;
        }

        $target = $mapping[$channelName];
        $config = $channelConfig[$target['channel']] ?? null;

        // If the configuration for the channel is missing or explicitly disabled
        if (!$config || (isset($config['enabled']) && !$config['enabled'])) {
            return false;
        }

        // If cache_all is enabled, the channel is considered active even with an empty entity list
        if (isset($config['cache_all']) && $config['cache_all']) {
            return true;
        }

        // Check the specific list of entities (pages, ad_accounts, or sites)
        $entities = $config[$target['key']] ?? [];
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
