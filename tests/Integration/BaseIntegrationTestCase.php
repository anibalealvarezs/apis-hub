<?php

namespace Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

abstract class BaseIntegrationTestCase extends TestCase
{
    protected static ?EntityManager $staticEntityManager = null;
    protected ?EntityManager $entityManager = null;
    protected \Faker\Generator $faker;
    protected array $config = [];
    protected static bool $schemaCreated = false;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->faker = \Faker\Factory::create();
        $this->config = $GLOBALS['app_config'] ?? [];

        // Ensure we're not in demo mode for tests
        putenv('APP_ENV=testing');
        putenv('APP_MODE=testing');
        putenv('PROJECT_NAME=testing');
        putenv('ENV_FILE=/dev/null'); // Prevent loading real .env files
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['APP_MODE'] = 'testing';
        $_ENV['PROJECT_NAME'] = 'testing';
        $_ENV['ENV_FILE'] = '/dev/null';

        // Explicitly clear some persistent demo vars that might have leaked from bootstrap
        putenv('GOOGLE_REFRESH_TOKEN');
        putenv('GOOGLE_SEARCH_CONSOLE_REFRESH_TOKEN');
        unset($_ENV['GOOGLE_REFRESH_TOKEN'], $_ENV['GOOGLE_SEARCH_CONSOLE_REFRESH_TOKEN']);

        if (class_exists(\Helpers\Helpers::class)) {
            \Helpers\Helpers::resetConfigs();
        }

        if (self::$staticEntityManager === null || !self::$staticEntityManager->isOpen()) {
            $this->initializeStaticEntityManager();
        }

        $this->entityManager = self::$staticEntityManager;
        $this->entityManager->clear();
        
        // Inject EM into Helpers
        if (class_exists(\Helpers\Helpers::class)) {
            $reflection = new \ReflectionClass(\Helpers\Helpers::class);
            $property = $reflection->getProperty('entityManager');
            $property->setAccessible(true);
            $property->setValue(null, $this->entityManager);
        }

        // Start transaction for speed and isolation
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            while ($this->entityManager->getConnection()->getTransactionNestingLevel() > 0) {
                $this->entityManager->getConnection()->rollBack();
            }
        }
        $this->entityManager->getConnection()->beginTransaction();
    }

    private function initializeStaticEntityManager(): void
    {
        $paths = [__DIR__ . '/../../src/Entities'];
        $isDevMode = true;

        $defaultConfig = class_exists(\Helpers\Helpers::class) ? \Helpers\Helpers::getDbConfig() : [];

        $dbParams = [
            'driver'   => getenv('TEST_DB_DRIVER') ?: ($defaultConfig['driver'] ?? 'pdo_mysql'),
            'user'     => getenv('TEST_DB_USER') ?: ($defaultConfig['user'] ?? 'root'),
            'password' => getenv('TEST_DB_PASSWORD') !== false ? getenv('TEST_DB_PASSWORD') : ($defaultConfig['password'] ?? ''),
            'host'     => getenv('TEST_DB_HOST') ?: ($defaultConfig['host'] ?? '127.0.0.1'),
            'port'     => getenv('TEST_DB_PORT') ?: ($defaultConfig['port'] ?? 3306),
        ];

        $testDbName = getenv('TEST_DB_NAME') ?: (($defaultConfig['dbname'] ?? 'apis-hub') . '-test');
        $config = ORMSetup::createAttributeMetadataConfiguration($paths, $isDevMode);

        // Safely create DB if not exists
        $tmpConnection = DriverManager::getConnection($dbParams, $config);
        if ($dbParams['driver'] === 'pdo_pgsql') {
            $exists = $tmpConnection->fetchOne("SELECT 1 FROM pg_database WHERE datname = '{$testDbName}'");
            if (!$exists) {
                $tmpConnection->executeStatement("CREATE DATABASE \"{$testDbName}\"");
            }
        } else {
            $tmpConnection->executeStatement("CREATE DATABASE IF NOT EXISTS `{$testDbName}`");
        }
        $tmpConnection->close();

        $dbParams['dbname'] = $testDbName;
        $connection = DriverManager::getConnection($dbParams, $config);
        self::$staticEntityManager = new EntityManager($connection, $config);

        if (!self::$schemaCreated) {
            $schemaTool = new SchemaTool(self::$staticEntityManager);
            $classes = self::$staticEntityManager->getMetadataFactory()->getAllMetadata();
            
            try {
                // Ensure schema exists
                $schemaTool->createSchema($classes);
            } catch (\Exception $e) {
                // Ignore if schema already exists
                $schemaTool->updateSchema($classes);
            }

            // Perform one-time TRUNCATE for a clean slate
            $tableNames = [];
            foreach ($classes as $class) {
                $classMetadata = self::$staticEntityManager->getClassMetadata($class->getName());
                if ($classMetadata->isMappedSuperclass) continue;
                $tableNames[] = ($dbParams['driver'] === 'pdo_pgsql' ? "\"{$classMetadata->getTableName()}\"" : "`{$classMetadata->getTableName()}`");
            }

            if (!empty($tableNames)) {
                $allTables = implode(', ', $tableNames);
                if ($dbParams['driver'] === 'pdo_pgsql') {
                    self::$staticEntityManager->getConnection()->executeStatement("TRUNCATE TABLE {$allTables} RESTART IDENTITY CASCADE");
                } else {
                    self::$staticEntityManager->getConnection()->executeStatement("SET FOREIGN_KEY_CHECKS = 0");
                    foreach ($tableNames as $tbl) {
                        self::$staticEntityManager->getConnection()->executeStatement("TRUNCATE TABLE {$tbl}");
                    }
                    self::$staticEntityManager->getConnection()->executeStatement("SET FOREIGN_KEY_CHECKS = 1");
                }
            }
            self::$schemaCreated = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->entityManager) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->getConnection()->rollBack();
            }
        }
        parent::tearDown();
    }

    protected function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }
}
