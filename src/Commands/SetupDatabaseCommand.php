<?php

    namespace Commands;

    use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
    use Doctrine\DBAL\DriverManager;
    use Doctrine\ORM\EntityManager;
    use Exception;
    use Helpers\Helpers;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Exception\ExceptionInterface;
    use Symfony\Component\Console\Input\ArrayInput;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Output\OutputInterface;

    class SetupDatabaseCommand extends Command
    {
        protected static $defaultName = 'app:setup-db';

        protected function configure()
        {
            $this
                ->setDescription('Ensures the database exists, updates the schema, and seeds default entities.');
        }

        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            $dbConfig = Helpers::getDbConfig();
            $dbName = $dbConfig['dbname'];
            unset($dbConfig['dbname']); // Remove dbname for initial connection

            try {
                // 1. Ensure Database Exists
                $output->writeln("<info>🔍 Ensuring database '{$dbName}' exists...</info>");

                $isPostgres = $dbConfig['driver'] === 'pdo_pgsql';

                if (!$isPostgres) {
                    $connection = DriverManager::getConnection($dbConfig);
                    $connection->executeStatement("CREATE DATABASE IF NOT EXISTS `{$dbName}`");
                    $connection->close();
                } else {
                    // For PostgreSQL, the DB is typically created by Docker via environment variables.
                    // If not, we try to connect with the full config; if it fails, the DB is missing.
                    $output->writeln("<comment>  Notice: Assuming database '{$dbName}' is already created by Docker/Postgres environment.</comment>");
                }
                $output->writeln("<info>✔  Database '{$dbName}' check passed.</info>");

                // 2. Update Schema
                $output->writeln("<info>📂 Updating schema structure...</info>");
                $schemaUpdateCommand = $this->getApplication()->find('orm:schema-tool:update');
                $schemaInput = new ArrayInput(['--force' => true]);
                $schemaUpdateCommand->run($schemaInput, $output);

                // 3. Install Drivers (Dynamic Registry)
                $output->writeln("<info>🔌 Registration of Providers and Channels...</info>");
                $installDriversCommand = $this->getApplication()->find('app:install-drivers');
                $exitCode = $installDriversCommand->run(new ArrayInput([]), $output);
                if ($exitCode !== Command::SUCCESS) {
                    throw new Exception("Failed to install/register drivers.");
                }

                // 4. Initialize Entities
                $output->writeln("<info>🌱 Seeding initial entities...</info>");
                $initEntitiesCommand = $this->getApplication()->find('app:initialize-entities');
                $initExitCode = $initEntitiesCommand->run(new ArrayInput([]), $output);
                if ($initExitCode !== Command::SUCCESS) {
                    error_log("CRITICAL: app:initialize-entities failed with exit code: ".$initExitCode);
                    $output->writeln("<error>CRITICAL: app:initialize-entities failed with exit code: {$initExitCode}</error>");
                    throw new Exception("Failed to initialize core/channel entities.");
                }

                // 5. Auto-Seed for Demo (Smart Zero-Touch)
                $skipSeed = (string)getenv('SKIP_SEED');
                if (getenv('APP_ENV') === 'demo' && $skipSeed !== '1' && $skipSeed !== 'true') {
                    $output->writeln("<info>🎁 Environment is 'demo'. Filling with sample data...</info>");
                    $seedDemoCommand = $this->getApplication()->find('app:seed-demo-data');
                    $channels = implode(',', DriverFactory::getAvailableChannels());
                    $seedDemoCommand->run(new ArrayInput(['--channels' => $channels]), $output);
                }

                $output->writeln("<info>✅ Database setup complete!</info>");

                return Command::SUCCESS;

            } catch (Exception|ExceptionInterface $e) {
                $output->writeln("<error>✘ Error during database setup: ".$e->getMessage()."</error>");

                return Command::FAILURE;
            }
        }
    }
