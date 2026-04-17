<?php

namespace Commands\Analytics;

use Anibalealvarezs\ApiSkeleton\Enums\Channel;
use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ResetChannelCommand extends Command
{
    protected static $defaultName = 'app:reset-channel';
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
    }

    protected function configure()
    {
        $this
            ->setDescription('Reset a specific channel (Atómico)')
            ->addArgument('channel', InputArgument::REQUIRED, 'The channel name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channelName = $input->getArgument('channel');
        $manager = $this->entityManager;
        $connection = $manager->getConnection();
        $isPostgres = strpos($connection->getDatabasePlatform()->getName(), 'postgresql') !== false;

        try {
            $enum = Channel::tryFromName($channelName);
            if (!$enum) {
                throw new \Exception("Unknown channel: $channelName.");
            }

            $channelId = $enum->value;
            $channelSlug = $enum->name;

            $output->writeln("<info>🚀 Starting ATOMIC RESET for channel: $channelName...</info>");

            $driver = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::get($channelSlug);
            if ($driver) {
                $output->writeln("<info>  Calling modular reset for $channelName...</info>");
                $driver->reset('all', ['manager' => $manager]);
            } else {
                $output->writeln("<comment>  ⚠ Warning: No driver found for $channelSlug. Performing generic cleanup if possible.</comment>");
            }

            if ($isPostgres) {
                // Dimensions Cleanup (Shared logic across channels)
                $output->writeln('<info>  Pruning orphaned Dimensions...</info>');
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

                // Global Pruning of Base Entities
                $output->writeln('<info>  Pruning orphaned base campaigns/creatives/posts...</info>');
                $connection->executeStatement("DELETE FROM campaigns WHERE id NOT IN (SELECT campaign_id FROM channeled_campaigns)");
                $connection->executeStatement("DELETE FROM creatives WHERE id NOT IN (SELECT creative_id FROM channeled_ads WHERE creative_id IS NOT NULL)");
                $connection->executeStatement("DELETE FROM posts WHERE channeled_account_id IS NULL AND id NOT IN (SELECT post_id FROM metric_configs WHERE post_id IS NOT NULL)");
            } else {
                throw new \Exception("The Atomic Reset is currently optimized for PostgreSQL environments.");
            }

            // Flush Redis Cache
            $output->writeln('<info>🧹 Purgando Caché de Aplicación...</info>');
            $clearCacheCommand = $this->getApplication()->find('app:cache:clear');
            $clearCacheCommand->run(new \Symfony\Component\Console\Input\ArrayInput([]), $output);

            $output->writeln("<info>✅ EXCELENTE: El canal '$channelName' ha sido reseteado de forma atómica y está listo para re-sync.</info>");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln("<error>✘ Error Atómico: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }
}
