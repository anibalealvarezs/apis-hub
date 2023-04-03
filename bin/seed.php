<?php

use Doctrine\Common\DataFixtures\Loader;
// use Fixtures\JobDataLoader;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;

require_once __DIR__ . "/../app/bootstrap.php";

$loader = new Loader();
// $loader->addFixture(new JobDataLoader());
$fixtures = $loader->getFixtures();

$executor = new ORMExecutor($entityManager, new ORMPurger());
$executor->execute($loader->getFixtures(), append: true);
