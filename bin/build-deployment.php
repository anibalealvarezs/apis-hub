#!/usr/bin/env php
<?php
/**
 * apis-hub deployment builder
 *
 * Usage:
 *   php bin/build-deployment.php <project-name>
 *
 * Reads:  deploy/<project-name>.yaml
 * Writes: docker-compose.yml
 *         config/channelsconfig.env
 *
 * The project YAML is your single source of truth for a deployment:
 * instances, DB credentials, and all channel/API credentials.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

// ─── Load Configuration ───────────────────────────────────────────────────────
use Helpers\Helpers;
$config = Helpers::getProjectConfig();

// ─── Validate required sections ───────────────────────────────────────────────
foreach (['database', 'instances', 'channels'] as $required) {
    if (empty($config[$required])) {
        fwrite(STDERR, "Missing required section '{$required}' in your config/ directory.\n");
        fwrite(STDERR, "Please ensure your YAML files are correctly populated.\n");
        exit(1);
    }
}

$env      = getenv('APP_ENV') ?: 'testing';
$dbConfig = $config['database'];
$db       = $dbConfig[$env] ?? array_shift($dbConfig); // Default to specified env or first block

$redis   = $config['redis'] ?? ['host' => 'redis', 'port' => 6379];
$instances = $config['instances'];
$channels  = $config['channels'];
$projectLabel = $config['project'] ?? 'apis-hub';
echo "⚒  Building deployment for environment: " . strtoupper($env) . "\n";

// ─── Build docker-compose.yml ─────────────────────────────────────────────────
$services = [];
foreach ($instances as $instance) {
    $ports     = [];
    $name      = $instance['name'];
    $port      = $instance['port'] ?? null;
    $channel   = $instance['channel'];
    $entity    = $instance['entity'];
    $startDate = $instance['start_date'] ?? null;
    $endDate   = $instance['end_date']   ?? null;

    $extractEnvVar = function($str) {
        if (!str_contains((string)$str, '${')) return (string)$str;
        return preg_replace('/^\$\{.*:-(.*)\}$/', '$1', (string) $str);
    };

    $envBlock = [
        "PORT=8080",
        "API_SOURCE={$channel}",
        "API_ENTITY={$entity}",
        "DB_DRIVER=" . $extractEnvVar($db['driver'] ?? 'pdo_mysql'),
        "DB_HOST=" . str_replace(['127.0.0.1', 'localhost'], 'host.docker.internal', $extractEnvVar($db['host'] ?? 'host.docker.internal')),
        "DB_PORT=" . $extractEnvVar($db['port'] ?? 3306),
        "DB_USER=" . $extractEnvVar($db['user'] ?? 'root'),
        "DB_PASSWORD=" . $extractEnvVar($db['password'] ?? ''),
        "DB_NAME=" . $extractEnvVar($db['name'] ?? ''),
        "REDIS_HOST=" . $redis['host'],
        "REDIS_PORT=" . $redis['port'],
        "PROJECT_CONFIG_FILE=/app/config/" . ($config['project'] ?? 'apis-hub') . ".yaml",
        "INSTANCE_NAME={$name}",
        "ENV_FILE=" . (getenv('ENV_FILE') ?: '.env'),
    ];

    if ($startDate) {
        $envBlock[] = "START_DATE={$startDate}";
    }
    if ($endDate) {
        $envBlock[] = "END_DATE={$endDate}";
    }

    $serviceConfig = [
        'build' => [
            'context'    => '.',
            'dockerfile' => 'Dockerfile',
        ],
        'environment' => $envBlock,
        'volumes'     => ['./:/app', '/app/vendor'],
        'depends_on'  => ['redis'],
        'extra_hosts' => ['host.docker.internal:host-gateway'],
    ];

    // Gateway pattern: Only map PHP and MCP ports for the first master sync instance found
    if (str_contains($name, 'entities-sync')) {
        if (!isset($gatewayAssigned)) {
            // Map PHP port if defined in config
            if ($port) {
                $ports[] = "{$port}:8080";
                $gatewayAssigned = $name;
            }
            // Map MCP port
            $mcpHostPort = getenv('MCP_PORT') ?: 3000;
            $ports[] = "{$mcpHostPort}:3000";
        }
    }

    if (!empty($ports)) {
        $serviceConfig['ports'] = $ports;
    }

    $services[$name] = $serviceConfig;
}

// ─── Add Database Service if DB_HOST is 'db' ─────────────────────────────────────
$dbHost = $extractEnvVar($db['host'] ?? '');
$dbDriver = $extractEnvVar($db['driver'] ?? 'pdo_mysql');
if (str_contains($dbHost, 'db') && !isset($services['db'])) {
    if ($dbDriver === 'pdo_pgsql') {
        $dbHostPort = getenv('DB_HOST_PORT') ?: 5432;
        $services['db'] = [
            'image' => 'postgres:16-alpine',
            'restart' => 'always',
            'environment' => [
                'POSTGRES_USER' => $extractEnvVar($db['user'] ?? 'postgres'),
                'POSTGRES_PASSWORD' => $extractEnvVar($db['password'] ?? 'postgres'),
                'POSTGRES_DB' => $extractEnvVar($db['name'] ?? 'apis-hub'),
            ],
            'ports' => ["127.0.0.1:{$dbHostPort}:5432"],
            'volumes' => ['db_data:/var/lib/postgresql/data'],
        ];
    } else {
        $dbHostPort = getenv('DB_HOST_PORT') ?: 3306;
        $services['db'] = [
            'image' => 'mysql:8.0',
            'restart' => 'always',
            'environment' => [
                'MYSQL_ROOT_PASSWORD' => $extractEnvVar($db['password'] ?? 'root'),
                'MYSQL_DATABASE' => $extractEnvVar($db['name'] ?? 'apis-hub'),
            ],
            'ports' => ["127.0.0.1:{$dbHostPort}:3306"],
            'volumes' => ['db_data:/var/lib/mysql'],
        ];
    }
}

$redisHostPort = getenv('REDIS_HOST_PORT') ?: 6379;
$services['redis'] = [
    'image'   => 'redis:alpine',
    'restart' => 'always',
    'ports'   => ["{$redisHostPort}:6379"],
    'volumes' => ['redis_data:/data'],
];

$compose = [
    'name'     => getenv('DEPLOYMENT_NAME') ?: 'apis-hub',
    'services' => $services,
    'volumes'  => [
        'redis_data' => null,
        'db_data' => null
    ],
];

$composeYaml = Yaml::dump($compose, 6, 2, Yaml::DUMP_NULL_AS_TILDE);

$composeOut  = __DIR__ . '/../docker-compose.yml';
file_put_contents($composeOut, $composeYaml);
echo "✔  Written: docker-compose.yml  ({$projectLabel}: " . count($instances) . " instance(s))\n";

echo "\nDeploy with:\n";
echo "  docker compose up -d --build\n";
