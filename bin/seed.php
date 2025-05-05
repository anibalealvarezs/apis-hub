<?php

declare(strict_types=1);

use Doctrine\Common\DataFixtures\Loader;
// use Fixtures\JobDataLoader;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;

$entityManager = require_once __DIR__ . "/../app/bootstrap.php";

$loader = new Loader();
// $loader->addFixture(new JobDataLoader());

$executor = new ORMExecutor($entityManager, new ORMPurger());
$executor->execute($loader->getFixtures(), append: true);
