<?php

use Helpers\Helpers;
use Doctrine\ORM\Exception\ORMException;

require_once __DIR__ . "/../vendor/autoload.php";

// Return the entity manager
// return Helpers::getSingletonManager();
try {
    return Helpers::getManager();
} catch (\Doctrine\DBAL\Exception|ORMException $e) {

}
