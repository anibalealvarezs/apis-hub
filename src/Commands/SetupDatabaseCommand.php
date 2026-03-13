<?php

namespace Commands;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Helpers\Helpers;
use Symfony\Component\Console\Command\Command;
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
            $connection = DriverManager::getConnection($dbConfig);
            
            // Use a more robust check: try to create if not exists
            $connection->executeStatement("CREATE DATABASE IF NOT EXISTS `{$dbName}`");
            $output->writeln("<info>✔  Database '{$dbName}' is ready.</info>");
            $connection->close();

            // 2. Update Schema
            $output->writeln("<info>📂 Updating schema structure...</info>");
            $schemaUpdateCommand = $this->getApplication()->find('orm:schema-tool:update');
            $schemaInput = new ArrayInput(['--force' => true]);
            $schemaUpdateCommand->run($schemaInput, $output);

            // 3. Initialize Entities
            $output->writeln("<info>🌱 Seeding initial entities...</info>");
            $initEntitiesCommand = $this->getApplication()->find('app:initialize-entities');
            $initEntitiesCommand->run(new ArrayInput([]), $output);

            $output->writeln("<info>✅ Database setup complete!</info>");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln("<error>✘ Error during database setup: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }
}
