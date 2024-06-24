<?php

declare(strict_types=1);

use Commands\Crud\CreateEntityCommand;
use Commands\Crud\DeleteEntityCommand;
use Commands\Crud\ReadEntityCommand;
use Commands\Crud\UpdateEntityCommand;
use Doctrine\ORM\Tools\Console\ConsoleRunner;
use Symfony\Component\Console\Application;

$entityManager = require_once __DIR__ . "/../app/bootstrap.php";
$helperSet = require_once __DIR__ . "/../config/cli-config.php";

$cli = new Application(
    name: 'Doctrine Command Line Interface',
    version: '1.0.0'
);
$cli->setCatchExceptions(true);
$cli->setHelperSet($helperSet);

// Register All Doctrine Commands
ConsoleRunner::addCommands($cli);

// Register your own command
$cli->addCommands([
    new CreateEntityCommand(),
    new DeleteEntityCommand(),
    new ReadEntityCommand(),
    new UpdateEntityCommand()
]);

// Runs console application
$cli->run();
