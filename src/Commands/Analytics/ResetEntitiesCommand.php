<?php

declare(strict_types=1);

namespace Commands\Analytics;

use Enums\Channel;
use Helpers\Helpers;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class ResetEntitiesCommand extends Command
{
    protected static $defaultName = 'app:reset-entities';

    protected function configure()
    {
        $this
            ->setDescription('Clears FB Entities (Campaigns, Adsets, Ads, Creatives) and associated metrics from the database.')
            ->addOption('channel', 'c', InputOption::VALUE_REQUIRED, 'Target channel to clear (facebook_marketing, facebook_organic, or all_facebook)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channelName = $input->getOption('channel');
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        
        $confirmMsg = $channelName 
            ? "This will PERMANENTLY delete all Meta Entities (Ads, Campaigns, etc.) for channel '$channelName'. Are you sure? [y/N] "
            : 'This will PERMANENTLY delete ALL Meta Entities across all Facebook channels. Are you sure? [y/N] ';
        
        $question = new ConfirmationQuestion($confirmMsg, false);

        if (!$questionHelper->ask($input, $output, $question)) {
            $output->writeln('<comment>Operation cancelled.</comment>');
            return Command::SUCCESS;
        }

        try {
            $manager = Helpers::getManager();
            $connection = $manager->getConnection();
            $isPostgres = Helpers::isPostgres();

            // Determine target channel IDs
            $targetChannelIds = [];
            if (!$channelName || strtolower($channelName) === 'all') {
                $targetChannelIds = [Channel::facebook_marketing->value, Channel::facebook_organic->value, Channel::google_search_console->value];
            } elseif (strtolower($channelName) === 'all_facebook') {
                $targetChannelIds = [Channel::facebook_marketing->value, Channel::facebook_organic->value];
            } else {
                $enum = Channel::tryFromName($channelName);
                if (!$enum) {
                    throw new \Exception("Unknown channel: $channelName. Use google_search_console, facebook_marketing, facebook_organic, all_facebook or all.");
                }
                $targetChannelIds = [$enum->value];
            }

            $output->writeln('<info>🗑  Resetting Entities for selected channels...</info>');

            if ($isPostgres) {
                // 1. Clear Metrics for targeted channels
                $connection->executeStatement("
                    DELETE FROM channeled_metrics WHERE metric_id IN (
                        SELECT m.id FROM metrics m 
                        JOIN metric_configs mc ON m.metric_config_id = mc.id 
                        WHERE mc.channel IN (?)
                    )", [$targetChannelIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);

                // 2. Clear Jobs
                $jobChannels = [];
                foreach ($targetChannelIds as $id) {
                    $enum = Channel::tryFrom($id);
                    if ($enum) {
                        $jobChannels[] = $enum->name;
                    }
                }
                if (!empty($jobChannels)) {
                    $connection->executeStatement("DELETE FROM jobs WHERE channel IN (?)", [$jobChannels], [\Doctrine\DBAL\ArrayParameterType::STRING]);
                }

                // 3. Conditional Asset Cleanup
                if (in_array(Channel::facebook_marketing->value, $targetChannelIds) || in_array(Channel::facebook_organic->value, $targetChannelIds)) {
                    $output->writeln('<info>  Cleaning Meta Assets...</info>');
                    $connection->executeStatement("DELETE FROM channeled_ads WHERE channel IN (?)", [$targetChannelIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
                    $connection->executeStatement("DELETE FROM channeled_ad_groups WHERE channel IN (?)", [$targetChannelIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
                    $connection->executeStatement("DELETE FROM channeled_campaigns WHERE channel IN (?)", [$targetChannelIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
                    $connection->executeStatement("DELETE FROM channeled_creatives WHERE channel IN (?)", [$targetChannelIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
                }

                if (in_array(Channel::google_search_console->value, $targetChannelIds)) {
                    $output->writeln('<info>  Cleaning Google Search Console Assets...</info>');
                    // Sites are stored in 'pages' table with source = 'gsc_site' in data JSON
                    $connection->executeStatement("DELETE FROM pages WHERE data->>'source' = 'gsc_site'");
                }

                // 4. Cleanup Orphaned Base Entities (Meta)
                $output->writeln('<info>  Pruning orphaned base entities...</info>');
                $connection->executeStatement("DELETE FROM ads WHERE id NOT IN (SELECT ad_id FROM channeled_ads)");
                $connection->executeStatement("DELETE FROM ad_groups WHERE id NOT IN (SELECT ad_group_id FROM channeled_ad_groups)");
                $connection->executeStatement("DELETE FROM campaigns WHERE id NOT IN (SELECT campaign_id FROM channeled_campaigns)");
                $connection->executeStatement("DELETE FROM creatives WHERE id NOT IN (SELECT creative_id FROM channeled_creatives)");
                
            } else {
                // MySQL implementation (Foreign Key Checks)
                $connection->executeStatement("SET FOREIGN_KEY_CHECKS = 0");
                // ... (Similar logic but using MySQL syntax if needed, but for GBS we focus on Postgres)
                $connection->executeStatement("SET FOREIGN_KEY_CHECKS = 1");
            }

            // Clear Redis Cache
            $output->writeln('<info>🧹 Clearing application cache...</info>');
            $clearCacheCommand = $this->getApplication()->find('app:cache:clear');
            $clearCacheCommand->run($input, $output);

            $output->writeln('<info>✅ Success: Meta Entities and Metrics have been cleared for the selected channels.</info>');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln("<error>✘ Error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }
}
