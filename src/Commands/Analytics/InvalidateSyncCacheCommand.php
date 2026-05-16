<?php

    namespace Commands\Analytics;

    use Helpers\Helpers;
    use Services\Sync\SyncTelemetryService;
    use Services\CacheService;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Input\InputOption;
    use Symfony\Component\Console\Output\OutputInterface;

    class InvalidateSyncCacheCommand extends Command
    {
        protected static string $defaultName = 'cache:invalidate-sync';

        protected function configure(): void
        {
            $this
                ->setDescription('Invalidates the sync telemetry cache.')
                ->addOption('channel', 'c', InputOption::VALUE_OPTIONAL, 'The channel to invalidate.')
                ->addOption('account', 'a', InputOption::VALUE_OPTIONAL, 'The account ID to invalidate (requires --channel).');
        }

        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            $channel = $input->getOption('channel');
            $account = $input->getOption('account');

            $redisClient = Helpers::getRedisClient();
            $cacheService = new CacheService($redisClient);
            $telemetryService = new SyncTelemetryService($cacheService);

            $telemetryService->invalidate($channel, $account);

            if ($channel && $account) {
                $output->writeln("<info>✅ Invalidated cache for account '$account' in channel '$channel'.</info>");
            } elseif ($channel) {
                $output->writeln("<info>✅ Invalidated cache for channel '$channel'.</info>");
            } else {
                $output->writeln("<info>✅ Invalidated global sync telemetry cache.</info>");
            }

            return Command::SUCCESS;
        }
    }
