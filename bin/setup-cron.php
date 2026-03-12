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

$projectName = $argv[1] ?? null;
if (!$projectName) {
    fwrite(STDERR, "Usage: php bin/setup-cron.php <project-name>\n");
    exit(1);
}

$projectFile = __DIR__ . "/../deploy/{$projectName}.yaml";
if (!file_exists($projectFile)) {
    fwrite(STDERR, "Project file not found: {$projectFile}\n");
    exit(1);
}

$config = Yaml::parseFile($projectFile);
$instances = $config['instances'] ?? [];

// Capture relevant environment variables to pass to Cron
$envVars = [
    'PATH' => '/usr/local/bin:/usr/bin:/bin',
    'SHELL' => '/bin/bash'
];

$keep = ['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASSWORD', 'DB_NAME', 'DB_DRIVER', 'PROJECT_CONFIG_FILE', 'APP_ENV', 'REDIS_HOST', 'REDIS_PORT', 'API_SOURCE', 'API_ENTITY', 'START_DATE', 'END_DATE'];
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

    // Only schedule if it matches current container instance name
    if (!$instanceFilter || $instanceName !== $instanceFilter) {
        continue;
    }

    $params = [
        'instance_name' => $instanceName
    ];
    if (!empty($instance['start_date'])) $params['startDate'] = $instance['start_date'];
    if (!empty($instance['end_date'])) $params['endDate'] = $instance['end_date'];
    if (!empty($instance['requires'])) $params['requires'] = $instance['requires'];
    
    $paramString = "";
    if (!empty($params)) {
        $paramString = ' --params="' . http_build_query($params) . '"';
    }

    // Command to schedule the job in the database
    $cronLines[] = "{$frequency} root cd /app && /usr/local/bin/php bin/cli.php apis-hub:cache \"{$channel}\" \"{$entity}\"{$paramString} >> /app/logs/cron.log 2>&1";
}

// Also add the job processor cron (runs every minute)
$cronLines[] = "* * * * * root cd /app && /usr/local/bin/php bin/cli.php jobs:process >> /app/logs/jobs.log 2>&1";

$cronFile = '/etc/cron.d/apis-hub-cron';
file_put_contents($cronFile, implode("\n", $cronLines) . "\n");
chmod($cronFile, 0644);

if (Helpers::isDebug()) {
    echo "✔ Cron configuration generated from {$projectName}.yaml\n";
    foreach ($cronLines as $line) {
        echo "  Applied: {$line}\n";
    }
}
