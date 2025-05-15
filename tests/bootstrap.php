<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$configFile = getenv('CONFIG_FILE') ?: __DIR__ . '/../config/config.yaml';

if (!file_exists($configFile)) {
    echo "⚠️  Config file not found: $configFile\n";
    echo "👉  Please copy config_example.yaml to config.yaml and fill in your credentials.\n";
    exit(1);
}

$GLOBALS['app_config'] = Yaml::parseFile($configFile);

function app_config(string $key = null, $default = null)
{
    $config = $GLOBALS['app_config'] ?? [];
    if ($key === null) {
        return $config;
    }

    return $config[$key] ?? $default;
}
