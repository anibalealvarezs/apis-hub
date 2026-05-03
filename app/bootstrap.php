<?php

use Helpers\Helpers;
use Doctrine\ORM\Exception\ORMException;

require_once __DIR__ . "/../vendor/autoload.php";

// Set timezone from config
Helpers::applyTimezone();

// Boot modular drivers
\Classes\DriverInitializer::bootDrivers();

// Return the entity manager
try {
    return Helpers::getManager();
} catch (\Doctrine\DBAL\Exception|ORMException $e) {

}
