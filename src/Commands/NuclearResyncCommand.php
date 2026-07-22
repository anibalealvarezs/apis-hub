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
        $this->addOption('asset', null, InputOption::VALUE_OPTIONAL, 'Target single asset/account ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = Helpers::setLogger('nuclear_resync.log');
        $channelArg = $input->getOption('channel');
        $assetArg = $input->getOption('asset');
        
        if (empty($channelArg)) {
            $logger->error("No channels provided for resync.");
            $output->writeln("<error>No channels provided for resync.</error>");
            return Command::FAILURE;
        }

        $channels = array_filter(array_map('trim', explode(',', $channelArg)));
        if (empty($channels)) {
            $logger->error("Invalid channel list provided.");
            $output->writeln("<error>Invalid channel list provided.</error>");
            return Command::FAILURE;
        }

        $assetInfo = $assetArg ? " (Asset: {$assetArg})" : "";
        $logger->info("Nuclear Resync starting for channels: " . implode(', ', $channels) . $assetInfo);
        $output->writeln("<info>Nuclear Resync starting for channels: <comment>" . implode(', ', $channels) . "</comment>{$assetInfo}</info>");

        // 1. Delete jobs
        try {
            $conn = $this->entityManager->getConnection();
            if ($assetArg) {
                $cleanAsset = str_replace(['act_', 'properties/', 'sc-domain:', 'https://', 'http://'], '', $assetArg);
                $cleanAsset = rtrim($cleanAsset, '/');
                $md5Asset = md5($assetArg);
                $md5Clean = md5($cleanAsset);

                $deleted = $conn->executeStatement(
                    "DELETE FROM jobs WHERE channel IN (?) AND (
                        CAST(payload AS text) LIKE ? 
                        OR CAST(payload AS text) LIKE ?
                        OR CAST(payload AS text) LIKE ?
                        OR CAST(payload AS text) LIKE ?
                        OR CAST(payload AS JSONB)->'params'->>'account_id' IN (?, ?, ?, ?, ?) 
                        OR CAST(payload AS JSONB)->>'account_id' IN (?, ?, ?, ?, ?)
                    )",
                    [
                        array_values($channels),
                        '%' . $assetArg . '%',
                        '%' . $cleanAsset . '%',
                        '%' . $md5Asset . '%',
                        '%' . $md5Clean . '%',
                        $assetArg, 'act_' . $cleanAsset, 'properties/' . $cleanAsset, $md5Asset, $md5Clean,
                        $assetArg, 'act_' . $cleanAsset, 'properties/' . $cleanAsset, $md5Asset, $md5Clean
                    ],
                    [
                        \Doctrine\DBAL\ArrayParameterType::STRING,
                        \Doctrine\DBAL\ParameterType::STRING,
                        \Doctrine\DBAL\ParameterType::STRING,
                        \Doctrine\DBAL\ParameterType::STRING,
                        \Doctrine\DBAL\ParameterType::STRING,
                        \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING,
                        \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING
                    ]
                );
            } else {
                $deleted = $conn->executeStatement(
                    "DELETE FROM jobs WHERE channel IN (?)",
                    [array_values($channels)],
                    [\Doctrine\DBAL\ArrayParameterType::STRING]
                );
            }
            $logger->info("Deleted $deleted jobs for targeted channels{$assetInfo}.");
            $output->writeln("<info>✓ Deleted $deleted jobs for targeted channels{$assetInfo}.</info>");
        } catch (\Throwable $e) {
            $logger->error("DB error deleting jobs: " . $e->getMessage());
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
                    $logger->info("Cleared " . count($keys) . " Redis telemetry keys for $channel.");
                    $output->writeln("<info>✓ Cleared " . count($keys) . " Redis telemetry keys for $channel.</info>");
                } else {
                    $output->writeln("<comment>No Redis telemetry keys found for pattern: $pattern</comment>");
                }
            } catch (\Throwable $e) {
                $logger->error("Redis telemetry cache clear error for $channel: " . $e->getMessage());
                $output->writeln("<error>✗ Redis telemetry cache clear error for $channel: " . $e->getMessage() . "</error>");
            }
        }

        // 2.5. Fallback telemetry cache clear
        foreach ($channels as $channel) {
            try {
                $invalidateSyncCacheCmd = new InvalidateSyncCacheCommand();
                $invalidateSyncCacheInput = new ArrayInput(['--channel' => $channel]);
                $invalidateSyncCacheCmd->run($invalidateSyncCacheInput, $output);
                $logger->info("Fallback Redis telemetry cache cleared for $channel.");
                $output->writeln("<info>✓ Fallback Redis telemetry cache cleared for $channel.</info>");
            } catch (\Throwable $e) {
                $logger->error("Fallback Redis telemetry cache clear error for $channel: " . $e->getMessage());
                $output->writeln("<error>✗ Fallback Redis telemetry cache clear error for $channel: " . $e->getMessage() . "</error>");
            }
        }

        // 2.75. Refresh Instances Configurations to guarantee up-to-date end dates
        $output->writeln("<info>Refreshing instance configurations...</info>");
        try {
            $refreshCmd = new \Commands\RefreshInstancesCommand();
            $refreshInput = new ArrayInput([]);
            $refreshCmd->run($refreshInput, $output);
            $logger->info("Instances configuration refreshed.");
            $output->writeln("<info>✓ Instances configuration refreshed.</info>");
        } catch (\Throwable $e) {
            $logger->error("Refresh instances error: " . $e->getMessage());
            $output->writeln("<error>✗ Refresh instances error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }

        // 3. Schedule initial jobs
        $output->writeln("<info>Scheduling initial jobs...</info>");

        foreach ($channels as $channel) {
            try {
                $scheduleCmd = new ScheduleInitialJobsCommand($this->entityManager);
                $scheduleInputParams = ['--channel' => $channel];
                if ($assetArg) {
                    $scheduleInputParams['--asset'] = $assetArg;
                }
                $scheduleInput = new ArrayInput($scheduleInputParams);
                $scheduleCmd->run($scheduleInput, $output);
                $logger->info("Initial jobs scheduled for $channel{$assetInfo}.");
                $output->writeln("<info>✓ Initial jobs scheduled for $channel{$assetInfo}.</info>");
            } catch (\Throwable $e) {
                $logger->error("Schedule error for $channel: " . $e->getMessage());
                $output->writeln("<error>✗ Schedule error for $channel: " . $e->getMessage() . "</error>");
            }
        }

        $output->writeln("<comment>Worker restart is now handled externally by bin/nuclear-sync.sh to ensure graceful container lifecycle management.</comment>");

        // 4. Force invalidate telemetry cache at the end of the process
        $telemetryService = new \Services\Sync\SyncTelemetryService(new \Services\CacheService(Helpers::getRedisClient()));
        foreach ($channels as $channel) {
            try {
                $output->writeln("<info>Invalidating telemetry cache for $channel...</info>");
                $telemetryService->invalidate($channel, $assetArg);
                $invalidateSyncCacheCmd = new InvalidateSyncCacheCommand();
                $invalidateSyncCacheInput = new ArrayInput(['--channel' => $channel]);
                $invalidateSyncCacheCmd->run($invalidateSyncCacheInput, $output);
                $logger->info("Telemetry cache successfully invalidated for $channel.");
                $output->writeln("<info>✓ Telemetry cache successfully invalidated for $channel.</info>");
            } catch (\Throwable $e) {
                $logger->error("Telemetry cache invalidation error for $channel: " . $e->getMessage());
                $output->writeln("<error>✗ Telemetry cache invalidation error for $channel: " . $e->getMessage() . "</error>");
            }
        }

        $logger->info("Nuclear Resync complete for channels: " . implode(', ', $channels) . $assetInfo);
        $output->writeln("<info>✓ Nuclear Resync complete.</info>");

        return Command::SUCCESS;
    }
}
