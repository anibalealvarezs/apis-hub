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
        
        $projectConfig = \Helpers\Helpers::getProjectConfig();
        $channels = $projectConfig['rules'] ?? [];

        if (empty($channels)) {
            throw new Exception("No instance generation rules found in configuration (instances_rules.yaml)");
        }

        $currentPort = $basePort;

        foreach ($channels as $channel => $rules) {
            if (isset($rules['enabled']) && !$rules['enabled']) {
                continue;
            }
            $channelInstances = [];
            
            // 1. Entities Sync (if applicable)
            if ($rules['entities_sync']) {
                $instanceName = str_replace('_', '-', $channel) . '-entities-sync';
                $channelInstances[] = [
                    'name' => $instanceName,
                    'port' => $currentPort++,
                    'channel' => $channel,
                    'entity' => $rules['entities_sync'],
                    'frequency' => sprintf('%d %d * * *', ($rules['recent_cron_minute'] + 5) % 60, $rules['recent_cron_hour']) // Just an example, maybe offset from recent
                ];
            }

            // 2. Historics (Quarters)
            $historyStart = $today->modify("-{$rules['history_months']} months");
            $tempStart = $historyStart;
            
            while ($tempStart < $yesterday) {
                // Calculate end of quarter or just before yesterday
                $quarterEnd = $this->getEndOfQuarter($tempStart);
                if ($quarterEnd >= $yesterday) {
                    $quarterEnd = $yesterday;
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
                // The order should be: Entities Sync -> Historics (Oldest to Newest) -> Recent
                // To avoid overlap, we link them in reverse order of your requirement? 
                // "A partir del segundo job en la cola por canal, se debe asignar el job anterior como dependencia"
                for ($i = 1; $i < count($channelInstances); $i++) {
                    $channelInstances[$i]['requires'] = $channelInstances[$i - 1]['name'];
                }
            }

            $instances = array_merge($instances, $channelInstances);
        }

        return $instances;
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
