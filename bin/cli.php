<?php

    declare(strict_types=1);

    require_once __DIR__."/../vendor/autoload.php";

    use Commands\Analytics\InvalidateSyncCacheCommand;
    use Commands\Analytics\ScaleDownCommand;
    use Commands\Analytics\ScheduleInitialJobsCommand;
    use Commands\Analytics\CacheEntityCommand;
    use Commands\Analytics\ProcessJobsCommand;
    use Commands\Analytics\ClearCacheCommand;
    use Commands\Analytics\CheckCoverageCommand;
    use Commands\Analytics\InspectJobsCommand;
    use Commands\Analytics\AnalyzeLogsCommand;
    use Commands\Analytics\ReportAggregationTelemetryCommand;
    use Commands\Analytics\PlanMetricConfigIndexesCommand;
    use Commands\Analytics\ResetMetricsCommand;
    use Commands\Analytics\ResetEntitiesCommand;
    use Commands\Analytics\ResetChannelCommand;
    use Commands\Analytics\RetryFailedJobCommand;
    use Commands\Analytics\RetryFailedJobsCommand;
    use Commands\Analytics\SwooleWorkerCommand;
    use Commands\HealthCheckCommand;
    use Commands\Crud\AggregateEntityCommand;
    use Commands\Crud\CreateEntityCommand;
    use Commands\Crud\DeleteEntityCommand;
    use Commands\Crud\ReadEntityCommand;
    use Commands\Crud\UpdateEntityCommand;
    use Commands\GenerateEntitiesConfigCommand;
    use Commands\Infrastructure\ScaleWorkersCommand;
    use Commands\InitializeDefaultEntitiesCommand;
    use Commands\InitializeEntitiesCommand;
    use Commands\RefreshInstancesCommand;
    use Commands\SetupDatabaseCommand;
    use Commands\SeedDemoDataCommand;
    use Commands\MigratePagesCanonicalCommand;
    use Commands\InstallDriversCommand;
    use Doctrine\ORM\Tools\Console\ConsoleRunner;
    use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
    use Exceptions\ConfigurationException;
    use Helpers\Helpers;
    use Symfony\Component\Console\Application;
    use Symfony\Component\Console\Command\Command as SymfonyCommand;
    use Monolog\ErrorHandler;
    use Symfony\Component\Console\Helper\QuestionHelper;

    try {
        $logger = Helpers::setLogger('cli.log');
        ErrorHandler::register($logger);
        $cliConfig = Helpers::getCliConfig();
        ini_set('memory_limit', $cliConfig['memory_limit'] ?? '1G');

        $entityManager = require_once __DIR__."/../app/bootstrap.php";
        $helperSet = null;

        $cli = new Application(
            name: 'Doctrine Command Line Interface',
            version: '1.0.0'
        );
        $cli->setCatchExceptions(true);
        // Doctrine ORM 3 removed EntityManagerHelper; prefer provider-based registration.
        if (class_exists(SingleManagerProvider::class)) {
            ConsoleRunner::addCommands($cli, new SingleManagerProvider($entityManager));
        } else {
            $helperSet = require_once __DIR__."/../config/cli-config.php";
            // Register All Doctrine Helpers to the existing HelperSet (legacy ORM 2 path)
            $cli->getHelperSet()->set($helperSet->get('em'), 'em');
            if ($helperSet->has('db')) {
                $cli->getHelperSet()->set($helperSet->get('db'), 'db');
            }
            // Register All Doctrine Commands
            ConsoleRunner::addCommands($cli);
        }
        // Ensure default helpers are registered
        if (!$cli->getHelperSet()->has('question')) {
            $cli->getHelperSet()->set(new QuestionHelper(), 'question');
        }

        // Register your own commands
        $commands = [
            new InvalidateSyncCacheCommand(),
            new CreateEntityCommand(),
            new DeleteEntityCommand(),
            new ReadEntityCommand(),
            new UpdateEntityCommand(),
            new GenerateEntitiesConfigCommand(),
            new InitializeDefaultEntitiesCommand(Helpers::getManager()),
            new InitializeEntitiesCommand(Helpers::getManager()),
            new ScheduleInitialJobsCommand($entityManager),
            new CacheEntityCommand(),
            new ProcessJobsCommand($entityManager),
            new ClearCacheCommand(),
            new CheckCoverageCommand(),
            new InspectJobsCommand(),
            new AnalyzeLogsCommand(),
            new ReportAggregationTelemetryCommand(),
            new PlanMetricConfigIndexesCommand(),
            new HealthCheckCommand(),
            new AggregateEntityCommand(),
            new RefreshInstancesCommand(),
            new SetupDatabaseCommand(),
            new SeedDemoDataCommand(Helpers::getManager()),
            new ResetMetricsCommand($entityManager),
            new ResetEntitiesCommand($entityManager),
            new ResetChannelCommand($entityManager),
            new SwooleWorkerCommand($entityManager),
            new ScaleDownCommand($entityManager),
            new MigratePagesCanonicalCommand(Helpers::getManager()),
            new InstallDriversCommand(),
            new ScaleWorkersCommand($entityManager),
            new RetryFailedJobCommand($entityManager),
            new RetryFailedJobsCommand($entityManager),
        ];

        foreach ($commands as $command) {
            if ($command instanceof SymfonyCommand && !$command->getName()) {
                $reflection = new ReflectionClass($command);
                if ($reflection->hasProperty('defaultName')) {
                    $prop = $reflection->getProperty('defaultName');
                    $prop->setAccessible(true);
                    $defaultName = $prop->getValue();
                    if (is_string($defaultName) && $defaultName !== '') {
                        $command->setName($defaultName);
                    }
                }
            }
        }

        $cli->addCommands($commands);

        // Runs console application
        $cli->run();
    } catch (ConfigurationException $e) {
        fwrite(STDERR, "\n[Configuration Error]\n".$e->getMessage()."\n\n");
        exit(1);
    } catch (Exception $e) {
        fwrite(STDERR, "\n[Internal Error]\n".$e->getMessage()."\n\n");
        exit(1);
    }