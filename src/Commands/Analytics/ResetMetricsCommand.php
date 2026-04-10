<?php

namespace Commands\Analytics;

use Anibalealvarezs\ApiSkeleton\Enums\Channel;
use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ResetMetricsCommand extends Command
{
    protected static $defaultName = 'app:reset-metrics';
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
    }

    protected function configure()
    {
        $this
            ->setDescription('Reset metrics for selected channels or ALL')
            ->addArgument('channel', InputArgument::OPTIONAL, 'Channel name (e.g. facebook, all. Default: all)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channelName = $input->getArgument('channel');
        $manager = $this->entityManager;
        $connection = $manager->getConnection();
        $isPostgres = strpos($connection->getDatabasePlatform()->getName(), 'postgresql') !== false;

        try {
            if ($channelName) {
                // Determine target channel names for job matching
                $jobChannels = [];

                if (strtolower($channelName) === 'all') {
                    $jobChannels = DriverFactory::getAvailableChannels();
                } elseif (strtolower($channelName) === 'facebook') {
                    $jobChannels = ['facebook_marketing', 'facebook_organic'];
                } else {
                    $enum = Channel::tryFromName($channelName);
                    if (!$enum) {
                        throw new \Exception("Unknown channel: $channelName. Use all, facebook, or any specific channel name.");
                    }
                    $jobChannels = [$enum->name];
                }

                $output->writeln('<info>  Performing modular metrics reset for each channel...</info>');
                foreach ($jobChannels as $chanSlug) {
                    $driver = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::get($chanSlug);
                    if ($driver) {
                        $output->writeln("<info>    - Resetting metrics for $chanSlug...</info>");
                        $driver->reset($manager, 'metrics');
                    }
                }

                // Cleanup orphaned dimension sets and items
                if ($isPostgres) {
                    $output->writeln('<info>  Cleaning orphaned dimension sets and items...</info>');
                    $connection->executeStatement("
                        DELETE FROM dimension_set_items 
                        WHERE dimension_set_id NOT IN (SELECT DISTINCT dimension_set_id FROM channeled_metrics WHERE dimension_set_id IS NOT NULL)
                        AND dimension_set_id NOT IN (SELECT DISTINCT dimension_set_id FROM metric_configs WHERE dimension_set_id IS NOT NULL)
                    ");
                    $connection->executeStatement("
                        DELETE FROM dimension_sets 
                        WHERE id NOT IN (SELECT DISTINCT dimension_set_id FROM channeled_metrics WHERE dimension_set_id IS NOT NULL)
                        AND id NOT IN (SELECT DISTINCT dimension_set_id FROM metric_configs WHERE dimension_set_id IS NOT NULL)
                    ");

                    $connection->executeStatement("DELETE FROM dimension_values WHERE id NOT IN (SELECT dimension_value_id FROM dimension_set_items)");
                    $connection->executeStatement("DELETE FROM dimension_keys WHERE id NOT IN (SELECT dimension_key_id FROM dimension_values)");
                }
                
            } else {
                // Global TRUNCATE
                $tables = [
                    'channeled_metrics',
                    'metrics',
                    'metric_configs',
                    'dimension_values',
                    'dimension_keys',
                    'dimension_sets',
                    'queries'
                ];

                if ($isPostgres) {
                    $connection->executeStatement("DELETE FROM jobs WHERE entity = 'metric'");
                    $tableList = implode(', ', $tables);
                    $connection->executeStatement("TRUNCATE TABLE $tableList CASCADE");
                } else {
                    $connection->executeStatement("SET FOREIGN_KEY_CHECKS = 0");
                    $connection->executeStatement("DELETE FROM jobs WHERE entity = 'metric'");
                    foreach ($tables as $table) {
                        $connection->executeStatement("TRUNCATE TABLE $table");
                    }
                    $connection->executeStatement("SET FOREIGN_KEY_CHECKS = 1");
                }
            }

            $output->writeln("<info>✅ Metrics were successfully reset for: $channelName</info>");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln("<error>✘ Error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }
}
