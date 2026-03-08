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

// ─── CLI arg ──────────────────────────────────────────────────────────────────
$projectName = $argv[1] ?? null;
if (!$projectName) {
    fwrite(STDERR, "Usage: php bin/build-deployment.php <project-name>\n");
    fwrite(STDERR, "  Reads deploy/<project-name>.yaml\n");
    exit(1);
}

$projectFile = __DIR__ . "/../deploy/{$projectName}.yaml";
if (!file_exists($projectFile)) {
    fwrite(STDERR, "Project file not found: {$projectFile}\n");
    fwrite(STDERR, "Copy deploy/project.yaml.example to deploy/{$projectName}.yaml and fill it in.\n");
    exit(1);
}

$config = Yaml::parseFile($projectFile);

// ─── Validate required sections ───────────────────────────────────────────────
foreach (['database', 'instances', 'channels'] as $required) {
    if (empty($config[$required])) {
        fwrite(STDERR, "Missing required section '{$required}' in {$projectFile}\n");
        exit(1);
    }
}

$env      = getenv('APP_ENV') ?: 'testing';
$dbConfig = $config['database'];
$db       = $dbConfig[$env] ?? array_shift($dbConfig); // Default to specified env or first block

$redis   = $config['redis'] ?? ['host' => 'redis', 'port' => 6379];
$instances = $config['instances'];
$channels  = $config['channels'];
$projectLabel = $config['project'] ?? $projectName;
echo "⚒  Building deployment for environment: " . strtoupper($env) . "\n";

// ─── Build docker-compose.yml ─────────────────────────────────────────────────
$services = [];
foreach ($instances as $instance) {
    $name      = $instance['name'];
    $port      = $instance['port'] ?? null;
    $channel   = $instance['channel'];
    $entity    = $instance['entity'];
    $startDate = $instance['start_date'] ?? null;
    $endDate   = $instance['end_date']   ?? null;

    $envBlock = [
        "PORT=8080",
        "API_SOURCE={$channel}",
        "API_ENTITY={$entity}",
        "DB_DRIVER=" . ($db['driver'] ?? 'pdo_mysql'),
        "DB_HOST=" . ($db['host'] ?? 'host.docker.internal'),
        "DB_PORT=" . ($db['port'] ?? 3306),
        "DB_USER=" . ($db['user'] ?? 'root'),
        "DB_PASSWORD=" . ($db['password'] ?? ''),
        "DB_NAME=" . ($db['name'] ?? ''),
        "REDIS_HOST=" . $redis['host'],
        "REDIS_PORT=" . $redis['port'],
        "PROJECT_CONFIG_FILE=/app/deploy/{$projectName}.yaml",
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

    if ($port) {
        $serviceConfig['ports'] = ["{$port}:8080"];
    }

    $services[$name] = $serviceConfig;
}

$services['redis'] = [
    'image'   => 'redis:alpine',
    'restart' => 'always',
    'ports'   => ['6379:6379'],
    'volumes' => ['redis_data:/data'],
];

$compose = [
    'services' => $services,
    'volumes'  => ['redis_data' => null],
];

$composeYaml = Yaml::dump($compose, 6, 2, Yaml::DUMP_NULL_AS_TILDE);

$composeOut  = __DIR__ . '/../docker-compose.yml';
file_put_contents($composeOut, $composeYaml);
echo "✔  Written: docker-compose.yml  ({$projectLabel}: " . count($instances) . " instance(s))\n";

echo "\nDeploy with:\n";
echo "  docker compose up -d --build\n";
