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
foreach (['database'] as $required) {
    if (empty($config[$required])) {
        fwrite(STDERR, "Missing required section '{$required}' in your config/ directory.\n");
        exit(1);
    }
}

$env             = getenv('APP_ENV') ?: 'testing';
$dbConfig        = $config['database'];
$db              = $dbConfig[$env] ?? array_shift($dbConfig);
$redis           = $config['redis'] ?? ['host' => 'redis', 'port' => 6379];
$instances       = $config['instances'] ?? [];
$projectLabel    = $config['project'] ?? 'apis-hub';
$deploymentName  = getenv('DEPLOYMENT_NAME') ?: 'apis-hub';

echo "⚒  Building standardized Master/Worker deployment for: " . strtoupper($env) . "\n";

$services = [];

// Helper to build environment block
$buildEnv = function($instanceName = null, $channel = 'none', $entity = 'none') use ($db, $redis, $config, $env, $deploymentName) {
    $envVars = [
        "PORT=8080",
        "API_SOURCE={$channel}",
        "API_ENTITY={$entity}",
        "USE_SWOOLE=true",
        "DB_DRIVER=\${DB_DRIVER:-" . ($db['driver'] ?? 'pdo_pgsql') . "}",
        "DB_HOST=\${DB_HOST:-" . (($env === 'production' || $env === 'testing') ? 'db' : ($db['host'] ?? 'db')) . "}",
        "DB_PORT=\${DB_PORT:-" . ($db['port'] ?? 5432) . "}",
        "DB_USER=\${DB_USER:-" . ($db['user'] ?? 'postgres') . "}",
        "DB_PASSWORD=\${DB_PASSWORD:-" . ($db['password'] ?? '') . "}",
        "DB_NAME=\${DB_NAME:-" . ($db['name'] ?? 'apis-hub') . "}",
        "REDIS_HOST=\${REDIS_HOST:-" . $redis['host'] . "}",
        "REDIS_PORT=\${REDIS_PORT:-" . $redis['port'] . "}",
        "PROJECT_CONFIG_FILE=/app/config/" . ($config['project'] ?? 'apis-hub') . ".yaml",
        "CONFIG_DIR=/app/config",
        "SKIP_SEED=\${SKIP_SEED:-0}",
        "ENV_FILE=\${ENV_FILE:-" . (getenv('ENV_FILE') ?: '.env') . "}",
        "PROJECT_PATH_HOST=\${PROJECT_PATH_HOST:-./}",
    ];
    if ($instanceName) {
        $envVars[] = "INSTANCE_NAME={$instanceName}";
    }
    return $envVars;
};

// ─── Phase 1: Create Standardized Master ────────────────────────────────────────
$masterName = "{$deploymentName}-master";
$startingHostPort = (int) (getenv('STARTING_HOST_PORT') ?: 10000);
$externalPort = getenv('EXTERNAL_PORT') ?: ($instances[0]['port'] ?? $startingHostPort);
$mcpPort = getenv('MCP_PORT') ?: 3000;
    $projectPathHost = "\${PROJECT_PATH_HOST:-./}";
    $isLocal = !in_array($env, ['production', 'testing', 'remote']);

    $phpVolumes = $isLocal ? [
        "$projectPathHost:/app",
        '/var/run/docker.sock:/var/run/docker.sock'
    ] : [
        '/var/run/docker.sock:/var/run/docker.sock'
    ];

    $services['master'] = [
        'container_name' => $masterName,
        'build' => [
            'context'    => '.',
            'dockerfile' => 'Dockerfile',
        ],
        'restart'     => 'always',
        'command'     => null,
        'environment' => $buildEnv($masterName),
        'networks'    => ['default', 'gateway'],
        'ports'       => [
            "{$externalPort}:8080"
        ],
        'volumes'     => $phpVolumes,
        'depends_on'  => [
            'db' => ['condition' => 'service_started'],
            'redis' => ['condition' => 'service_started'],
            'mcp' => ['condition' => 'service_started'],
        ],
        'extra_hosts' => ['host.docker.internal:host-gateway'],
        'deploy' => [
            'resources' => [
                'limits' => [
                    'cpus' => '0.50',
                    'memory' => '512M'
                ],
                'reservations' => [
                    'memory' => '256M'
                ]
            ]
        ]
    ];

    // ─── Phase 2: Create Scalable Worker Service ──────────────────────────────────
    $infraConfig = $config['infrastructure'] ?? [];
    $workerPoolSize = (int) ($infraConfig['worker_pool_size'] ?? 1);
    $workerVolumes = $isLocal ? ["$projectPathHost:/app"] : [];

    // Generic worker service definition (Single service to be scaled)
    $services['worker'] = [
        'build'          => [
            'context'    => '.',
            'dockerfile' => 'Dockerfile',
        ],
        'restart'     => 'always',
        'command'     => null,
        'environment' => $buildEnv(),
        'networks'    => ['default'],
        'volumes'     => $workerVolumes,
        'depends_on'  => [
            'master' => ['condition' => 'service_started'],
            'db' => ['condition' => 'service_started'],
            'redis' => ['condition' => 'service_started'],
        ],
        'deploy' => [
            'resources' => [
                'limits' => [
                    'cpus' => '0.50',
                    'memory' => '384M'
                ],
                'reservations' => [
                    'memory' => '128M'
                ]
            ]
        ]
    ];
    // Note: The actual number of containers is managed via 'docker compose up -d --scale worker=N'
    // but we can set the default scale in the yaml if supported by the version.
    $services['worker']['scale'] = $workerPoolSize;

    // ─── Phase 2.5: Create Dedicated MCP Service ─────────────────────────────────────
    $services['mcp'] = [
        'container_name' => "{$deploymentName}-mcp",
        'build' => [
            'context'    => '.',
            'dockerfile' => 'Dockerfile',
        ],
        'restart'     => 'always',
        'command'     => 'node mcp-server/index.js',
        'environment' => [
            'MCP_MODE=sse',
            'MCP_PORT=3000',
            'INSTANCE_NAME=mcp-server',
            'PHP_HOST=master'
        ],
        'networks'    => ['default', 'gateway'],
        'ports'       => [
            "{$mcpPort}:3000"
        ],
        'volumes'     => $isLocal ? [
            "$projectPathHost:/app",
            '/app/mcp-server/node_modules'
        ] : [
            '/app/mcp-server/node_modules'
        ],
        'deploy' => [
            'resources' => [
                'limits' => [
                    'cpus' => '0.30',
                    'memory' => '256M'
                ],
                'reservations' => [
                    'memory' => '128M'
                ]
            ]
        ]
    ];

// ─── Phase 3: Infrastructure (DB & Redis) ────────────────────────────────────────
$dbHost = (($env === 'production' || $env === 'testing') ? 'db' : ($db['host'] ?? 'db'));
if (true) { // Always create DB service in this master/worker architecture
    $dbHostPort = getenv('DB_HOST_PORT') ?: 5432;
    $services['db'] = [
        'container_name' => "{$deploymentName}-db",
        'image'         => ($db['driver'] ?? 'pdo_pgsql') === 'pdo_pgsql' ? 'postgres:16-alpine' : 'mysql:8.0',
        'shm_size'      => '2g',
        'restart'       => 'always',
        'environment'   => [
            (($db['driver'] ?? 'pdo_pgsql') === 'pdo_pgsql' ? 'POSTGRES_USER' : 'MYSQL_USER') => "\${DB_USER_".strtoupper(str_replace('-','_',$deploymentName)).":-" . ($db['user'] ?? 'postgres') . "}",
            (($db['driver'] ?? 'pdo_pgsql') === 'pdo_pgsql' ? 'POSTGRES_PASSWORD' : 'MYSQL_PASSWORD') => "\${DB_PASSWORD_".strtoupper(str_replace('-','_',$deploymentName)).":-" . ($db['password'] ?? 'postgres') . "}",
            (($db['driver'] ?? 'pdo_pgsql') === 'pdo_pgsql' ? 'POSTGRES_DB' : 'MYSQL_DATABASE') => "\${DB_NAME_".strtoupper(str_replace('-','_',$deploymentName)).":-" . ($db['name'] ?? 'apis-hub') . "}",
        ],
        'ports'   => ["{$dbHostPort}:5432"],
        'volumes' => ["db_data:/var/lib/postgresql/data"],
        'networks' => ['default'],
    ];
}

$redisHostPort = getenv('REDIS_HOST_PORT') ?: 6379;
$services['redis'] = [
    'container_name' => "{$deploymentName}-redis",
    'image'         => 'redis:alpine',
    'restart'       => 'always',
    'ports'         => ["{$redisHostPort}:6379"],
    'volumes'       => ['redis_data:/data'],
    'networks'      => ['default'],
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
        'gateway' => [
            'name' => "{$deploymentName}_default",
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
