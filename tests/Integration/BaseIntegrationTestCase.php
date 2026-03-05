<?php

namespace Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

abstract class BaseIntegrationTestCase extends TestCase
{
    protected ?EntityManager $entityManager = null;
    protected array $config = [];

    protected function setUp(): void
    {
        parent::setUp();
        
        // Load the actual configuration
        // In the real environment, bootstrap.php injects $GLOBALS['app_config']
        $this->config = $GLOBALS['app_config'] ?? [];

        // Set up the Doctrine annotations mapping
        $paths = [__DIR__ . '/../../src/Entities'];
        $isDevMode = true;

        // Enable configuration of the test database via Environment Variables or local dbconfig.yaml
        // It strictly defaults to appending '-test' to the existing DB name to prevent data loss.
        $defaultConfig = class_exists(\Helpers\Helpers::class) ? \Helpers\Helpers::getDbConfig() : [];

        $dbParams = [
            'driver'   => getenv('TEST_DB_DRIVER') ?: ($defaultConfig['driver'] ?? 'pdo_mysql'),
            'user'     => getenv('TEST_DB_USER') ?: ($defaultConfig['user'] ?? 'root'),
            'password' => getenv('TEST_DB_PASSWORD') !== false ? getenv('TEST_DB_PASSWORD') : ($defaultConfig['password'] ?? ''),
            'host'     => getenv('TEST_DB_HOST') ?: ($defaultConfig['host'] ?? '127.0.0.1'),
            'port'     => getenv('TEST_DB_PORT') ?: ($defaultConfig['port'] ?? 3306),
        ];

        $testDbName = getenv('TEST_DB_NAME') ?: (($defaultConfig['dbname'] ?? 'apis-hub') . '-test');

        // 1. Create a configuration block for Doctrine
        $config = ORMSetup::createAttributeMetadataConfiguration($paths, $isDevMode);

        // 2. Connect without dbname to safely auto-create the testing database
        $tmpConnection = DriverManager::getConnection($dbParams, $config);
        $tmpConnection->executeStatement("CREATE DATABASE IF NOT EXISTS `{$testDbName}`");
        $tmpConnection->close();

        // 3. Connect formally to the test database
        $dbParams['dbname'] = $testDbName;
        $connection = DriverManager::getConnection($dbParams, $config);

        // 3. Create the EntityManager
        $this->entityManager = new EntityManager($connection, $config);

        // 4. Automatically generate the schema for testing
        $schemaTool = new SchemaTool($this->entityManager);
        $classes = $this->entityManager->getMetadataFactory()->getAllMetadata();
        
        if (!empty($classes)) {
            $schemaTool->dropSchema($classes);
            $schemaTool->createSchema($classes);
        }

        // Force the main application to use our transactional test EntityManager
        if (class_exists(\Helpers\Helpers::class)) {
            $reflection = new \ReflectionClass(\Helpers\Helpers::class);
            $property = $reflection->getProperty('entityManager');
            $property->setAccessible(true);
            $property->setValue(null, $this->entityManager);
        }
    }

    protected function tearDown(): void
    {
        if ($this->entityManager) {
            $this->entityManager->close();
            $this->entityManager = null;
        }
        parent::tearDown();
    }

    /**
     * Helper method to safely retrieve config keys
     */
    protected function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }
}
