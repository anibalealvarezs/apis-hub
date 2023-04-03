<?php
// bootstrap.php
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__ . "/../vendor/autoload.php";

$connectionParams = Yaml::parseFile(__DIR__ . "/../config/yaml/dbconfig.yaml");
$conn = DriverManager::getConnection($connectionParams);

$config = ORMSetup::createAttributeMetadataConfiguration(paths: array(__DIR__."/../src"), isDevMode: true);

// obtaining the entity manager
$entityManager = EntityManager::create($conn, $config);
