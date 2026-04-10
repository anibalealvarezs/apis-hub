<?php

declare(strict_types=1);

namespace Commands\Analytics;

use Anibalealvarezs\ApiDriverCore\Enums\Channel;
use Helpers\Helpers;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class ResetChannelCommand extends Command
{
    protected static $defaultName = 'app:reset-channel';

    protected function configure()
    {
        $this
            ->setDescription('Performs a TOTAL reset of a channel, clearing all entities, metrics, dimensions, and jobs to prepare for fresh redeployment.')
            ->addOption('channel', 'c', InputOption::VALUE_REQUIRED, 'Target channel to wipe (e.g. facebook_marketing, facebook_organic, google_search_console)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channelName = $input->getOption('channel');
        if (!$channelName) {
            $output->writeln('<error>✘ Error: Missing --channel option. You must specify a channel to reset.</error>');
            return Command::FAILURE;
        }

        /** @var \Symfony\Component\Console\Helper\QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        
        $confirmMsg = "This will PERMANENTLY wipe ALL data (Entities, Metrics, Jobs, Dimensions) for channel '$channelName'. ARE YOU ABSOLUTELY SURE? [y/N] ";
        $question = new ConfirmationQuestion($confirmMsg, false);

        if (!$questionHelper->ask($input, $output, $question)) {
            $output->writeln('<comment>Operation cancelled.</comment>');
            return Command::SUCCESS;
        }

        try {
            $manager = Helpers::getManager();
            $connection = $manager->getConnection();
            $isPostgres = Helpers::isPostgres();
            
            $enum = Channel::tryFromName($channelName);
            if (!$enum) {
                throw new \Exception("Unknown channel: $channelName. Use facebook_marketing, facebook_organic, instagram, google_search_console, etc.");
            }

            $channelId = $enum->value;
            $channelSlug = $enum->name;

            $output->writeln("<info>🚀 Starting ATOMIC RESET for channel: $channelName...</info>");

            if ($isPostgres) {
                // 1. Clear Jobs (Entity and Metric jobs)
                $output->writeln('<info>  [1/5] Terminating existing Jobs/Workers...</info>');
                $connection->executeStatement(
                    "DELETE FROM jobs WHERE channel = ?",
                    [$channelSlug],
                    [\Doctrine\DBAL\ParameterType::STRING]
                );

                // 2. Clear Metrics and Configurations
                $output->writeln('<info>  [2/5] Purging Metrics and Configs...</info>');
                // Clear Channeled Metrics
                $connection->executeStatement("
                    DELETE FROM channeled_metrics WHERE metric_id IN (
                        SELECT m.id FROM metrics m 
                        JOIN metric_configs mc ON m.metric_config_id = mc.id 
                        WHERE mc.channel = ?
                    )", [$channelId], [\Doctrine\DBAL\ParameterType::INTEGER]);

                // Clear Metrics
                $connection->executeStatement("
                    DELETE FROM metrics WHERE metric_config_id IN (
                        SELECT id FROM metric_configs WHERE channel = ?
                    )", [$channelId], [\Doctrine\DBAL\ParameterType::INTEGER]);

                // Clear Metric Configs
                $connection->executeStatement("DELETE FROM metric_configs WHERE channel = ?", [$channelId], [\Doctrine\DBAL\ParameterType::INTEGER]);

                // 3. Clear Entities and Assets
                $output->writeln('<info>  [3/5] Cleaning Entities and Master Pages...</info>');
                
                // Clear Channel-Specific Assets
                $connection->executeStatement("DELETE FROM channeled_ads WHERE channel = ?", [$channelId], [\Doctrine\DBAL\ParameterType::INTEGER]);
                $connection->executeStatement("DELETE FROM channeled_ad_groups WHERE channel = ?", [$channelId], [\Doctrine\DBAL\ParameterType::INTEGER]);
                $connection->executeStatement("DELETE FROM channeled_campaigns WHERE channel = ?", [$channelId], [\Doctrine\DBAL\ParameterType::INTEGER]);

                // Clear Posts associated with the channel
                $connection->executeStatement("
                    DELETE FROM posts 
                    WHERE channeled_account_id IN (SELECT id FROM channeled_accounts WHERE channel = ?)
                ", [$channelId], [\Doctrine\DBAL\ParameterType::INTEGER]);

                // CRITICAL FACEBOOK/IG PAGE CLEANUP (Source of Ghost IDs)
                $connection->executeStatement("
                    DELETE FROM pages 
                    WHERE account_id IN (SELECT id FROM channeled_accounts WHERE channel = ?)
                    OR (data->>'source' = 'gsc_site' AND ? = " . Channel::google_search_console->value . ")
                ", [$channelId, $channelId], [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER]);

                // 4. Dimensions Cleanup
                $output->writeln('<info>  [4/5] Pruning orphaned Dimensions...</info>');
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

                // 5. Global Pruning of Base Entities
                $output->writeln('<info>  [5/5] Pruning orphaned base campaigns/creatives...</info>');
                $connection->executeStatement("DELETE FROM campaigns WHERE id NOT IN (SELECT campaign_id FROM channeled_campaigns)");
                $connection->executeStatement("DELETE FROM creatives WHERE id NOT IN (SELECT creative_id FROM channeled_ads WHERE creative_id IS NOT NULL)");

            } else {
                throw new \Exception("The Atomic Reset is currently optimized for PostgreSQL environments.");
            }

            // Flush Redis Cache
            $output->writeln('<info>🧹 Purgando Caché de Aplicación...</info>');
            $clearCacheCommand = $this->getApplication()->find('app:cache:clear');
            $clearCacheCommand->run($input, $output);

            $output->writeln("<info>✅ EXCELENTE: El canal '$channelName' ha sido reseteado de forma atómica y está listo para re-sync.</info>");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln("<error>✘ Error Atómico: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }
}
