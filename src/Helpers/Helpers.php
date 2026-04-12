<?php

namespace Helpers;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\Mapping\MappingException;
use Doctrine\Persistence\Proxy;
use Entities\Entity;
use Exception;
use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Predis\Client;
use Predis\ClientInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionObject;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class Helpers
{
    private static ?EntityManager $entityManager = null;
    private static ?ClientInterface $redisClient = null;
    private static ?array $cacheConfig = null;
    private static ?array $dbConfig = null;
    private static ?array $channelsConfig = null;
    private static ?array $entitiesConfig = null;
    private static ?array $projectConfig = null;
    private static ?string $appMode = null;

    /**
     * Resets all cached configurations to force a reload from files.
     * Useful for testing.
     *
     * @return void
     */
    public static function resetConfigs(): void
    {
        self::$projectConfig = null;
        self::$channelsConfig = null;
        self::$entitiesConfig = null;
        self::$dbConfig = null;
        self::$cacheConfig = null;
        self::$appMode = null;
    }

    /**
     * @return array
     */
    /**
     * @return array
     * @throws \Exceptions\ConfigurationException
     */
    public static function getProjectConfig(): array
    {
        if (self::$projectConfig !== null) {
            return self::$projectConfig;
        }

        $config = [];
        $rootConfigDir = getenv('CONFIG_DIR') ?: __DIR__ . '/../../config';

        // 0. Load .env manually if it exists to ensure variables are available
        $envFileName = getenv('ENV_FILE') ?: '.env';

        // Smart Default: If we are not explicitly told a file, and we are NOT already loading .env.demo,
        // we check if we should supplement with .env.demo later.

        $loadEnvFile = function ($filename) {
            $root = getenv('CONFIG_DIR') ? dirname(getenv('CONFIG_DIR')) : __DIR__ . '/../../';
            $filePath = $root . '/' . $filename;
            if (file_exists($filePath)) {
                $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (str_starts_with(trim($line), '#')) {
                        continue;
                    }
                    if (str_contains($line, '=')) {
                        list($name, $value) = explode('=', $line, 2);
                        $name = trim($name);
                        $value = trim($value, " \t\n\r\0\x0B\"'");
                        putenv("$name=$value");
                        $_ENV[$name] = $value;
                    }
                }

                return true;
            }

            return false;
        };

        // First pass: Load the primary env file
        if (! defined('PHPUNIT_COMPOSER_INSTALL') && ! defined('__PHPUNIT_PHAR__')) {
            $loadEnvFile($envFileName);
        }

        // Second pass: Smart Chain Load for Demo
        // If we just loaded .env and discovered it's a demo, load .env.demo over it if it exists.
        $appEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? null);
        if ($appEnv === 'demo' && $envFileName !== '.env.demo') {
            $loadEnvFile('.env.demo');
        }

        // 0. Safeguard: Check for mandatory configuration files
        $mandatoryFiles = ['database.yaml', 'security.yaml', 'app.yaml'];
        $missing = [];
        foreach ($mandatoryFiles as $mFile) {
            if (! file_exists($rootConfigDir . '/' . $mFile)) {
                $missing[] = $mFile;
            }
        }

        if (! empty($missing)) {
            $fileList = implode(', ', $missing);

            throw new \Exceptions\ConfigurationException(
                "Critical configuration files are missing: $fileList. " .
                "Please copy them from their .example templates in the config/ directory."
            );
        }

        // 1. Load all split config files from config/
        if (is_dir($rootConfigDir)) {
            $files = self::globRecursive($rootConfigDir . '/*.yaml');

            foreach ($files as $file) {
                $fileConfig = self::loadYamlFile($file);
                if (! empty($fileConfig)) {
                    $config = array_replace_recursive($config, $fileConfig);
                }
            }
        }
        // 2. Load environment-specific override if PROJECT_CONFIG_FILE is set
        $envFile = getenv('PROJECT_CONFIG_FILE');
        if ($envFile && is_file($envFile)) {
            $legacyConfig = self::loadYamlFile($envFile);
            $config = array_replace_recursive($config, $legacyConfig);
        }

        return self::$projectConfig = is_array($config) ? $config : [];
    }

    /**
     * @param string $file
     * @return array
     */
    private static function loadYamlFile(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        try {
            $content = file_get_contents($file);
            // Interpolate environment variables: ${VAR:-default}
            $content = preg_replace_callback('/\$\{([^}:]+)(?::-([^}]*))?\}/', function ($matches) {
                $varName = trim($matches[1]);
                $envValue = getenv($varName);
                if ($envValue === false && isset($_ENV[$varName])) {
                    $envValue = $_ENV[$varName];
                }
                if ($envValue === false && isset($_SERVER[$varName])) {
                    $envValue = $_SERVER[$varName];
                }

                return ($envValue !== false) ? (string)$envValue : ($matches[2] ?? $matches[0]);
            }, $content);

            $parsed = Yaml::parse($content);

            return is_array($parsed) ? $parsed : [];
        } catch (Exception) {
            return [];
        }
    }

    /**
     * @param string $pattern
     * @param int $flags
     * @return array
     */
    private static function globRecursive(string $pattern, int $flags = 0): array
    {
        $files = glob($pattern, $flags);
        foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
            $files = array_merge($files, self::globRecursive($dir . '/' . basename($pattern), $flags));
        }

        return array_filter($files, 'is_file');
    }

    /**
     * @return void
     */
    public static function applyTimezone(): void
    {
        $config = self::getProjectConfig();
        $timezone = $config['timezone'] ?? 'UTC';
        date_default_timezone_set($timezone);
    }

    /**
     * @return bool
     */
    public static function isDebug(): bool
    {
        $config = self::getProjectConfig();

        return (bool) ($config['debug'] ?? false);
    }

    /**
     * @return string
     */
    public static function getAppMode(): string
    {
        if (self::$appMode === null) {
            // Ensure config/env is loaded
            try {
                $config = self::getProjectConfig();
            } catch (\Exception $e) {
                $config = [];
            }

            $mode = getenv('APP_MODE') ?: ($_ENV['APP_MODE'] ?? null);

            // Heuristic for Demo: If APP_MODE is missing but PROJECT_NAME has 'demo'
            if (! $mode) {
                $projectName = getenv('PROJECT_NAME') ?: ($config['project'] ?? '');
                if (str_contains(strtolower($projectName), 'demo')) {
                    $mode = 'demo';
                }
            }

            self::$appMode = strtolower($mode ?: 'production');
        }

        return self::$appMode;
    }

    public static function isDemo(): bool
    {
        $mode = self::getAppMode();
        if ($mode === 'demo') {
            return true;
        }

        try {
            $config = self::getProjectConfig();
            $envProject = getenv('PROJECT_NAME');
            $projectName = $envProject ?: ($config['project'] ?? '');
            if (str_contains(strtolower($projectName), 'demo')) {
                return true;
            }
        } catch (\Exception $e) {
        }

        return false;
    }

    /**
     * @return array
     */
    public static function getCliConfig(): array
    {
        $projectConfig = self::getProjectConfig();

        return (array) ($projectConfig['cli'] ?? ['memory_limit' => '1G']);
    }

    /**
     * @param int $limit
     * @return array
     */
    public static function getNumbersArray(int $limit = 100): array
    {
        $array = [];
        for ($i = 1; $i <= $limit; $i++) {
            $array[$i] = $i;
        }

        return $array;
    }

    /**
     * @param object $instance
     * @param array $properties
     * @return object
     * @throws ReflectionException
     */
    public static function getAccessible(object $instance, array $properties = []): object
    {
        $reflector = new ReflectionObject($instance);
        foreach ($properties as $property) {
            $property = $reflector->getProperty($property);
            $instance->$property = $property->getValue($instance);
        }

        return $instance;
    }

    /**
     * @param object|string $class
     * @return array
     * @throws ReflectionException
     */
    public static function listPrivateProperties(object|string $class): array
    {
        $reflection = new ReflectionClass($class);
        $properties = $reflection->getProperties(ReflectionMethod::IS_PRIVATE);

        return array_map(function ($property) {
            return $property;
        }, $properties);
    }

    /**
     * Generates a platform-specific Upsert (Insert or Update) SQL query.
     * Supports MySQL (ON DUPLICATE KEY UPDATE) and PostgreSQL (ON CONFLICT DO UPDATE).
     *
     * @param string $table The table name
     * @param array $insertCols Simple array of column names to insert
     * @param array $updateCols Simple array of column names to update on conflict
     * @param array|string $uniqueCols Single column or array of columns that form the unique constraint (required for Postgres)
     * @param int $rowCount Number of rows to be inserted in bulk
     * @return string The generated SQL
     */
    public static function buildUpsertSql(string $table, array $insertCols, array $updateCols, array|string $uniqueCols, int $rowCount = 1): string
    {
        $dbConfig = self::getDbConfig();
        $isPostgres = self::isPostgres();

        $colString = implode(', ', $insertCols);
        $placeholders = '(' . implode(', ', array_fill(0, count($insertCols), '?')) . ')';
        $valuesString = implode(', ', array_fill(0, $rowCount, $placeholders));

        if ($isPostgres) {
            // PostgreSQL syntax: ON CONFLICT (unique_cols) DO UPDATE SET col = EXCLUDED.col
            $uniqueClause = is_array($uniqueCols) ? implode(', ', $uniqueCols) : $uniqueCols;
            $updateClauses = [];
            foreach ($updateCols as $col) {
                if ($col === 'updatedAt' || $col === 'updated_at') {
                    $updateClauses[] = "{$col} = CURRENT_TIMESTAMP";

                    continue;
                }
                if ($col === 'channeled_account_id' || $col === 'account_id' || $col === 'page_id') {
                    $updateClauses[] = "{$col} = COALESCE(EXCLUDED.{$col}, {$table}.{$col})";

                    continue;
                }
                $updateClauses[] = "{$col} = EXCLUDED.{$col}";
            }
            $updateString = implode(', ', $updateClauses);

            return "INSERT INTO {$table} ({$colString}) VALUES {$valuesString} ON CONFLICT ({$uniqueClause}) DO UPDATE SET {$updateString}";
        } else {
            // MySQL syntax: ON DUPLICATE KEY UPDATE col = VALUES(col)
            $updateClauses = [];
            foreach ($updateCols as $col) {
                if ($col === 'updatedAt' || $col === 'updated_at') {
                    $updateClauses[] = "{$col} = CURRENT_TIMESTAMP";

                    continue;
                }
                $updateClauses[] = "{$col} = VALUES({$col})";
            }
            $updateString = implode(', ', $updateClauses);

            return "INSERT INTO {$table} ({$colString}) VALUES {$valuesString} ON DUPLICATE KEY UPDATE {$updateString}";
        }
    }

    /**
     * Generates a platform-specific "Insert Ignore" SQL query.
     * Supports MySQL (INSERT IGNORE) and PostgreSQL (ON CONFLICT DO NOTHING).
     *
     * @param string $table The table name
     * @param array $insertCols Simple array of column names to insert
     * @param array|string $uniqueCols Single column or array of columns that form the unique constraint (required for Postgres)
     * @param int $rowCount Number of rows to be inserted in bulk
     * @return string The generated SQL
     */
    public static function buildInsertIgnoreSql(string $table, array $insertCols, array|string $uniqueCols, int $rowCount = 1): string
    {
        $dbConfig = self::getDbConfig();
        $isPostgres = self::isPostgres();

        $colString = implode(', ', $insertCols);
        $placeholders = '(' . implode(', ', array_fill(0, count($insertCols), '?')) . ')';
        $valuesString = implode(', ', array_fill(0, $rowCount, $placeholders));

        if ($isPostgres) {
            // In PostgreSQL, if DO NOTHING is specified, the conflict_target can be omitted
            // to ignore any conflict with any existing unique index.
            return "INSERT INTO {$table} ({$colString}) VALUES {$valuesString} ON CONFLICT DO NOTHING";
        } else {
            return "INSERT IGNORE INTO {$table} ({$colString}) VALUES {$valuesString}";
        }
    }

    /**
     * @return array
     */
    public static function getDbConfig(): array
    {
        if (self::$dbConfig === null) {
            $projectConfig = self::getProjectConfig();
            $env = getenv('APP_ENV') ?: 'testing';
            $baseConfig = $projectConfig['database'][$env] ?? [];

            try {
                // Override with environment variables if present
                $config = [];
                $config['driver'] = getenv('DB_DRIVER') ?: ($baseConfig['driver'] ?? 'pdo_mysql');
                $config['host'] = getenv('DB_HOST') ?: ($baseConfig['host'] ?? '127.0.0.1');
                $config['port'] = getenv('DB_PORT') ?: ($baseConfig['port'] ?? 3306);
                $config['user'] = getenv('DB_USER') ?: ($baseConfig['user'] ?? 'root');
                $config['password'] = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : ($baseConfig['password'] ?? '');
                $config['dbname'] = getenv('DB_NAME') ?: ($baseConfig['name'] ?? 'apis-hub');
                $isPgsql = $config['driver'] === 'pdo_pgsql';
                $config['charset'] = $isPgsql ? 'UTF8' : 'utf8mb4';

                if (! $isPgsql) {
                    $config['driverOptions'] = [
                        1002 => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci', // PDO::MYSQL_ATTR_INIT_COMMAND
                    ];
                }

                self::$dbConfig = $config;
            } catch (Exception $e) {
                throw new RuntimeException("Failed to load database configuration: " . $e->getMessage());
            }
        }

        return self::$dbConfig;
    }

    /**
     * @return bool
     */
    public static function isPostgres(): bool
    {
        $config = self::getDbConfig();
        $driver = $config['driver'] ?? '';

        return (
            $driver === 'pdo_pgsql' ||
            $driver === 'pgsql' ||
            $driver === 'postgres' ||
            $driver === 'postgresql' ||
            str_contains($driver, 'pgsql') ||
            str_contains($driver, 'postgres')
        );
    }

    /**
     * @return array
     */
    public static function getChannelsConfig(): array
    {
        if (self::$channelsConfig === null) {
            $projectConfig = self::getProjectConfig();
            $config = $projectConfig['channels'] ?? [];
            $configDir = getenv('CONFIG_DIR') ?: __DIR__ . '/../../config';
            $filePath = $configDir . '/yaml/channelsconfig.yaml';

            try {
                if (file_exists($filePath)) {
                    $yamlConfig = Yaml::parseFile($filePath);
                    if (is_array($yamlConfig)) {
                        $config = array_replace_recursive($yamlConfig, $config);
                    }
                }

                // Override with environment variables if present
                if ($envChannelsJson = getenv('CHANNELS_CONFIG')) {
                    $envChannels = json_decode($envChannelsJson, true);
                    if (is_array($envChannels)) {
                        $config = array_replace_recursive($config, $envChannels);
                    }
                }

                // Normalize relative paths to project root to avoid CWD issues
                $rootPath = dirname(__DIR__, 2);
                $resolvePath = function ($path) use ($rootPath) {
                    if (is_string($path) && str_starts_with($path, './')) {
                        return $rootPath . substr($path, 1);
                    }

                    return $path;
                };

                foreach ($config as $chan => $chanConfig) {
                    if (isset($chanConfig['token_path'])) {
                        $config[$chan]['token_path'] = $resolvePath($chanConfig['token_path']);
                    }
                }

                // Inject credentials from environment variables if missing in configuration
                $credentialMapping = [
                    'google' => [
                        'GOOGLE_CLIENT_ID' => 'client_id',
                        'GOOGLE_CLIENT_SECRET' => 'client_secret',
                        'GOOGLE_REFRESH_TOKEN' => 'refresh_token',
                        'GOOGLE_USER_ID' => 'user_id',
                        'GOOGLE_REDIRECT_URI' => 'redirect_uri',
                        'GOOGLE_TOKEN_PATH' => 'token_path',
                    ],
                    'facebook' => [
                        'FACEBOOK_APP_ID' => 'app_id',
                        'FACEBOOK_APP_SECRET' => 'app_secret',
                        'FACEBOOK_USER_ID' => 'user_id',
                        'FACEBOOK_REDIRECT_URI' => 'redirect_uri',
                        'FACEBOOK_TOKEN_PATH' => 'token_path',
                    ],
                ];

                $resolvePlaceholders = function (&$item) use (&$resolvePlaceholders) {
                    if (is_array($item)) {
                        foreach ($item as &$value) {
                            $resolvePlaceholders($value);
                        }
                    } elseif (is_string($item) && preg_match('/\${([^}]+)}/', $item, $matches)) {
                        $envValue = getenv($matches[1]);
                        if ($envValue !== false && $envValue !== '') {
                            $item = $envValue;
                        } else {
                            $item = ''; // Clear unresolved placeholders
                        }
                    }
                };
                $resolvePlaceholders($config);

                // Inject credentials from environment variables if missing or empty
                $credentialMapping = [
                    'google' => [
                        'GOOGLE_CLIENT_ID' => 'client_id',
                        'GOOGLE_CLIENT_SECRET' => 'client_secret',
                        'GOOGLE_REFRESH_TOKEN' => 'refresh_token',
                        'GOOGLE_USER_ID' => 'user_id',
                        'GOOGLE_REDIRECT_URI' => 'redirect_uri',
                        'GOOGLE_TOKEN_PATH' => 'token_path',
                    ],
                    'facebook' => [
                        'FACEBOOK_APP_ID' => 'app_id',
                        'FACEBOOK_APP_SECRET' => 'app_secret',
                        'FACEBOOK_USER_ID' => 'user_id',
                        'FACEBOOK_REDIRECT_URI' => 'redirect_uri',
                        'FACEBOOK_TOKEN_PATH' => 'token_path',
                    ],
                ];

                foreach ($credentialMapping as $chan => $mapping) {
                    if (!isset($config[$chan])) {
                        $config[$chan] = [];
                    }
                    foreach ($mapping as $envKey => $configKey) {
                        $val = getenv($envKey);
                        if ($val && empty($config[$chan][$configKey])) {
                            $config[$chan][$configKey] = $val;
                        }
                    }
                }

                // Inject common credentials into specific channels for backward compatibility
                $commonMappings = [
                    'google' => ['google_search_console', 'google_analytics', 'gsc'],
                    'facebook' => ['facebook_marketing', 'facebook_organic', 'instagram_insights'],
                ];

                foreach ($commonMappings as $commonKey => $specificChannels) {
                    if (!empty($config[$commonKey])) {
                        foreach ($specificChannels as $chan) {
                            if (isset($config[$chan])) {
                                // Important: Merge common into specific but only for missing/empty values in specific
                                $config[$chan] = array_replace_recursive($config[$commonKey], array_filter($config[$chan], fn($v) => !empty($v) && !is_array($v)));
                                // Maintain nested structure too
                                if (!isset($config[$chan][$commonKey])) {
                                    $config[$chan][$commonKey] = $config[$commonKey];
                                } else {
                                    $config[$chan][$commonKey] = array_replace_recursive($config[$commonKey], array_filter($config[$chan][$commonKey]));
                                }
                            }
                        }
                    }
                }

                if (getenv('APP_ENV') === 'demo') {
                    $placeholders = ['PAGE_ID', 'IG_ACCOUNT_ID', 'PAGE_URL', 'example.com', 'AD_ACCOUNT_ID'];
                    $cleanEntityList = function ($entities) use ($placeholders) {
                        return array_filter($entities, function ($item) use ($placeholders) {
                            $asString = json_encode($item);
                            foreach ($placeholders as $p) {
                                if (str_contains($asString, $p)) {
                                    return false;
                                }
                            }

                            return true;
                        });
                    };

                    $registry = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::getRegistry();
                    foreach ($registry as $chan => $rConfig) {
                        $resourceKey = $rConfig['resource_key'] ?? null;
                        if ($resourceKey && isset($config[$chan][$resourceKey])) {
                            $config[$chan][$resourceKey] = $cleanEntityList($config[$chan][$resourceKey]);
                        }
                    }
                }

                self::$channelsConfig = $config;
            } catch (Exception $e) {
                throw new RuntimeException("Failed to load channels configuration: " . $e->getMessage());
            }
        }

        return self::$channelsConfig;
    }

    /**
     * @return array
     */
    public static function getEntitiesConfig(): array
    {
        if (self::$entitiesConfig === null) {
            $filePath = __DIR__ . '/../../config/entitiesconfig.yaml';

            try {
                if (! file_exists($filePath)) {
                    throw new RuntimeException("Entities configuration file not found: $filePath");
                }
                $config = Yaml::parseFile($filePath);
                if (! is_array($config)) {
                    throw new RuntimeException("Invalid entities configuration: $filePath must return an array");
                }
                self::$entitiesConfig = $config;
            } catch (Exception $e) {
                throw new RuntimeException("Failed to load entities configuration: " . $e->getMessage());
            }
        }

        return self::$entitiesConfig;
    }

    /**
     * @return array
     */
    public static function getCacheConfig(): array
    {
        if (self::$cacheConfig === null) {
            $projectConfig = self::getProjectConfig();
            $config = $projectConfig['redis'] ?? [];

            $filePath = __DIR__ . '/../../config/yaml/cacheconfig.yaml';

            try {
                if (file_exists($filePath)) {
                    $yamlConfig = Yaml::parseFile($filePath);
                    if (is_array($yamlConfig)) {
                        $config = array_replace_recursive($yamlConfig, $config);
                    }
                }

                if (! isset($config['redis'])) {
                    $config['redis'] = [];
                }

                // Override with environment variables if present
                $config['redis']['host'] = getenv('REDIS_HOST') ?: ($config['redis']['host'] ?? '127.0.0.1');
                $config['redis']['port'] = getenv('REDIS_PORT') ? (int)getenv('REDIS_PORT') : ($config['redis']['port'] ?? 6379);
                if (getenv('REDIS_PASSWORD') !== false) {
                    $config['redis']['password'] = getenv('REDIS_PASSWORD');
                }

                self::$cacheConfig = $config;
            } catch (Exception $e) {
                throw new RuntimeException("Failed to load cache configuration: " . $e->getMessage());
            }
        }

        return self::$cacheConfig;
    }

    public static function getAdminApiKey(): ?string
    {
        // Forzamos la carga de la configuración para procesar el archivo .env
        self::getProjectConfig();

        $env = getenv('ADMIN_API_KEY');
        if ($env !== false && $env !== '') {
            return $env;
        }

        // Fallback a $_ENV (donde nuestro cargador manual inyecta los valores)
        return $_ENV['ADMIN_API_KEY'] ?? null;
    }

    /**
     * @return string|null
     */
    public static function getAppApiKey(): ?string
    {
        $env = getenv('APP_API_KEY');
        if ($env !== false && $env !== '') {
            return $env;
        }

        $config = self::getProjectConfig();
        $keys = $config['security']['api_keys'] ?? null;
        if (is_array($keys)) {
            return implode(',', $keys);
        }

        return $keys;
    }

    /**
     * @return array
     */
    public static function getAuthorizedIps(): array
    {
        $envIps = getenv('AUTHORIZED_IPS');
        if ($envIps !== false && $envIps !== '' && $envIps !== '[]') {
            return array_map('trim', explode(',', $envIps));
        }

        $config = self::getProjectConfig();
        $ips = $config['security']['authorized_ips'] ?? [];
        if (is_string($ips)) {
            return [$ips];
        }

        return is_array($ips) ? $ips : [];
    }

    /**
     * @return ClientInterface
     * @throws RuntimeException
     */
    public static function getRedisClient(): ClientInterface
    {
        if (self::$redisClient === null) {
            try {
                $config = self::getCacheConfig()['redis'] ?? [
                    'scheme' => 'tcp',
                    'host' => 'localhost',
                    'port' => 6379,
                    'password' => null,
                ];

                self::$redisClient = new Client($config);
                self::$redisClient->ping();
            } catch (Exception $e) {
                if (getenv('APP_ENV') === 'testing' || (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__'))) {
                    // During tests, we log the failure but don't crash everything
                    error_log('Redis initialization failed during tests: ' . $e->getMessage());

                    // We keep the client instance even if ping failed,
                    // downstream code will fail specifically if it actually tries to use it.
                    return self::$redisClient;
                }

                throw new RuntimeException('Failed to initialize Redis client: ' . $e->getMessage());
            }
        }

        return self::$redisClient;
    }

    /**
     * @return EntityManager
     */
    public static function getManager(): EntityManager
    {
        if (self::$entityManager === null || ! self::$entityManager->isOpen()) {
            try {
                $config = self::getDbConfig();
                $connection = DriverManager::getConnection($config);

                // Create attribute metadata configuration
                $ormConfig = ORMSetup::createAttributeMetadataConfiguration(
                    paths: array_merge([__DIR__ . '/../Entities'], \Anibalealvarezs\ApiDriverCore\Classes\EntityRegistry::getAll()),
                    isDevMode: true
                );

                // Create EntityManager
                self::$entityManager = new EntityManager($connection, $ormConfig);
            } catch (Exception $e) {
                throw new RuntimeException('Failed to initialize EntityManager: ' . $e->getMessage());
            }
        }

        return self::$entityManager;
    }

    public static function getAllSubsets($elements): array
    {
        $subsets = [[]]; // Include the emtpy subset
        foreach ($elements as $element) {
            $newSubsets = [];
            foreach ($subsets as $subset) {
                $newSubsets[] = $subset; // Copy the existing subset
                $newSubsets[] = array_merge($subset, [$element]); // Add the element to the existing subset
            }
            $subsets = $newSubsets;
        }

        return $subsets;
    }

    /**
     * @return array
     */
    public static function getEnabledCrudEntities(): array
    {
        return array_filter(self::getEntitiesConfig(), function ($entity) {
            if ($entity['crud_enabled']) {
                return $entity;
            }

            return false;
        });
    }

    /**
     * @param string $string
     * @param bool $capitalizeFirst
     * @return string
     */
    public static function toCamelcase(string $string, bool $capitalizeFirst = false): string
    {
        $str = str_replace(
            search: ' ',
            replace: '',
            subject: ucwords(
                string: str_replace(
                    search: ['_', '-'],
                    replace: ' ',
                    subject: $string
                )
            )
        );
        if (! $capitalizeFirst) {
            $str[0] = strtolower($str[0]);
        }

        return $str;
    }

    public static function toSnakeCase(string $string): string
    {
        $string = preg_replace('/([a-z])([A-Z])/', '$1_$2', $string);

        return strtolower($string);
    }

    /**
     * Converts a human-readable interval like '3 years' or '1 month' to ISO8601 interval 'P3Y'.
     *
     * @param string $human
     * @return string
     */
    public static function humanToIsoInterval(string $human): array|string|null
    {
        $human = strtolower(trim($human));

        $conversions = [
            'year' => 'Y',
            'month' => 'M',
            'week' => 'W',
            'day' => 'D',
            'hour' => 'H',
            'minute' => 'M', // Suffix for time part
            'second' => 'S',
        ];

        if (preg_match('/^(\d+)\s*(year|month|week|day|hour|minute|second)s?$/', $human, $matches)) {
            $value = $matches[1];
            $unit = $matches[2];
            $suffix = $conversions[$unit];

            if (in_array($unit, ['hour', 'minute', 'second'])) {
                return "PT{$value}{$suffix}";
            }

            return "P{$value}{$suffix}";
        }

        return $human; // Return as is if already ISO or unrecognized
    }

    /**
     * @param Entity $entity
     * @param array|null $fields
     * @return array
     * @throws ReflectionException
     */
    public static function jsonSerialize(Entity $entity, ?array $fields = null): array
    {
        $reflect = new ReflectionClass($entity);
        $props = $reflect->getProperties(ReflectionProperty::IS_STATIC | ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PRIVATE);

        $propsIterator = function () use ($props, $entity, $fields) {
            foreach ($props as $prop) {
                if (method_exists($entity, self::toCamelcase('get_' . $prop->getName())) && (! $fields || in_array($prop->getName(), $fields))) {
                    yield $prop->getName() => $entity->{self::toCamelcase('get_' . $prop->getName())}();
                }
            }
        };

        return iterator_to_array($propsIterator());
    }

    /**
     * @param EntityManager $em
     * @param string|object $class
     * @return bool
     * @throws MappingException
     */
    public static function isEntity(EntityManager $em, string|object $class): bool
    {
        if (is_object($class)) {
            $class = ($class instanceof Proxy)
                ? get_parent_class($class)
                : get_class($class);
        }

        return ! $em->getMetadataFactory()->isTransient($class);
    }

    /**
     * @param string|null $data
     * @return object
     */
    public static function dataToObject(?string $data = null): object
    {
        if ($data) {
            return json_decode(base64_decode($data));
        }

        return (object)[];
    }

    /**
     * @param string|null $data
     * @return object
     */
    public static function bodyToObject(?string $data = null): object
    {
        if ($data) {
            $decoded = json_decode($data);

            return is_object($decoded) ? $decoded : (object)[];
        }

        return (object)[];
    }

    /**
     * @param array $multiDimensionalArray
     * @return array
     */
    public static function multiDimensionalArrayUnique(array $multiDimensionalArray): array
    {
        // Apply array_map() to each sub-array to convert it to a string representation
        $stringMatrix = array_map(function ($array) {
            return json_encode($array);
        }, $multiDimensionalArray);
        // Remove duplicates based on the string representation
        $uniqueStringMatrix = array_unique($stringMatrix);

        // Convert back to the original multidimensional array
        return array_map(function ($variant) {
            return json_decode($variant, true);
        }, $uniqueStringMatrix);
    }

    /**
     * @param string $url
     * @return string|null
     */
    public static function getDomain(string $url): ?string
    {
        // Remove scheme and www
        $url = preg_replace('~^https?://(?:www\.)?~i', '', $url);

        // Remove path and query strings
        $url = preg_replace('~[/?#].*$~', '', $url);

        // Validate domain pattern (supports international domains)
        if (preg_match('~^([a-z0-9\-]+\.)+[a-z]{2,}$~i', $url)) {
            return $url;
        }

        return null;
    }

    /**
     * @param string $haystack
     * @param array $needles
     * @return bool
     */
    public static function str_contains_any(string $haystack, array $needles): bool
    {
        return array_reduce($needles, fn ($a, $n) => $a || stripos($haystack, $n) !== false, false);
    }

    /**
     * @param string $haystack
     * @param array $needles
     * @return bool
     */
    public static function str_contains_all(string $haystack, array $needles): bool
    {
        return array_reduce($needles, fn ($a, $n) => $a && str_contains($haystack, $n), true);
    }

    /**
     * Dump data as JSON for debugging purposes.
     * @param array $data
     * @return void
     */
    public static function dumpDebugJson(array $data)
    {
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        die();
    }

    /**
     * @param int|null $jobId
     * @return void
     * @throws \Exceptions\JobCancelledException
     */
    public static function checkJobStatus(?int $jobId): void
    {
        if (! $jobId) {
            return;
        }

        $jobRepo = self::getManager()->getRepository(\Entities\Job::class);
        $status = $jobRepo->createQueryBuilder('j')
            ->select('j.status')
            ->where('j.id = :id')
            ->setParameter('id', $jobId)
            ->getQuery()
            ->getSingleScalarResult();

        if ($status == \Enums\JobStatus::failed->value || $status == \Enums\JobStatus::cancelled->value) {
            throw new \Exceptions\JobCancelledException("El Job #{$jobId} fue interrumpido o cancelado manualmente.");
        }
    }

    /**
     * @param string $level
     * @return Level
     */
    public static function getLogLevel(string $level): Level
    {
        return match (strtolower($level)) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Info,
        };
    }

    /**
     * @param string $filename
     * @param Level|int|string|null $level
     * @return LoggerInterface
     */
    public static function setLogger(string $filename, Level|int|string|null $level = null): LoggerInterface
    {
        $projectConfig = self::getProjectConfig();
        $logConfig = $projectConfig['logging'] ?? [];
        $enabled = (bool) ($logConfig['enabled'] ?? true);

        $name = str_replace('.log', '', $filename);
        $logger = new Logger($name);

        if (! $enabled) {
            $logger->pushHandler(new NullHandler());

            return $logger;
        }

        // Determine base level from config
        $configLevelStr = self::isDebug()
            ? ($logConfig['level'] ?? 'info')
            : ($logConfig['prod_level'] ?? 'error');

        $baseLevel = self::getLogLevel($configLevelStr);

        // If a specific level was requested, we respect it if it's more restrictive
        // or if debug is on.
        if ($level !== null) {
            $requested = ($level instanceof Level)
                ? $level
                : (is_int($level) ? Level::from($level) : self::getLogLevel($level));

            // In non-debug mode, we don't allow logging below the prod_level
            if (! self::isDebug() && $requested->value < $baseLevel->value) {
                $requested = $baseLevel;
            }
            $finalLevel = $requested;
        } else {
            $finalLevel = $baseLevel;
        }

        $maxFiles = $logConfig['max_days'] ?? 7;
        $logger->pushHandler(new RotatingFileHandler(__DIR__ . '/../../logs/' . $filename, $maxFiles, $finalLevel));

        return $logger;
    }

    /**
     * @param EntityManager $em
     * @return void
     */
    public static function reconnectIfNeeded(EntityManager $em): void
    {
        try {
            $em->getConnection()->executeQuery('SELECT 1');
        } catch (Exception $e) {
            $em->getConnection()->close();
            $em->getConnection()->connect();
        }
    }

    /**
     * Checks if a string matches inclusion/exclusion filters.
     * Supports both plain text and regex (if delimited by /).
     *
     * @param string $value
     * @param string|array|null $include
     * @param string|array|null $exclude
     * @return bool
     */
    public static function matchesFilter(string|int $value, $include = null, $exclude = null): bool
    {
        $value = (string)$value;
        // If include is set, must match at least one
        if (! empty($include)) {
            $matchedInclude = false;
            $includes = is_array($include) ? $include : [$include];
            foreach ($includes as $pattern) {
                if (empty($pattern)) {
                    continue;
                }
                if (str_starts_with($pattern, '/') && str_ends_with($pattern, '/')) {
                    if (preg_match($pattern, $value)) {
                        $matchedInclude = true;

                        break;
                    }
                } elseif (stripos($value, $pattern) !== false) {
                    $matchedInclude = true;

                    break;
                }
            }
            if (! $matchedInclude) {
                return false;
            }
        }

        // If exclude is set, must NOT match any
        if (! empty($exclude)) {
            $excludes = is_array($exclude) ? $exclude : [$exclude];
            foreach ($excludes as $pattern) {
                if (empty($pattern)) {
                    continue;
                }
                if (str_starts_with($pattern, '/') && str_ends_with($pattern, '/')) {
                    if (preg_match($pattern, $value)) {
                        return false;
                    }
                } elseif (stripos($value, $pattern) !== false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Splits a date range into smaller chunks.
     *
     * @param string $startDate
     * @param string $endDate
     * @param string $interval e.g. '1 week', '1 month', '7 days'
     * @return array Array of ['start' => string, 'end' => string]
     */
    public static function getDateChunks(string $startDate, string $endDate, string $interval = '1 week'): array
    {
        $start = \Carbon\Carbon::parse($startDate);
        $end = \Carbon\Carbon::parse($endDate);

        if ($start->isAfter($end)) {
            return [];
        }

        $chunks = [];
        $currentStart = $start->copy();

        while ($currentStart->isBefore($end) || $currentStart->isSameDay($end)) {
            $currentEnd = $currentStart->copy()->add($interval)->subDay();
            if ($currentEnd->isAfter($end)) {
                $currentEnd = $end->copy();
            }

            $chunks[] = [
                'start' => $currentStart->format('Y-m-d'),
                'end' => $currentEnd->format('Y-m-d'),
            ];

            $currentStart = $currentEnd->copy()->addDay();
        }

        return $chunks;
    }

    /**
     * @param string $url
     * @param string|int|null $platformId
     * @param \Enums\PageType|string|null $type
     * @param string|null $hostname
     * @return string
     */
    public static function getCanonicalPageId(string $url, string|int|null $platformId = null, string|null $type = null, string|null $hostname = null): string
    {
        $prefix = null;
        $urlIdRegex = null;

        // 1. Resolve prefix by type if provided
        if ($type) {
            $assetPattern = \Anibalealvarezs\ApiDriverCore\Classes\AssetRegistry::findByType($type);
            if ($assetPattern) {
                $prefix = $assetPattern['prefix'];
                $urlIdRegex = $assetPattern['url_id_regex'] ?? null;
            }
        }

        if (! $prefix && $hostname) {
            $assetPattern = \Anibalealvarezs\ApiDriverCore\Classes\AssetRegistry::findByHostname($hostname);
            if ($assetPattern) {
                $prefix = $assetPattern['prefix'];
                $urlIdRegex = $assetPattern['url_id_regex'] ?? null;
            }
        }

        // 3. Normalized URL processing
        $normalizedUrl = preg_replace('~^https?://(?:www\.)?~i', '', $url);
        $normalizedUrl = rtrim($normalizedUrl, '/');
        $normalizedUrl = strtolower($normalizedUrl);

        // 4. Extract ID from URL if regex is available
        if (! $platformId && $urlIdRegex) {
            if (preg_match($urlIdRegex, $normalizedUrl, $matches)) {
                $platformId = $matches[1];
            }
        }

        // 5. Build canonical ID
        if ($platformId && $prefix) {
            return "{$prefix}:{$platformId}";
        }

        // 6. Last resort fallback
        if ($prefix) {
            return "{$prefix}:" . md5($normalizedUrl);
        }

        return "site:domain:" . $normalizedUrl;
    }

    /**
     * Determines if the current instance is the master container.
     */
    public static function isMaster(): bool
    {
        $instance = getenv('INSTANCE_NAME');

        return $instance && str_contains(strtolower($instance), 'master');
    }
}
