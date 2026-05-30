<?php

    namespace Commands;

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
            $this->addOption('channel', null, InputOption::VALUE_OPTIONAL, 'Target channel (omit for all channels)', 'all');
        }

        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            $channel = $input->getOption('channel') ?: 'all';
            $isAll = $channel === 'all';

            $output->writeln("<info>Nuclear Resync starting for: <comment>" . ($isAll ? 'ALL channels' : $channel) . "</comment></info>");

            // 1. Delete jobs
            try {
                $conn = $this->entityManager->getConnection();
                if ($isAll) {
                    $isPostgres = Helpers::isPostgres($this->entityManager);
                    if ($isPostgres) {
                        $conn->executeStatement("TRUNCATE TABLE jobs RESTART IDENTITY CASCADE");
                    } else {
                        $conn->executeStatement("DELETE FROM jobs");
                    }
                    $output->writeln("<info>✓ All jobs deleted.</info>");
                } else {
                    $deleted = $conn->executeStatement("DELETE FROM jobs WHERE channel = :channel", ['channel' => $channel]);
                    $output->writeln("<info>✓ Deleted $deleted jobs for channel '$channel'.</info>");
                }
            } catch (\Throwable $e) {
                $output->writeln("<error>✗ DB error: " . $e->getMessage() . "</error>");
                return Command::FAILURE;
            }

            // 2. Clear telemetry Redis cache
            try {
                $redis = Helpers::getRedisClient();
                $pattern = $isAll ? 'sync_telemetry:*' : "sync_telemetry:{$channel}:*";
                $keys = $redis->keys($pattern);
                if (!empty($keys)) {
                    $redis->del($keys);
                    $output->writeln("<info>✓ Cleared " . count($keys) . " Redis telemetry keys.</info>");
                } else {
                    $output->writeln("<comment>No Redis telemetry keys found for pattern: $pattern</comment>");
                }
            } catch (\Throwable $e) {
                $output->writeln("<error>✗ Redis error: " . $e->getMessage() . "</error>");
                return Command::FAILURE;
            }

            // 3. Schedule initial jobs
            $output->writeln("<info>Scheduling initial jobs...</info>");
            try {
                $scheduleCmd = new ScheduleInitialJobsCommand($this->entityManager);
                $scheduleInput = new ArrayInput($isAll ? [] : ['--channel' => $channel]);
                $scheduleCmd->run($scheduleInput, $output);
                $output->writeln("<info>✓ Initial jobs scheduled.</info>");
            } catch (\Throwable $e) {
                $output->writeln("<error>✗ Schedule error: " . $e->getMessage() . "</error>");
                return Command::FAILURE;
            }

            $output->writeln("<info>✓ Nuclear Resync complete.</info>");
            return Command::SUCCESS;
        }
    }
