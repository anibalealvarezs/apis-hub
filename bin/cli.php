<?php

use Commands\CreateEntityCommand;
use Commands\DeleteEntityCommand;
use Commands\ReadEntityCommand;
use Commands\UpdateEntityCommand;
use Doctrine\ORM\Tools\Console\ConsoleRunner;
use Doctrine\ORM\Version;
use Symfony\Component\Console\Application;

require_once __DIR__ . "/../app/bootstrap.php";

$helperSet = ConsoleRunner::createHelperSet($entityManager);

// as before ...

// replace the ConsoleRunner::run() statement with:
$cli = new Application('Doctrine Command Line Interface', Version::VERSION);
$cli->setCatchExceptions(true);
$cli->setHelperSet($helperSet);

// Register All Doctrine Commands
ConsoleRunner::addCommands($cli);

// Register your own command
$cli->addCommands([new CreateEntityCommand()]);
$cli->addCommands([new DeleteEntityCommand()]);
$cli->addCommands([new ReadEntityCommand()]);
$cli->addCommands([new UpdateEntityCommand()]);

// Runs console application
$cli->run();
