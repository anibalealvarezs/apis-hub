<?php

    declare(strict_types=1);

    namespace Commands\Analytics;

    use Exceptions\ConfigurationException;
    use Helpers\Helpers;
    use Services\CacheService;
    use Symfony\Component\Console\Attribute\AsCommand;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Input\InputOption;
    use Symfony\Component\Console\Output\OutputInterface;

    #[AsCommand(
        name: 'cache:clear',
        description: 'Clears the Redis cache (all, by channel, or by entity).',
        aliases: ['app:cache:clear'],
        hidden: false
    )]
    class ClearCacheCommand extends Command
    {
        private ?CacheService $cacheService;

        public function __construct(?CacheService $cacheService = null)
        {
            $this->cacheService = $cacheService;
            parent::__construct();
        }

        protected function configure(): void
        {
            $this
                ->addOption('channel', 'c', InputOption::VALUE_OPTIONAL, 'Clear cache for a specific channel')
                ->addOption('entity', 'e', InputOption::VALUE_OPTIONAL, 'Clear cache for a specific entity')
                ->addOption('all', 'a', InputOption::VALUE_NONE, 'Clear all cache');
        }

        /**
         * @throws ConfigurationException
         */
        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            $channel = $input->getOption('channel');
            $entity = $input->getOption('entity');
            $all = $input->getOption('all');

            $cache = $this->cacheService ?? CacheService::getInstance(Helpers::getRedisClient());

            if ($all) {
                if (Helpers::isDebug()) {
                    $output->writeln('<comment>Clearing all cache...</comment>');
                }
                $cache->deletePattern('*');
                if (Helpers::isDebug()) {
                    $output->writeln('<info>All cache cleared.</info>');
                }

                return self::SUCCESS;
            }

            if ($channel && $entity) {
                if (Helpers::isDebug()) {
                    $output->writeln("<comment>Clearing cache for channel '$channel' and entity '$entity'...</comment>");
                }
                // List and Count patterns for channeled entities
                $cache->deletePattern("channeled_list_{$entity}_{$channel}_*");
                $cache->deletePattern("channeled_count_{$entity}_{$channel}_*");
                // Standard ones just in case
                $cache->deletePattern("list_{$entity}_*");
                $cache->deletePattern("count_{$entity}_*");
                if (Helpers::isDebug()) {
                    $output->writeln('<info>Filtered cache cleared.</info>');
                }

                return self::SUCCESS;
            }

            if ($channel) {
                if (Helpers::isDebug()) {
                    $output->writeln("<comment>Clearing cache for channel '$channel'...</comment>");
                }
                $cache->deletePattern("*_{$channel}_*");
                if (Helpers::isDebug()) {
                    $output->writeln('<info>Channel cache cleared.</info>');
                }

                return self::SUCCESS;
            }

            if ($entity) {
                if (Helpers::isDebug()) {
                    $output->writeln("<comment>Clearing cache for entity '$entity'...</comment>");
                }
                $cache->deletePattern("*_{$entity}_*");
                $cache->deletePattern("list_{$entity}_*");
                $cache->deletePattern("count_{$entity}_*");
                if (Helpers::isDebug()) {
                    $output->writeln('<info>Entity cache cleared.</info>');
                }

                return self::SUCCESS;
            }

            $output->writeln('<error>Please specify --all, --channel, or --entity.</error>');

            return self::FAILURE;
        }
    }
