<?php

namespace Commands\Analytics;

use Enums\Channel;
use Helpers\Helpers;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class ResetMetricsCommand extends Command
{
    protected static $defaultName = 'app:reset-metrics';

    protected function configure()
    {
        $this
            ->setDescription('Clears metrics, configurations, dimensions, and metric-syncing jobs from the database.')
            ->addOption('channel', 'c', InputOption::VALUE_REQUIRED, 'Target channel to clear (e.g. facebook, facebook_marketing)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channelName = $input->getOption('channel');
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        
        $confirmMsg = $channelName 
            ? "This will PERMANENTLY delete all metrics and metric-syncing jobs for channel '$channelName'. Entity caching history will be preserved. Are you sure? [y/N] "
            : 'This will PERMANENTLY delete ALL metrics, dimension data, and metric-syncing jobs across all channels. Are you sure? [y/N] ';
        
        $question = new ConfirmationQuestion($confirmMsg, false);

        if (!$questionHelper->ask($input, $output, $question)) {
            $output->writeln('<comment>Operation cancelled.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln($channelName 
            ? "<info>🗑  Cleaning metrics and metric-syncing jobs for channel: $channelName...</info>" 
            : '<info>🗑  Cleaning ALL metrics and metric-syncing jobs...</info>'
        );

        try {
            $manager = Helpers::getManager();
            $connection = $manager->getConnection();
            $isPostgres = Helpers::isPostgres();

            if ($channelName) {
                // Determine target channel IDs and names for job matching
                $targetIds = [];
                $jobChannels = [];

                if (strtolower($channelName) === 'all') {
                    $targetIds = [Channel::facebook_marketing->value, Channel::facebook_organic->value, Channel::google_search_console->value];
                    $jobChannels = ['facebook_marketing', 'facebook_organic', 'google_search_console'];
                } elseif (strtolower($channelName) === 'facebook') {
                    $targetIds = [Channel::facebook_marketing->value, Channel::facebook_organic->value];
                    $jobChannels = ['facebook_marketing', 'facebook_organic'];
                } else {
                    $enum = Channel::tryFromName($channelName);
                    if (!$enum) {
                        throw new \Exception("Unknown channel: $channelName. Use all, facebook, facebook_marketing, or google_search_console.");
                    }
                    $targetIds = [$enum->value];
                    $jobChannels = [$enum->name];
                }

                // Targeted DELETE
                if ($isPostgres) {
                    // 1. Delete Jobs first
                    $connection->executeStatement(
                        "DELETE FROM jobs WHERE channel IN (?) AND entity = 'metric'",
                        [$jobChannels],
                        [\Doctrine\DBAL\ArrayParameterType::STRING]
                    );

                    // 2. Delete Channeled Metrics
                    $connection->executeStatement("
                        DELETE FROM channeled_metrics WHERE metric_id IN (
                            SELECT m.id FROM metrics m 
                            JOIN metric_configs mc ON m.metric_config_id = mc.id 
                            WHERE mc.channel IN (?)
                        )", [$targetIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
                    
                    // 3. Delete Metrics
                    $connection->executeStatement("
                        DELETE FROM metrics WHERE metric_config_id IN (
                            SELECT id FROM metric_configs WHERE channel IN (?)
                        )", [$targetIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
                    
                    // 4. Delete Configs
                    $connection->executeStatement("DELETE FROM metric_configs WHERE channel IN (?)", [$targetIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
                } else {
                    $connection->executeStatement("SET FOREIGN_KEY_CHECKS = 0");
                    
                    $connection->executeStatement(
                        "DELETE FROM jobs WHERE channel IN (?) AND entity = 'metric'",
                        [$jobChannels],
                        [\Doctrine\DBAL\ArrayParameterType::STRING]
                    );

                    $connection->executeStatement("
                        DELETE cm FROM channeled_metrics cm
                        JOIN metrics m ON cm.id = m.id
                        JOIN metric_configs mc ON m.metric_config_id = mc.id
                        WHERE mc.channel IN (?)
                    ", [$targetIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
                    
                    $connection->executeStatement("
                        DELETE m FROM metrics m
                        JOIN metric_configs mc ON m.metric_config_id = mc.id
                        WHERE mc.channel IN (?)
                    ", [$targetIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
                    
                    $connection->executeStatement("DELETE FROM metric_configs WHERE channel IN (?)", [$targetIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
                    $connection->executeStatement("SET FOREIGN_KEY_CHECKS = 1");
                }

                // Cleanup orphaned dimension sets and items
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

                // Optional: Prune unused dimension values and keys to ensure absolute reset
                $connection->executeStatement("DELETE FROM dimension_values WHERE id NOT IN (SELECT dimension_value_id FROM dimension_set_items)");
                $connection->executeStatement("DELETE FROM dimension_keys WHERE id NOT IN (SELECT dimension_key_id FROM dimension_values)");
                
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

            // Always clear cache
            $output->writeln('<info>🧹 Clearing analytics cache...</info>');
            $clearCacheCommand = $this->getApplication()->find('app:cache:clear');
            $clearCacheCommand->run($input, $output);

            $output->writeln('<info>✅ Success: Metrics have been cleared.</info>');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln("<error>✘ Error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }
}
