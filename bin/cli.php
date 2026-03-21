<?php

declare(strict_types=1);

require_once __DIR__ . "/../vendor/autoload.php";

use Commands\Analytics\ScheduleInitialJobsCommand;
use Commands\Analytics\CacheEntityCommand;
use Commands\Analytics\ProcessJobsCommand;
use Commands\Analytics\ClearCacheCommand;
use Commands\Analytics\CheckCoverageCommand;
use Commands\Analytics\InspectJobsCommand;
use Commands\Analytics\AnalyzeLogsCommand;
use Commands\Analytics\ResetMetricsCommand;
use Commands\HealthCheckCommand;
use Commands\Crud\AggregateEntityCommand;
use Commands\Crud\CreateEntityCommand;
use Commands\Crud\DeleteEntityCommand;
use Commands\Crud\ReadEntityCommand;
use Commands\Crud\UpdateEntityCommand;
use Commands\GenerateEntitiesConfigCommand;
use Commands\InitializeEntitiesCommand;
use Commands\RefreshInstancesCommand;
use Commands\SetupDatabaseCommand;
use Commands\SeedDemoDataCommand;
use Doctrine\ORM\Tools\Console\ConsoleRunner;
use Helpers\Helpers;
use Symfony\Component\Console\Application;

try {
    $cliConfig = Helpers::getCliConfig();
    ini_set('memory_limit', $cliConfig['memory_limit'] ?? '1G');

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
        new UpdateEntityCommand(),
        new GenerateEntitiesConfigCommand(),
        new InitializeEntitiesCommand(Helpers::getManager()),
        new ScheduleInitialJobsCommand($entityManager),
        new CacheEntityCommand(),
        new ProcessJobsCommand($entityManager),
        new ClearCacheCommand(),
        new CheckCoverageCommand(),
        new InspectJobsCommand(),
        new AnalyzeLogsCommand(),
        new HealthCheckCommand(),
        new AggregateEntityCommand(),
        new RefreshInstancesCommand(),
        new SetupDatabaseCommand(),
        new SeedDemoDataCommand(Helpers::getManager()),
        new ResetMetricsCommand(),
    ]);

    // Runs console application
    $cli->run();
} catch (\Exceptions\ConfigurationException $e) {
    fwrite(STDERR, "\n[Configuration Error]\n" . $e->getMessage() . "\n\n");
    exit(1);
} catch (Exception $e) {
    fwrite(STDERR, "\n[Internal Error]\n" . $e->getMessage() . "\n\n");
    exit(1);
}
