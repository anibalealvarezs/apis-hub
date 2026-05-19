#!/usr/bin/env php
<?php
    /**
     * apis-hub deployment builder (Standardized Master Architecture)
     */

    require_once __DIR__.'/../vendor/autoload.php';

    use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
    use Anibalealvarezs\ApiDriverCore\Enums\InstanceTier;
    use Classes\DriverInitializer;
    use Exceptions\ConfigurationException;
    use Symfony\Component\Yaml\Yaml;
    use Helpers\Helpers;

    // ─── Ensure CONFIG_DIR is set ────────────────────────────────────────────────
    $configDir = getenv('CONFIG_DIR') ?: realpath(__DIR__.'/../config');
    putenv("CONFIG_DIR=$configDir");
    $_ENV['CONFIG_DIR'] = $configDir;

    // ─── Load Configuration ───────────────────────────────────────────────────────
    try {
        $config = Helpers::getProjectConfig();
    } catch (ConfigurationException $e) {
        fwrite(STDERR, "Error loading configuration: ".$e->getMessage()."\n");
        exit(1);
    }

    // Load tiers configuration
    $tiersConfigFile = __DIR__.'/../config/tiers.yaml';
    $tiersConfig = [];
    if (file_exists($tiersConfigFile)) {
        $tiersConfig = Yaml::parseFile($tiersConfigFile)['tiers'] ?? [];
    }
    // Default tiers validaton
    if (empty($tiersConfig)) {
        $tiersConfig = [
                0 => ['memory' => '128M', 'cpu' => '0.30'],
                1 => ['memory' => '256M', 'cpu' => '0.30'],
                2 => ['memory' => '384M', 'cpu' => '0.50'],
                3 => ['memory' => '512M', 'cpu' => '0.50'],
                4 => ['memory' => '1024M', 'cpu' => '1.00'],
                5 => ['memory' => '2048M', 'cpu' => '1.50'],
        ];
    }

    // ─── Validate required sections ───────────────────────────────────────────────
    foreach (['database', 'redis'] as $required) {
        if (empty($config[$required])) {
            fwrite(STDERR, "Missing required section '$required' in your config/ directory.\n");
            exit(1);
        }
    }

    $env = getenv('APP_ENV') ?: 'testing';
    $dbConfig = $config['database'];
    $db = $dbConfig[$env] ?? array_shift($dbConfig);
    $redis = $config['redis'] ?? ['host' => 'redis', 'port' => 6379];
    $instances = $config['instances'] ?? [];
    $projectLabel = $config['project'] ?? 'apis-hub';
    $deploymentName = getenv('DEPLOYMENT_NAME') ?: 'apis-hub';

    echo "⚒  Building standardized Master/Worker deployment for: ".strtoupper($env)."\n";

    $services = [];

    // Helper to build environment block
    $buildEnv = function ($instanceName = null, $channel = 'none', $entity = 'none', $workerTier = null) use ($db, $redis, $config, $env, $deploymentName) {
        $envVars = [
                "PORT=8080",
                "API_SOURCE=$channel",
                "API_ENTITY=$entity",
                "USE_SWOOLE=true",
                "DB_DRIVER=\${DB_DRIVER:-".($db['driver'] ?? 'pdo_pgsql')."}",
                "DB_HOST=\${DB_HOST:-".(($env === 'production' || $env === 'testing') ? 'db' : ($db['host'] ?? 'db'))."}",
                "DB_PORT=\${DB_PORT:-".($db['port'] ?? 5432)."}",
                "DB_USER=\${DB_USER:-".($db['user'] ?? 'postgres')."}",
                "DB_PASSWORD=\${DB_PASSWORD:-".($db['password'] ?? '')."}",
                "DB_NAME=\${DB_NAME:-".($db['name'] ?? 'apis-hub')."}",
                "REDIS_HOST=\${REDIS_HOST:-".$redis['host']."}",
                "REDIS_PORT=\${REDIS_PORT:-".$redis['port']."}",
                "PROJECT_CONFIG_FILE=/app/config/".($config['project'] ?? 'apis-hub').".yaml",
                "CONFIG_DIR=/app/config",
                "SKIP_SEED=\${SKIP_SEED:-0}",
                "ENV_FILE=\${ENV_FILE:-".(getenv('ENV_FILE') ?: '.env')."}",
                "PROJECT_PATH_HOST=\${PROJECT_PATH_HOST:-./}",
        ];
        if ($instanceName) {
            $envVars[] = "INSTANCE_NAME=$instanceName";
        }
        if ($workerTier !== null) {
            $envVars[] = "WORKER_TIER=$workerTier";
        }

        return $envVars;
    };

    // ─── Phase 1: Create Standardized Master ────────────────────────────────────────
    $masterName = "$deploymentName-master";
    $startingHostPort = (int)(getenv('STARTING_HOST_PORT') ?: 10000);
    $externalPort = getenv('EXTERNAL_PORT') ?: ($instances[0]['port'] ?? $startingHostPort);
    $mcpPort = getenv('MCP_PORT') ?: 3000;
    $projectPathHost = "\${PROJECT_PATH_HOST:-./}";
    $isLocal = !in_array($env, ['production', 'testing', 'remote']);

    $phpVolumes = [
            "$projectPathHost:/app",
            '/var/run/docker.sock:/var/run/docker.sock'
    ];

    $services['master'] = [
            'container_name' => $masterName,
            'build'          => [
                    'context'    => '.',
                    'dockerfile' => 'Dockerfile',
            ],
            'restart'        => 'always',
            'command'        => null,
            'environment'    => $buildEnv($masterName),
            'networks'       => ['default', 'gateway'],
            'ports'          => [
                    "$externalPort:8080"
            ],
            'volumes'        => $phpVolumes,
            'depends_on'     => [
                    'db'    => ['condition' => 'service_started'],
                    'redis' => ['condition' => 'service_started'],
                    'mcp'   => ['condition' => 'service_started'],
            ],
            'extra_hosts'    => ['host.docker.internal:host-gateway'],
            'deploy'         => [
                    'resources' => [
                            'limits'       => [
                                    'cpus'   => $tiersConfig[InstanceTier::MASTER->value]['cpu'],
                                    'memory' => $tiersConfig[InstanceTier::MASTER->value]['memory']
                            ],
                            'reservations' => [
                                    'memory' => $tiersConfig[InstanceTier::MINIMAL->value]['memory']
                            ]
                    ]
            ]
    ];

    // ─── Phase 2: Create Scalable Tiered Worker Pools ─────────────────────────────
    $infraConfig = $config['infrastructure'] ?? [];
    $workerVolumes = ["$projectPathHost:/app"];

    // 1. Gather all active channels and their required tiers
    $availableChannels = DriverFactory::getAvailableChannels();
    if (empty($availableChannels)) {
        $availableChannels = array_map(function ($f) {
            return str_replace('.yaml', '', basename($f));
        }, glob(__DIR__.'/../config/channels/*.yaml'));
    }

    $requiredTiers = []; // Map of [tierValue => totalMaxWorkers]
    
    foreach ($availableChannels as $chanKey) {
        try {
            $chanConfig = DriverInitializer::validateConfig($chanKey);
            if (!empty($chanConfig['enabled'])) {
                $driver = DriverFactory::get($chanKey);
                $tierLevel = InstanceTier::BASIC->value;
                if (method_exists($driver, 'getRequiredInstanceTier')) {
                    $tierLevel = $driver->getRequiredInstanceTier()->value;
                }
                
                $maxWorkers = 3;
                if (array_key_exists('max_workers', $chanConfig)) {
                    $maxWorkers = max(0, (int)$chanConfig['max_workers']);
                } elseif (method_exists($driver, 'getDefaultMaxWorkers')) {
                    $maxWorkers = max(0, (int)$driver::getDefaultMaxWorkers());
                }
                
                if (!isset($requiredTiers[$tierLevel])) {
                    $requiredTiers[$tierLevel] = 0;
                }
                // We add up the max workers required by each channel in this tier
                // This gives us a theoretical maximum concurrency needed for this tier
                $requiredTiers[$tierLevel] += $maxWorkers;
            }
        } catch (Exception $e) {
            // Skip invalid configs
        }
    }

    // Default to at least one basic worker pool if nothing is found
    if (empty($requiredTiers)) {
        $requiredTiers[InstanceTier::BASIC->value] = 3;
    }

    // 2. Generate a worker pool service for each required tier
    foreach ($requiredTiers as $tierValue => $totalChannelConcurrency) {
        // Limit the pool size to a reasonable default (e.g., max 10 per tier) or based on infra config
        // Assuming infraConfig['worker_pool_size'] applies globally, we might want to scale it or just use a sensible ratio
        // Let's cap each pool to 10 by default to avoid accidental massive scaling, but allow infra config to override.
        $maxPerTier = 10;
        if (isset($infraConfig['worker_pool_size'])) {
            $maxPerTier = (int)$infraConfig['worker_pool_size'];
        }
        $poolSize = min($totalChannelConcurrency, $maxPerTier);
        
        $tierName = "worker-tier-$tierValue";
        
        // Safety check to ensure we have limits defined for this tier
        $memoryLimit = $tiersConfig[$tierValue]['memory'] ?? $tiersConfig[InstanceTier::BASIC->value]['memory'];
        $cpuLimit = $tiersConfig[$tierValue]['cpu'] ?? $tiersConfig[InstanceTier::BASIC->value]['cpu'];

        echo "ℹ  Calculated Worker Pool Size for Tier $tierValue: $poolSize (Max theoretical concurrency needed: $totalChannelConcurrency)\n";

        $services[$tierName] = [
                'build'       => [
                        'context'    => '.',
                        'dockerfile' => 'Dockerfile',
                ],
                'restart'     => 'always',
                'command'     => null,
                'environment' => $buildEnv(null, 'none', 'none', $tierValue),
                'networks'    => ['default'],
                'volumes'     => $workerVolumes,
                'depends_on'  => [
                        'master' => ['condition' => 'service_started'],
                        'db'     => ['condition' => 'service_started'],
                        'redis'  => ['condition' => 'service_started'],
                ],
                'deploy'      => [
                        'resources' => [
                                'limits'       => [
                                        'cpus'   => (string)$cpuLimit,
                                        'memory' => $memoryLimit
                                ],
                                'reservations' => [
                                        'memory' => $tiersConfig[InstanceTier::RESERVATION->value]['memory']
                                ]
                        ]
                ]
        ];
        $services[$tierName]['scale'] = $poolSize;
    }


    // ─── Phase 2.5: Create Dedicated MCP Service ─────────────────────────────────────
    $services['mcp'] = [
            'container_name' => "$deploymentName-mcp",
            'build'          => [
                    'context'    => '.',
                    'dockerfile' => 'Dockerfile',
            ],
            'restart'        => 'always',
            'command'        => 'node mcp-server/index.js',
            'environment'    => [
                    'MCP_MODE=sse',
                    'MCP_PORT=3000',
                    'INSTANCE_NAME=mcp-server',
                    'PHP_HOST=master'
            ],
            'networks'       => ['default', 'gateway'],
            'ports'          => [
                    "$mcpPort:3000"
            ],
            'volumes'        => $isLocal ? [
                    "$projectPathHost:/app",
                    '/app/mcp-server/node_modules'
            ] : [
                    '/app/mcp-server/node_modules'
            ],
            'deploy'         => [
                    'resources' => [
                            'limits'       => [
                                    'cpus'   => $tiersConfig[InstanceTier::MINIMAL->value]['cpu'],
                                    'memory' => $tiersConfig[InstanceTier::MINIMAL->value]['memory']
                            ],
                            'reservations' => [
                                    'memory' => $tiersConfig[InstanceTier::RESERVATION->value]['memory']
                            ]
                    ]
            ]
    ];

    // ─── Phase 3: Infrastructure (DB & Redis) ────────────────────────────────────────
    $dbHost = (($env === 'production' || $env === 'testing') ? 'db' : ($db['host'] ?? 'db'));
    if (true) { // Always create DB service in this master/worker architecture
        $dbHostPort = getenv('DB_HOST_PORT') ?: 5432;
        $services['db'] = [
                'container_name' => "$deploymentName-db",
                'image'          => ($db['driver'] ?? 'pdo_pgsql') === 'pdo_pgsql' ? 'postgres:16-alpine' : 'mysql:8.0',
                'shm_size'       => '2g',
                'restart'        => 'always',
                'environment'    => [
                        (($db['driver'] ?? 'pdo_pgsql') === 'pdo_pgsql' ? 'POSTGRES_USER' : 'MYSQL_USER')         => "\${DB_USER_".strtoupper(str_replace('-', '_', $deploymentName)).":-".($db['user'] ?? 'postgres')."}",
                        (($db['driver'] ?? 'pdo_pgsql') === 'pdo_pgsql' ? 'POSTGRES_PASSWORD' : 'MYSQL_PASSWORD') => "\${DB_PASSWORD_".strtoupper(str_replace('-', '_', $deploymentName)).":-".($db['password'] ?? 'postgres')."}",
                        (($db['driver'] ?? 'pdo_pgsql') === 'pdo_pgsql' ? 'POSTGRES_DB' : 'MYSQL_DATABASE')       => "\${DB_NAME_".strtoupper(str_replace('-', '_', $deploymentName)).":-".($db['name'] ?? 'apis-hub')."}",
                ],
                'ports'          => ["$dbHostPort:5432"],
                'volumes'        => ["db_data:/var/lib/postgresql/data"],
                'networks'       => ['default'],
        ];
    }

    $redisHostPort = getenv('REDIS_HOST_PORT') ?: 6379;
    $services['redis'] = [
            'container_name' => "$deploymentName-redis",
            'image'          => 'redis:alpine',
            'restart'        => 'always',
            'ports'          => ["$redisHostPort:6379"],
            'volumes'        => ['redis_data:/data'],
            'networks'       => ['default'],
    ];

    // ─── Write docker-compose.yml ──────────────────────────────────────────────────
    $dbVolumeName = getenv('DB_VOLUME_NAME') ?: "$deploymentName-db-data";
    $redisVolumeName = getenv('REDIS_VOLUME_NAME') ?: "$deploymentName-redis-data";

    $compose = [
            'name'     => $deploymentName,
            'services' => $services,
            'networks' => [
                    'default' => [
                            'name' => "{$deploymentName}_internal",
                    ],
                    'gateway' => [
                            'name'     => "{$deploymentName}_default",
                            'external' => true
                    ]
            ],
            'volumes'  => [
                    'redis_data' => ['name' => $redisVolumeName],
                    'db_data'    => ['name' => $dbVolumeName]
            ],
    ];

    file_put_contents(__DIR__.'/../docker-compose.yml', Yaml::dump($compose, 6, 2, Yaml::DUMP_NULL_AS_TILDE));
    echo "✔  Written: docker-compose.yml (Master Architecture Enabled)\n";