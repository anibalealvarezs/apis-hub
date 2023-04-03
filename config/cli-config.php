<?php
// cli-config.php
use Doctrine\ORM\Tools\Console\ConsoleRunner;

require_once __DIR__ . "/../app/bootstrap.php";

return ConsoleRunner::createHelperSet($entityManager);