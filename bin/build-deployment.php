#!/usr/bin/env php
<?php
/**
 * apis-hub deployment builder (Standardized Master Architecture)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Helpers\Helpers;

// ─── Load Configuration ───────────────────────────────────────────────────────
$config = Helpers::getProjectConfig();

// ─── Validate required sections ───────────────────────────────────────────────
foreach (['database', 'instances', 'channels'] as $required) {
    if (empty($config[$required])) {
        fwrite(STDERR, "Missing required section '{$required}' in your config/ directory.\n");
        exit(1);
    }
}

$env             = getenv('APP_ENV') ?: 'testing';
$dbConfig        = $config['database'];
$db              = $dbConfig[$env] ?? array_shift($dbConfig);
$redis           = $config['redis'] ?? ['host' => 'redis', 'port' => 6379];
$instances       = $config['instances'];
$projectLabel    = $config['project'] ?? 'apis-hub';
$deploymentName  = getenv('DEPLOYMENT_NAME') ?: 'apis-hub';

echo "⚒  Building standardized Master/Worker deployment for: " . strtoupper($env) . "\n";

$services = [];

// Helper to build environment block
$buildEnv = function($instanceName, $channel = 'none', $entity = 'none') use ($db, $redis, $config, $env) {
    return [
        "PORT=8080",
        "API_SOURCE={$channel}",
        "API_ENTITY={$entity}",
        "DB_DRIVER=\${DB_DRIVER:-" . ($db['driver'] ?? 'pdo_pgsql') . "}",
        "DB_HOST=\${DB_HOST:-" . (($env === 'production' || $env === 'testing') ? 'db' : ($db['host'] ?? 'db')) . "}",
        "DB_PORT=\${DB_PORT:-" . ($db['port'] ?? 5432) . "}",
        "DB_USER=\${DB_USER:-" . ($db['user'] ?? 'postgres') . "}",
        "DB_PASSWORD=\${DB_PASSWORD:-" . ($db['password'] ?? '') . "}",
        "DB_NAME=\${DB_NAME:-" . ($db['name'] ?? 'apis-hub') . "}",
        "REDIS_HOST=\${REDIS_HOST:-" . $redis['host'] . "}",
        "REDIS_PORT=\${REDIS_PORT:-" . $redis['port'] . "}",
        "PROJECT_CONFIG_FILE=/app/config/" . ($config['project'] ?? 'apis-hub') . ".yaml",
        "INSTANCE_NAME={$instanceName}",
        "SKIP_SEED=\${SKIP_SEED:-0}",
        "ENV_FILE=\${ENV_FILE:-.env}",
    ];
};

// ─── Phase 1: Create Standardized Master ────────────────────────────────────────
$masterName = "{$deploymentName}-master";
$externalPort = getenv('EXTERNAL_PORT') ?: ($instances[0]['port'] ?? 10000);
$mcpPort = getenv('MCP_PORT') ?: 3000;

$services['master'] = [
    'container_name' => $masterName,
    'build' => [
        'context'    => '.',
        'dockerfile' => 'Dockerfile',
    ],
    'restart'     => 'always',
    'environment' => $buildEnv($masterName),
    'networks'    => ['default', 'apis-hub_gateway'],
    'volumes'     => ['./:/app', '/app/vendor', '/app/mcp-server/node_modules', '/var/run/docker.sock:/var/run/docker.sock'],
    'depends_on'  => ['redis'],
    'extra_hosts' => ['host.docker.internal:host-gateway'],
];

// ─── Phase 2: Create Workers from Instances Configuration ───────────────────────
foreach ($instances as $instance) {
    $name    = $instance['name'];
    $channel = $instance['channel'];
    $entity  = $instance['entity'];

    // Worker configuration (No ports exposed)
    $services[$name] = [
        'container_name' => "{$deploymentName}-{$name}",
        'build'          => [
            'context'    => '.',
            'dockerfile' => 'Dockerfile',
        ],
        'restart'     => 'always',
        'environment' => $buildEnv($name, $channel, $entity),
        'volumes'     => ['./:/app', '/app/vendor', '/app/mcp-server/node_modules'],
        'depends_on'  => ['master', 'redis'],
    ];
}

// ─── Phase 3: Infrastructure (DB & Redis) ────────────────────────────────────────
$dbHost = (($env === 'production' || $env === 'testing') ? 'db' : ($db['host'] ?? 'db'));
if (true) { // Always create DB service in this master/worker architecture
    $dbHostPort = getenv('DB_HOST_PORT') ?: 5432;
    $services['db'] = [
        'container_name' => "{$deploymentName}-db",
        'image'         => ($db['driver'] ?? 'pdo_pgsql') === 'pdo_pgsql' ? 'postgres:16-alpine' : 'mysql:8.0',
        'restart'       => 'always',
        'environment'   => [
            (($db['driver'] ?? 'pdo_pgsql') === 'pdo_pgsql' ? 'POSTGRES_USER' : 'MYSQL_USER') => ($db['user'] ?? 'postgres'),
            (($db['driver'] ?? 'pdo_pgsql') === 'pdo_pgsql' ? 'POSTGRES_PASSWORD' : 'MYSQL_PASSWORD') => ($db['password'] ?? 'postgres'),
            (($db['driver'] ?? 'pdo_pgsql') === 'pdo_pgsql' ? 'POSTGRES_DB' : 'MYSQL_DATABASE') => ($db['name'] ?? 'apis-hub'),
        ],
        'volumes' => ['db_data:/var/lib/postgresql/data'],
    ];
}

$redisHostPort = getenv('REDIS_HOST_PORT') ?: 6379;
$services['redis'] = [
    'container_name' => "{$deploymentName}-redis",
    'image'         => 'redis:alpine',
    'restart'       => 'always',
    'volumes'       => ['redis_data:/data'],
];

// ─── Write docker-compose.yml ──────────────────────────────────────────────────
$dbVolumeName    = getenv('DB_VOLUME_NAME')    ?: "{$deploymentName}-db-data";
$redisVolumeName = getenv('REDIS_VOLUME_NAME') ?: "{$deploymentName}-redis-data";

$compose = [
    'name'     => $deploymentName,
    'services' => $services,
    'networks' => [
        'default' => [
            'name' => "{$deploymentName}_internal",
        ],
        'apis-hub_gateway' => [
            'name' => 'apis-hub_default',
            'external' => true
        ]
    ],
    'volumes'  => [
        'redis_data' => ['name' => $redisVolumeName],
        'db_data'    => ['name' => $dbVolumeName]
    ],
];

file_put_contents(__DIR__ . '/../docker-compose.yml', Yaml::dump($compose, 6, 2, Yaml::DUMP_NULL_AS_TILDE));
echo "✔  Written: docker-compose.yml (Master Architecture Enabled)\n";
