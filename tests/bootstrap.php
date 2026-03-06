<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

// ─── Config resolution ────────────────────────────────────────────────────────
// Priority:
//   1. CONFIG_FILE env var pointing to a YAML file
//   2. Default config/config.yaml (if it exists)
//   3. Individual environment variables — for CI / deployments without a file
//
// Note: channel credentials (Google, Facebook, etc.) are NOT sourced from
// app_config. They come from CHANNELS_CONFIG env var or channelsconfig.yaml,
// handled entirely by Helpers\Helpers::getChannelsConfig().
//
// app_config is only used here for test-suite infrastructure (DB, etc.) and
// for any future test-time overrides the test suite may need.
// ─────────────────────────────────────────────────────────────────────────────

$rawConfigFile = getenv('CONFIG_FILE');
$projectFile   = getenv('PROJECT_CONFIG_FILE');
$configFile    = ($rawConfigFile !== false && $rawConfigFile !== '')
    ? $rawConfigFile
    : __DIR__ . '/../deploy/project.yaml';

$GLOBALS['app_config'] = [];

if (file_exists($configFile)) {
    $GLOBALS['app_config'] = Yaml::parseFile($configFile);
}

// Merge project level config if it exists
if ($projectFile && file_exists($projectFile)) {
    $projectConfig = Yaml::parseFile($projectFile);
    if (is_array($projectConfig)) {
        $GLOBALS['app_config'] = array_replace_recursive($GLOBALS['app_config'], $projectConfig);
    }
}

if (!file_exists($configFile) && (!$projectFile || !file_exists($projectFile))) {
    echo "ℹ️  No configuration source found (CONFIG_FILE or PROJECT_CONFIG_FILE).\n";
    echo "   Test infrastructure (DB, Redis) will be resolved from TEST_DB_* / REDIS_* env vars.\n";
    echo "   Channel credentials (Google, Facebook, …) come from CHANNELS_CONFIG env var.\n\n";
}

// ─── Helper ───────────────────────────────────────────────────────────────────

/**
 * Retrieve a value from the bootstrap config array.
 *
 * @param string|null $key
 * @param mixed       $default
 * @return mixed
 */
function app_config(string $key = null, mixed $default = null): mixed
{
    $config = $GLOBALS['app_config'] ?? [];
    if ($key === null) {
        return $config;
    }
    return $config[$key] ?? $default;
}
