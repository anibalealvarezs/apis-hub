<?php

namespace Commands\Analytics;

use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ResetEntitiesCommand extends Command
{
    protected static $defaultName = 'app:reset-entities';
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
    }

    protected function configure()
    {
        $this
            ->setDescription('Reset entities for a specific channel or all channels')
            ->addArgument('channel', InputArgument::OPTIONAL, 'The channel name or all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channelName = $input->getArgument('channel') ?: 'all';
        $manager = $this->entityManager;
        $connection = $manager->getConnection();
        $isPostgres = strpos($connection->getDatabasePlatform()->getName(), 'postgresql') !== false;

        try {
            if (strtolower($channelName) === 'all') {
                $targetChannelSlugs = DriverFactory::getAvailableChannels();
            } elseif (str_starts_with(strtolower($channelName), 'all_')) {
                $group = substr(strtolower($channelName), 4);
                $targetChannelSlugs = [];
                foreach (DriverFactory::getRegistry() as $slug => $config) {
                    $driverClass = $config['driver'] ?? null;
                    if ($driverClass && class_exists($driverClass) && $driverClass::getCommonConfigKey() === $group) {
                        $targetChannelSlugs[] = $slug;
                    }
                }
                if (empty($targetChannelSlugs)) {
                    throw new \Exception("No channels found for group: $group");
                }
            } else {
                $chanObj = $manager->getRepository(\Entities\Analytics\Channel::class)->findOneBy(['name' => $channelName]);
                if (!$chanObj) {
                    throw new \Exception("Unknown channel: $channelName. Use all or any specific channel name.");
                }
                $targetChannelSlugs = [$chanObj->getName()];
            }

            $output->writeln('<info>🗑  Resetting Entities for selected channels...</info>');

            if ($isPostgres) {
                $output->writeln('<info>  Performing modular reset for each channel...</info>');
                
                foreach ($targetChannelSlugs as $slug) {
                    $driver = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::get($slug);
                    if ($driver) {
                        $output->writeln("<info>    - Resetting entities for $slug...</info>");
                        $driver->reset('entities', ['manager' => $manager]);
                    }
                }

                // 4. Cleanup Orphaned Base Entities
                $output->writeln('<info>  Pruning orphaned base entities...</info>');
                $connection->executeStatement("DELETE FROM campaigns WHERE id NOT IN (SELECT campaign_id FROM channeled_campaigns)");
                $connection->executeStatement("DELETE FROM creatives WHERE id NOT IN (SELECT creative_id FROM channeled_ads WHERE creative_id IS NOT NULL)");
                $connection->executeStatement("DELETE FROM posts WHERE channeled_account_id IS NULL AND id NOT IN (SELECT post_id FROM metric_configs WHERE post_id IS NOT NULL)");
                
            } else {
                throw new \Exception("The Entities Reset is currently optimized for PostgreSQL environments.");
            }

            $output->writeln("<info>✅ Entities were successfully reset for: $channelName</info>");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln("<error>✘ Error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }
}
