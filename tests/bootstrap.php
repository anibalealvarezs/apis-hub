<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

// ─── Config resolution ────────────────────────────────────────────────────────
$GLOBALS['app_config'] = \Helpers\Helpers::getProjectConfig();

if (empty($GLOBALS['app_config'])) {
    echo "ℹ️  No configuration source found.\n";
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
