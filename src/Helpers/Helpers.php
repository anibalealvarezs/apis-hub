<?php

namespace Helpers;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\MissingMappingDriverImplementation;
use Doctrine\ORM\Exception\ORMException;
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

class Helpers
{
    private static ?EntityManager $entityManager = null;
    private static ?ClientInterface $redisClient = null;
    private static ?array $cacheConfig = null;
    private static ?array $dbConfig = null;
    private static ?array $channelsConfig = null;
    private static ?array $entitiesConfig = null;

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
     * @return array
     */
    public static function getDbConfig(): array
    {
        if (self::$dbConfig === null) {
            $filePath = __DIR__ . '/../../config/yaml/dbconfig.yaml';
            try {
                if (!file_exists($filePath)) {
                    throw new RuntimeException("Database configuration file not found: $filePath");
                }
                $config = Yaml::parseFile($filePath);
                if (!is_array($config)) {
                    throw new RuntimeException("Invalid database configuration: $filePath must return an array");
                }
                self::$dbConfig = $config;
            } catch (Exception $e) {
                throw new RuntimeException("Failed to load database configuration: " . $e->getMessage());
            }
        }
        return self::$dbConfig;
    }

    /**
     * @return array
     */
    public static function getChannelsConfig(): array
    {
        if (self::$channelsConfig === null) {
            $filePath = __DIR__ . '/../../config/yaml/channelsconfig.yaml';
            try {
                if (!file_exists($filePath)) {
                    throw new RuntimeException("Channels configuration file not found: $filePath");
                }
                $config = Yaml::parseFile($filePath);
                if (!is_array($config)) {
                    throw new RuntimeException("Invalid channels configuration: $filePath must return an array");
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
            $filePath = __DIR__ . '/../../config/yaml/entitiesconfig.yaml';
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
            $filePath = __DIR__ . '/../../config/yaml/cacheconfig.yaml';
            try {
                if (!file_exists($filePath)) {
                    throw new RuntimeException("Cache configuration file not found: $filePath");
                }
                $config = Yaml::parseFile($filePath);
                if (!is_array($config)) {
                    throw new RuntimeException("Invalid cache configuration: $filePath must return an array");
                }
                self::$cacheConfig = $config;
            } catch (Exception $e) {
                throw new RuntimeException("Failed to load cache configuration: " . $e->getMessage());
            }
        }
        return self::$cacheConfig;
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
                $connection = DriverManager::getConnection(self::getDbConfig());
                $config = ORMSetup::createAttributeMetadataConfiguration(
                    paths: [__DIR__ . '/..'],
                    isDevMode: true
                );
                self::$entityManager = new EntityManager($connection, $config);
            } catch (Exception $e) {
                throw new RuntimeException('Failed to initialize EntityManager: ' . $e->getMessage());
            }
        }

        return self::$entityManager;
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
     * @return string
     */
    public static function toCamelcase(string $string): string
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
        $str[0] = strtolower($str[0]);
        return $str;
    }

    /**
     * @param Entity $entity
     * @param array|null $fields
     * @return array
     * @throws ReflectionException
     */
    public static function jsonSerialize(Entity $entity, array $fields = null): array
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
    public static function dataToObject(string $data = null): object
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
    public static function bodyToObject(string $data = null): object
    {
        if ($data) {
            return json_decode($data);
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
        $stringMatrix = array_map(function($array) {
            return json_encode($array);
        }, $multiDimensionalArray);
        // Remove duplicates based on the string representation
        $uniqueStringMatrix = array_unique($stringMatrix);
        // Convert back to the original multidimensional array
        return array_map(function($variant) {
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
}
