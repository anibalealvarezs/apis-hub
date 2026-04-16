<?php
/**
 * apis-hub Cron Setup Tool
 *
 * Usage:
 *   php bin/setup-cron.php <project-name>
 *
 * Reads:  deploy/<project-name>.yaml
 * writes: /etc/cron.d/apis-hub-cron
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Helpers\Helpers;

$config = Helpers::getProjectConfig();
$instances = $config['instances'] ?? [];

// Capture relevant environment variables to pass to Cron
$envVars = [
    'PATH' => '/usr/local/bin:/usr/bin:/bin',
    'SHELL' => '/bin/bash'
];

$keep = ['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASSWORD', 'DB_NAME', 'DB_DRIVER', 'PROJECT_CONFIG_FILE', 'APP_ENV', 'REDIS_HOST', 'REDIS_PORT', 'API_SOURCE', 'API_ENTITY', 'START_DATE', 'END_DATE', 'INSTANCE_NAME', 'CONFIG_DIR'];
foreach ($keep as $key) {
    if ($val = getenv($key)) {
        $envVars[$key] = $val;
    }
}

$cronLines = [];
foreach ($envVars as $k => $v) {
    $cronLines[] = "{$k}=\"{$v}\"";
}

$instanceFilter = getenv('INSTANCE_NAME');

foreach ($instances as $instance) {
    $instanceName = $instance['name'] ?? null;
    $channel = $instance['channel'] ?? null;
    $entity = $instance['entity'] ?? null;
    $frequency = $instance['frequency'] ?? null;
    
    if (!$channel || !$entity || !$frequency) continue;
    
    $params = [
        'instance_name' => $instanceName
    ];

    $startDate = $instance['start_date'] ?? null;
    $endDate = $instance['end_date'] ?? null;

    // Apply specific Dawn Re-sync rules if dates are missing in project.yaml
    if (empty($startDate)) {
        if ($channel === 'facebook_organic') {
            // Rule 2: Organic (Entities & Metrics) resync -> 3 days
            $startDate = '-3 days';
            if (empty($endDate)) $endDate = 'yesterday';
        } elseif ($channel === 'facebook_marketing') {
            if ($entity === 'metric') {
                // Rule 4: Marketing Metrics resync -> 3 days
                $startDate = '-3 days';
                if (empty($endDate)) $endDate = 'yesterday';
            }
            // Rule 3: Marketing Entities -> Stay empty to use full sync_window from code
        }
    }

    if (!empty($startDate)) $params['startDate'] = $startDate;
    if (!empty($endDate)) $params['endDate'] = $endDate;
    if (!empty($instance['requires'])) $params['requires'] = $instance['requires'];

    // --- 🕒 Cron Time Override Logic (Hour & Minute) ---
    $channelConfig = $config['channels'][$channel] ?? [];
    $overrideHour = null;
    $overrideMinute = null;
    
    // Determine if this is an "entities" or "recent" job based on instance name
    if (str_contains($instanceName, 'entities')) {
        $overrideHour = $channelConfig['cron_entities_hour'] ?? ($config['cron']['entities_hour'] ?? null);
        $overrideMinute = $channelConfig['cron_entities_minute'] ?? ($config['cron']['entities_minute'] ?? null);
    } elseif (str_contains($instanceName, 'recent')) {
        $overrideHour = $channelConfig['cron_recent_hour'] ?? ($config['cron']['recent_hour'] ?? null);
        $overrideMinute = $channelConfig['cron_recent_minute'] ?? ($config['cron']['recent_minute'] ?? null);
    }

    if ($overrideHour !== null) {
        $freqParts = explode(' ', $frequency);
        if (count($freqParts) >= 2) {
            $freqParts[1] = $overrideHour;
            // Also override minute if defined
            if ($overrideMinute !== null) {
                $freqParts[0] = $overrideMinute;
            }
            $frequency = implode(' ', $freqParts);
        }
    }

    $paramString = "";
    if (!empty($params)) {
        $paramString = ' --params="' . http_build_query($params) . '"';
    }

    // Command to schedule the job in the database
    $cronLines[] = "{$frequency} cd /app && /usr/local/bin/php bin/cli.php apis-hub:cache \"{$channel}\" \"{$entity}\"{$paramString} > /dev/null 2>&1";
}

// Also add the job processor cron (runs every minute)
$cronLines[] = "* * * * * cd /app && /usr/local/bin/php bin/cli.php jobs:process > /dev/null 2>&1";

// Add the scale-down cron (runs every 15 minutes)
$cronLines[] = "*/15 * * * * cd /app && /usr/local/bin/php bin/cli.php app:scale-down > /dev/null 2>&1";


$cronFile = '/tmp/apis-hub-cron';
file_put_contents($cronFile, implode("\n", $cronLines) . "\n");
chmod($cronFile, 0644);

if (Helpers::isDebug()) {
    $projectName = $config['project'] ?? 'apis-hub';
    echo "✔ Cron configuration generated for project: {$projectName}\n";
    foreach ($cronLines as $line) {
        echo "  Applied: {$line}\n";
    }
}
