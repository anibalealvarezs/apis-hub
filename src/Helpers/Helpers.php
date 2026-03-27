<?php

namespace Helpers;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\Mapping\MappingException;
use Doctrine\Persistence\Proxy;
use Entities\Entity;
use Exception;
use Predis\Client;
use Predis\ClientInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionObject;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\NullHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

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
        $rootConfigDir = __DIR__ . '/../../config';

        // 0. Load .env manually if it exists to ensure variables are available
        $envFileName = getenv('ENV_FILE') ?: '.env';
        $envFilesToLoad = [$envFileName];
        
        // Smart Default: If we are not explicitly told a file, and we are NOT already loading .env.demo, 
        // we check if we should supplement with .env.demo later.
        
        $loadedValues = [];
        $loadEnvFile = function($filename) use (&$loadedValues) {
            $filePath = __DIR__ . '/../../' . $filename;
            if (file_exists($filePath)) {
                $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (str_starts_with(trim($line), '#')) continue;
                    if (str_contains($line, '=')) {
                        list($name, $value) = explode('=', $line, 2);
                        $name = trim($name);
                        $value = trim($value, " \t\n\r\0\x0B\"'");
                        putenv("$name=$value");
                        $_ENV[$name] = $value;
                        $loadedValues[$name] = $value;
                    }
                }
                return true;
            }
            return false;
        };

        // First pass: Load the primary env file
        $loadEnvFile($envFileName);

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
            if (!file_exists($rootConfigDir . '/' . $mFile)) {
                $missing[] = $mFile;
            }
        }

        if (!empty($missing)) {
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
                if (!empty($fileConfig)) {
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
        if (!is_file($file)) {
            return [];
        }
        try {
            $content = file_get_contents($file);
            // Interpolate environment variables: ${VAR:-default}
            $content = preg_replace_callback('/\$\{([^}:]+)(?::-([^}]*))?\}/', function($matches) {
                $varName = trim($matches[1]);
                $envValue = getenv($varName);
                if ($envValue === false && isset($_ENV[$varName])) $envValue = $_ENV[$varName];
                if ($envValue === false && isset($_SERVER[$varName])) $envValue = $_SERVER[$varName];
                
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
            if (!$mode) {
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
        if (self::getAppMode() === 'demo') {
            return true;
        }
        
        try {
            $config = self::getProjectConfig();
            $projectName = getenv('PROJECT_NAME') ?: ($config['project'] ?? '');
            if (str_contains(strtolower($projectName), 'demo')) {
                return true;
            }
        } catch (\Exception $e) {}

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
        $isPostgres = ($dbConfig['driver'] === 'pdo_pgsql');

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
        $isPostgres = ($dbConfig['driver'] === 'pdo_pgsql');

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
                
                if (!$isPgsql) {
                    $config['driverOptions'] = [
                        1002 => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci' // PDO::MYSQL_ATTR_INIT_COMMAND
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
        return ($config['driver'] === 'pdo_pgsql');
    }

    /**
     * @return array
     */
    public static function getChannelsConfig(): array
    {
        if (self::$channelsConfig === null) {
            $projectConfig = self::getProjectConfig();
            $config = $projectConfig['channels'] ?? [];

            $filePath = __DIR__ . '/../../config/yaml/channelsconfig.yaml';
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

                // Explicit environment variable mappings
                $envOverrides = [
                    'GOOGLE_CLIENT_ID' => ['google', 'client_id'],
                    'GOOGLE_CLIENT_SECRET' => ['google', 'client_secret'],
                    'GOOGLE_REFRESH_TOKEN' => ['google', 'refresh_token'],
                    'GOOGLE_REDIRECT_URI' => ['google', 'redirect_uri'],
                    'GOOGLE_USER_ID' => ['google', 'user_id'],
                    'GOOGLE_SEARCH_CONSOLE_CLIENT_ID' => ['google_search_console', 'client_id'],
                    'GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET' => ['google_search_console', 'client_secret'],
                    'GOOGLE_SEARCH_CONSOLE_REFRESH_TOKEN' => ['google_search_console', 'refresh_token'],
                    'GOOGLE_SEARCH_CONSOLE_TOKEN' => ['google_search_console', 'token'],
                    'FACEBOOK_APP_ID' => ['facebook', 'app_id'],
                    'FACEBOOK_APP_SECRET' => ['facebook', 'app_secret'],
                    'FACEBOOK_REDIRECT_URI' => ['facebook', 'app_redirect_uri'],
                    'FACEBOOK_USER_TOKEN' => ['facebook', 'graph_user_access_token'],
                    'FACEBOOK_PAGE_TOKEN' => ['facebook', 'graph_page_access_token'],
                    'FACEBOOK_TOKEN_PATH' => ['facebook', 'graph_token_path'],
                    'FACEBOOK_USER_ID' => ['facebook', 'user_id'],
                    'FACEBOOK_ACCOUNTS_GROUP' => ['facebook', 'accounts_group_name'],
                ];

                foreach ($envOverrides as $envKey => $configPath) {
                    $val = getenv($envKey);
                    if ($val !== false && $val !== '') {
                        if (count($configPath) === 2) {
                            $config[$configPath[0]][$configPath[1]] = $val;
                        }
                    }
                }

                // --- 🛡️ SMART STORAGE AUTH MAPPING ---
                // If we have a local stored token, prioritize it
                // Resolve token path
                $tokenPath = $_ENV['FACEBOOK_TOKEN_PATH'] ?? $config['facebook']['graph_token_path'] ?? './storage/tokens/facebook_tokens.json';
                if (is_string($tokenPath) && str_starts_with($tokenPath, './')) {
                    $tokenPath = dirname(__DIR__, 2) . substr($tokenPath, 1);
                }
                
                if (file_exists($tokenPath)) {
                    $tokens = json_decode(file_get_contents($tokenPath), true);
                    $marketingToken = $tokens['facebook_marketing']['access_token'] ?? null;
                    $marketingUserId = $tokens['facebook_marketing']['user_id'] ?? null;
                    
                    if ($marketingToken) {
                        // OVERRIDE ABSOLUTO: Inyectamos el token en todas las claves posibles
                        $config['facebook']['graph_user_access_token'] = $marketingToken;
                        $config['facebook_marketing']['graph_user_access_token'] = $marketingToken;
                        $config['facebook_marketing']['access_token'] = $marketingToken;
                        
                        // Inyectamos también en variables de entorno para máxima compatibilidad
                        $_ENV['FACEBOOK_USER_TOKEN'] = $marketingToken;
                        putenv("FACEBOOK_USER_TOKEN=" . $marketingToken);
                    }
                    if ($marketingUserId) {
                        $config['facebook']['user_id'] = $marketingUserId;
                        $config['facebook_marketing']['user_id'] = $marketingUserId;
                        $_ENV['FACEBOOK_USER_ID'] = $marketingUserId;
                        putenv("FACEBOOK_USER_ID=" . $marketingUserId);
                    }
                }
                // ---------------------------------

                // Normalize relative paths to project root to avoid CWD issues
                $rootPath = dirname(__DIR__, 2);
                $resolvePath = function($path) use ($rootPath) {
                    if (is_string($path) && str_starts_with($path, './')) {
                        return $rootPath . substr($path, 1);
                    }
                    return $path;
                };

                if (isset($config['google']['token_path'])) {
                    $config['google']['token_path'] = $resolvePath($config['google']['token_path']);
                }
                if (isset($config['google_search_console']['token_path'])) {
                    $config['google_search_console']['token_path'] = $resolvePath($config['google_search_console']['token_path']);
                }
                if (isset($config['facebook']['graph_token_path'])) {
                    $config['facebook']['graph_token_path'] = $resolvePath($config['facebook']['graph_token_path']);
                }

                if (getenv('APP_ENV') === 'demo') {
                    // Smart Clean: Only remove static example placeholders 
                    // from the .example files, but keep real or seeded data.
                    $placeholders = ['PAGE_ID', 'IG_ACCOUNT_ID', 'PAGE_URL', 'example.com'];
                    
                    $cleanEntityList = function($entities) use ($placeholders) {
                        return array_filter($entities, function($item) use ($placeholders) {
                            $asString = json_encode($item);
                            foreach ($placeholders as $p) {
                                if (str_contains($asString, $p)) return false;
                            }
                            return true;
                        });
                    };

                    $config['facebook_marketing']['ad_accounts'] = $cleanEntityList($config['facebook_marketing']['ad_accounts'] ?? []);
                    $config['facebook_organic']['pages'] = $cleanEntityList($config['facebook_organic']['pages'] ?? []);
                    $config['google_search_console']['sites'] = $cleanEntityList($config['google_search_console']['sites'] ?? []);
                    $config['shopify']['stores'] = $cleanEntityList($config['shopify']['stores'] ?? []);
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
                if (!file_exists($filePath)) {
                    throw new RuntimeException("Entities configuration file not found: $filePath");
                }
                $config = Yaml::parseFile($filePath);
                if (!is_array($config)) {
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

                if (!isset($config['redis'])) {
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
     * Returns the first available Facebook access token from storage.
     * Used for system-level background jobs or demo-mode bypass.
     * 
     * @return string|null
     */
    public static function getSystemFacebookToken(): ?string
    {
        $config = self::getChannelsConfig();
        $tokenPath = $config['facebook']['graph_token_path'] ?? null;

        if ($tokenPath && file_exists($tokenPath)) {
            $tokens = json_decode(file_get_contents($tokenPath), true);
            if (!empty($tokens)) {
                $firstToken = reset($tokens);
                return $firstToken['access_token'] ?? null;
            }
        }
        
        // Fallback to legacy ENV if present
        return getenv('FACEBOOK_USER_TOKEN') ?: null;
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
        if (self::$entityManager === null || !self::$entityManager->isOpen()) {
            try {
                $config = self::getDbConfig();
                $connection = DriverManager::getConnection($config);

                // Create attribute metadata configuration
                $ormConfig = ORMSetup::createAttributeMetadataConfiguration(
                    paths: [__DIR__ . '/../Entities'],
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
        if (!$capitalizeFirst) {
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
            'year'   => 'Y',
            'month'  => 'M',
            'week'   => 'W',
            'day'    => 'D',
            'hour'   => 'H',
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
                if (method_exists($entity, self::toCamelcase('get_' . $prop->getName())) && (!$fields || in_array($prop->getName(), $fields))) {
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
        if (!$jobId) {
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
            'debug'     => Level::Debug,
            'info'      => Level::Info,
            'notice'    => Level::Notice,
            'warning'   => Level::Warning,
            'error'     => Level::Error,
            'critical'  => Level::Critical,
            'alert'     => Level::Alert,
            'emergency' => Level::Emergency,
            default     => Level::Info,
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

        if (!$enabled) {
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
            if (!self::isDebug() && $requested->value < $baseLevel->value) {
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
        if (!empty($include)) {
            $matchedInclude = false;
            $includes = is_array($include) ? $include : [$include];
            foreach ($includes as $pattern) {
                if (empty($pattern)) continue;
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
            if (!$matchedInclude) return false;
        }

        // If exclude is set, must NOT match any
        if (!empty($exclude)) {
            $excludes = is_array($exclude) ? $exclude : [$exclude];
            foreach ($excludes as $pattern) {
                if (empty($pattern)) continue;
                if (str_starts_with($pattern, '/') && str_ends_with($pattern, '/')) {
                    if (preg_match($pattern, $value)) return false;
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
                'end' => $currentEnd->format('Y-m-d')
            ];

            $currentStart = $currentEnd->copy()->addDay();
        }

        return $chunks;
    }

    /**
     * @param string $url
     * @param string|int|null $platformId
     * @param string|null $type
     * @return string
     */
    public static function getCanonicalPageId(string $url, string|int|null $platformId = null, string|null $type = null): string
    {
        if ($platformId) {
            if ($type === 'facebook_page') return "fb:page:$platformId";
            if ($type === 'instagram') return "ig:account:$platformId";
        }

        // Normalize URL for websites or fallback
        $normalizedUrl = preg_replace('~^https?://(?:www\.)?~i', '', $url);
        $normalizedUrl = rtrim($normalizedUrl, '/');
        $normalizedUrl = strtolower($normalizedUrl);
        
        // If it's a FB page URL but no platformId was given, try to extract it from URL
        if (str_contains($normalizedUrl, 'facebook.com/') && preg_match('~(\d+)/?$~', $normalizedUrl, $matches)) {
            return "fb:page:" . $matches[1];
        }

        return "site:domain:$normalizedUrl";
    }
}
