<?php

namespace Commands;

use Commands\Analytics\InvalidateSyncCacheCommand;
use Commands\Analytics\ScheduleInitialJobsCommand;
use Doctrine\ORM\EntityManagerInterface;
use Helpers\Helpers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:nuclear-resync',
    description: 'Clears jobs and telemetry cache for all or a specific channel, then reschedules initial jobs.'
)]
class NuclearResyncCommand extends Command
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Target channels (comma-separated)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channelArg = $input->getOption('channel');
        
        if (empty($channelArg)) {
            $output->writeln("<error>No channels provided for resync.</error>");
            return Command::FAILURE;
        }

        $channels = array_filter(array_map('trim', explode(',', $channelArg)));
        if (empty($channels)) {
            $output->writeln("<error>Invalid channel list provided.</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>Nuclear Resync starting for channels: <comment>" . implode(', ', $channels) . "</comment></info>");

        // 1. Delete jobs
        try {
            $conn = $this->entityManager->getConnection();
            $deleted = $conn->executeStatement(
                "DELETE FROM jobs WHERE channel IN (?)",
                [array_values($channels)],
                [\Doctrine\DBAL\ArrayParameterType::STRING]
            );
            $output->writeln("<info>✓ Deleted $deleted jobs for targeted channels.</info>");
        } catch (\Throwable $e) {
            $output->writeln("<error>✗ DB error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }

        // 2. Clear telemetry Redis cache
        foreach ($channels as $channel) {
            try {
                $redis = Helpers::getRedisClient();
                $pattern = "sync_telemetry:{$channel}:*";
                $keys = $redis->keys($pattern);
                if (! empty($keys)) {
                    $redis->del($keys);
                    $output->writeln("<info>✓ Cleared " . count($keys) . " Redis telemetry keys for $channel.</info>");
                } else {
                    $output->writeln("<comment>No Redis telemetry keys found for pattern: $pattern</comment>");
                }
            } catch (\Throwable $e) {
                $output->writeln("<error>✗ Redis telemetry cache clear error for $channel: " . $e->getMessage() . "</error>");
            }
        }

        // 2.5. Fallback telemetry cache clear
        foreach ($channels as $channel) {
            try {
                $invalidateSyncCacheCmd = new InvalidateSyncCacheCommand();
                $invalidateSyncCacheInput = new ArrayInput(['--channel' => $channel]);
                $invalidateSyncCacheCmd->run($invalidateSyncCacheInput, $output);
                $output->writeln("<info>✓ Fallback Redis telemetry cache cleared for $channel.</info>");
            } catch (\Throwable $e) {
                $output->writeln("<error>✗ Fallback Redis telemetry cache clear error for $channel: " . $e->getMessage() . "</error>");
            }
        }

        // 2.75. Refresh Instances Configurations to guarantee up-to-date end dates
        $output->writeln("<info>Refreshing instance configurations...</info>");
        try {
            $refreshCmd = new \Commands\RefreshInstancesCommand();
            $refreshInput = new ArrayInput([]);
            $refreshCmd->run($refreshInput, $output);
            $output->writeln("<info>✓ Instances configuration refreshed.</info>");
        } catch (\Throwable $e) {
            $output->writeln("<error>✗ Refresh instances error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }

        // 3. Schedule initial jobs
        $output->writeln("<info>Scheduling initial jobs...</info>");

        foreach ($channels as $channel) {
            try {
                $scheduleCmd = new ScheduleInitialJobsCommand($this->entityManager);
                $scheduleInput = new ArrayInput(['--channel' => $channel]);
                $scheduleCmd->run($scheduleInput, $output);
                $output->writeln("<info>✓ Initial jobs scheduled for $channel.</info>");
            } catch (\Throwable $e) {
                $output->writeln("<error>✗ Schedule error for $channel: " . $e->getMessage() . "</error>");
            }
        }

        $output->writeln("<comment>Worker restart is now handled externally by bin/nuclear-sync.sh to ensure graceful container lifecycle management.</comment>");

        // 4. Force invalidate telemetry cache at the end of the process
        foreach ($channels as $channel) {
            try {
                $output->writeln("<info>Invalidating telemetry cache for $channel...</info>");
                $invalidateSyncCacheCmd = new InvalidateSyncCacheCommand();
                $invalidateSyncCacheInput = new ArrayInput(['--channel' => $channel]);
                $invalidateSyncCacheCmd->run($invalidateSyncCacheInput, $output);
                $output->writeln("<info>✓ Telemetry cache successfully invalidated for $channel.</info>");
            } catch (\Throwable $e) {
                $output->writeln("<error>✗ Telemetry cache invalidation error for $channel: " . $e->getMessage() . "</error>");
            }
        }

        $output->writeln("<info>✓ Nuclear Resync complete.</info>");

        return Command::SUCCESS;
    }
}
