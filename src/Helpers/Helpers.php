<?php

namespace Helpers;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\Mapping\MappingException;
use Doctrine\Persistence\Proxy;
use Entities\Entity;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionObject;
use ReflectionProperty;
use Symfony\Component\Yaml\Yaml;

class Helpers
{
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
            $property->setAccessible(true);
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
    public static function getConfig(): array
    {
        return Yaml::parseFile(__DIR__ . "/../../config/yaml/dbconfig.yaml");
    }

    /**
     * @return array
     */
    public static function getConnectionParams(): array
    {
        return self::getConfig()['connection_params'];
    }

    /**
     * @return EntityManager
     * @throws Exception
     * @throws ORMException
     */
    public static function getManager(): EntityManager
    {
        $connectionParams = self::getConnectionParams();
        $conn = DriverManager::getConnection($connectionParams);
        $config = ORMSetup::createAttributeMetadataConfiguration(paths: array(__DIR__."/.."), isDevMode: true);
        return EntityManager::create($conn, $config);
    }

    /**
     * @return array
     */
    public static function getCrudEntities(): array
    {
        return self::getConfig()['entities'];
    }

    /**
     * @return array
     */
    public static function getEnabledCrudEntities(): array
    {
        return array_filter(self::getConfig()['entities'], function ($entity) {
            if ($entity['crud_enabled']) {
                return $entity;
            };
            return false;
        });
    }

    /**
     * @param string $string
     * @return string
     */
    public static function toCamelcase(string $string): string
    {
        $str = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $string)));
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
}
