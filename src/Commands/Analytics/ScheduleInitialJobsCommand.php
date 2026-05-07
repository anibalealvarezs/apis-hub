<?php

    namespace Commands\Analytics;

    use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
    use DateTime;
    use Doctrine\DBAL\Exception;
    use Doctrine\ORM\EntityManagerInterface;
    use Entities\Job;
    use Enums\JobStatus;
    use Exceptions\ConfigurationException;
    use Helpers\Helpers;
    use Random\RandomException;
    use Symfony\Component\Console\Attribute\AsCommand;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Input\InputOption;
    use Symfony\Component\Console\Output\OutputInterface;
    use Entities\Analytics\Channel;

    #[AsCommand(
        name: 'app:schedule-initial-jobs',
        description: 'Schedules initial caching jobs for all instances defined in project.yaml'
    )]
    class ScheduleInitialJobsCommand extends Command
    {
        private EntityManagerInterface $entityManager;

        public function __construct(EntityManagerInterface $entityManager)
        {
            die("DEBUG: DENTRO DEL CONSTRUCTOR");
            $this->entityManager = $entityManager;
            parent::__construct();
        }

        protected function configure(): void
        {
            $this->addOption('instance', null, InputOption::VALUE_OPTIONAL, 'Schedule only for this instance name');
        }

        /**
         * @throws RandomException
         * @throws ConfigurationException
         * @throws Exception
         */
        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            die("DEBUG: DENTRO DE EXECUTE");
            $output->writeln("<info>Executing ScheduleInitialJobsCommand...</info>");
            $config = Helpers::getProjectConfig();
            $instances = $config['instances'] ?? [];
            $output->writeln("<comment>Found " . count($instances) . " instances in config.</comment>");
            $targetInstance = $input->getOption('instance');

            if (empty($instances)) {
                if (Helpers::isDebug()) {
                    $output->writeln('<comment>No instances found in project configuration.</comment>');
                }

                return Command::SUCCESS;
            }

            $jobRepository = $this->entityManager->getRepository(Job::class);
            $scheduledCount = 0;
            $skippedCount = 0;

            foreach ($instances as $instance) {
                $name = $instance['name'] ?? 'unknown';
                $output->writeln("<comment>Processing instance: $name</comment>");

                // Filter by instance if provided
                if ($targetInstance && $targetInstance !== $name) {
                    continue;
                }

                $channel = $instance['channel'] ?? null;
                $entity = $instance['entity'] ?? null;

                if ($channel && ($chanEnum = Channel::tryFromName($channel))) {
                    $channel = $chanEnum->name;
                }

                if (!$channel || !$entity) {
                    $output->writeln("<error>Instance $name is missing channel or entity. Skipping.</error>");
                    continue;
                }

                // Check if channel is enabled and history range
                $channelsConfig = Helpers::getChannelsConfig();
                $chanKey = $channel;
                $output->writeln("<comment> - Channel: $channel</comment>");
                try {
                    $driver = DriverFactory::get($channel);
                    $commonKey = $driver::getCommonConfigKey();
                    if ($commonKey && !isset($channelsConfig[$channel])) {
                        $chanKey = $commonKey;
                    }
                } catch (\Exception $e) {
                    // If no driver found, fall back to literal channel name
                }

                $chanConfig = $channelsConfig[$channel] ?? $channelsConfig[$chanKey] ?? null;

                if ($chanConfig) {
                    if (isset($chanConfig['enabled']) && !$chanConfig['enabled']) {
                        if (Helpers::isDebug()) {
                            $output->writeln("<comment>Skipping $name: channel $channel is disabled.</comment>");
                        }
                        continue;
                    }

                    if (!empty($instance['end_date']) && !empty($chanConfig['cache_history_range']) && $instance['end_date'] !== 'yesterday') {
                        try {
                            $limitDate = new DateTime();
                            $limitDate->modify('-' . $chanConfig['cache_history_range']);
                            $jobEndDate = new DateTime($instance['end_date']);
                            if ($jobEndDate < $limitDate) {
                                if (Helpers::isDebug()) {
                                    $output->writeln("<comment>Skipping $name: end date {$instance['end_date']} is outside caching history ({$chanConfig['cache_history_range']}).</comment>");
                                }
                                continue;
                            }
                        } catch (\Exception $e) {
                            // ignore malformed dates
                        }
                    }

                    $params = [];
                    if (!empty($instance['start_date'])) $params['startDate'] = $instance['start_date'];
                    if (!empty($instance['end_date'])) $params['endDate'] = $instance['end_date'];
                    if (!empty($instance['requires'])) $params['requires'] = $instance['requires'];

                    $isGranular = (bool)($chanConfig['granular_sync'] ?? false);
                    $accounts = [null];

                    if ($isGranular) {
                        try {
                            $regConfig = DriverFactory::getChannelConfig($channel);
                            $resourceKey = $regConfig['resource_key'] ?? null;
                            $accounts = $resourceKey ? ($chanConfig[$resourceKey] ?? []) : [null];
                            if (empty($accounts)) {
                                $accounts = [null];
                            }
                        } catch (\Throwable $e) {
                            $accounts = [null];
                        }
                    }

                    $output->writeln("<comment> - Found " . count($accounts) . " accounts for granular sync.</comment>");
                    foreach ($accounts as $account) {
                        $accountId = is_array($account) ? ($account['id'] ?? $account['identifier'] ?? $account['url'] ?? null) : $account;
                        $output->writeln("<comment>   - Resolving identity for: " . ($accountId ?: '[EMPTY]') . "</comment>");
 
                        // Agnostic Canonical ID resolution
                        if ($account) {
                            $assetData = is_array($account) ? $account : ['id' => $account];
                            $driverClass = $regConfig['driver'] ?? null;
                            if ($driverClass && method_exists($driverClass, 'getCanonicalId')) {
                                // 1. Resolve context and category from driver patterns
                                $resourceKey = $regConfig['resource_key'] ?? null;
                                $patterns = method_exists($driverClass, 'getAssetPatterns') ? $driverClass::getAssetPatterns() : [];
                                $category = \Anibalealvarezs\ApiDriverCore\Enums\AssetCategory::IDENTITY; // Default
                                $context = $channel;
 
                                if (!empty($patterns)) {
                                    foreach ($patterns as $pKey => $pattern) {
                                        if (($pattern['key'] ?? null) === $resourceKey) {
                                            $categories = (array) ($pattern['category'] ?? []);
                                            if (!empty($categories)) {
                                                $category = $categories[0];
                                                $context = $pKey;
                                                break;
                                            }
                                        }
                                    }
                                }
                                // 2. Ask the driver for the ID using the resolved category
                                $accountId = $driverClass::getCanonicalId($assetData, $category, $context);
                                $output->writeln("<comment>   - Resolved Canonical ID: " . ($accountId ?: '[EMPTY]') . "</comment>");
                            }
                        }
                    }

                    $jobParams = $params;
                    if ($accountId) {
                        $jobParams['account_id'] = $accountId;
                    }

                    $shouldSchedule = true;
                    // Try to find the LAST existing job for this instance/account
                    if (Helpers::isPostgres()) {
                        $sql = "SELECT id, status, payload FROM jobs j WHERE j.channel = :channel AND j.entity = :entity AND CAST(j.payload AS text) LIKE :instance_pattern";
                        $sqlParams = [
                            'channel'          => $channel,
                            'entity'           => $entity,
                            'instance_pattern' => '%instance_name%'.$name.'%',
                        ];
                        if ($accountId) {
                            $sql .= " AND CAST(j.payload AS text) LIKE :account_pattern";
                            $sqlParams['account_pattern'] = '%account_id%'.$accountId.'%';
                        }
                        $sql .= " ORDER BY id DESC LIMIT 1";
                        $lastJob = $this->entityManager->getConnection()->fetchAssociative($sql, $sqlParams);
                    } else {
                        $qb = $this->entityManager->createQueryBuilder();
                        $qb->select('j.id', 'j.status', 'j.payload')
                            ->from(Job::class, 'j')
                            ->where('j.channel = :channel')
                            ->andWhere('j.entity = :entity')
                            ->andWhere('j.payload LIKE :instance_pattern')
                            ->setParameter('channel', $channel)
                            ->setParameter('entity', $entity)
                            ->setParameter('instance_pattern', '%instance_name%'.$name.'%');
                        if ($accountId) {
                            $qb->andWhere('j.payload LIKE :account_pattern')
                                ->setParameter('account_pattern', '%account_id%'.$accountId.'%');
                        }
                        $qb->orderBy('j.id', 'DESC')->setMaxResults(1);
                        $lastJob = $qb->getQuery()->getOneOrNullResult();
                    }

                    if ($lastJob) {
                        $payloadObj = is_string($lastJob['payload']) ? json_decode($lastJob['payload'], true) : $lastJob['payload'];
                        $oldStart = $payloadObj['params']['startDate'] ?? $payloadObj['params']['start_date'] ?? null;
                        $oldEnd = $payloadObj['params']['endDate'] ?? $payloadObj['params']['end_date'] ?? null;
                        
                        $newStart = $jobParams['startDate'] ?? $jobParams['start_date'] ?? null;
                        $newEnd = $jobParams['endDate'] ?? $jobParams['end_date'] ?? null;
                        
                        if ($oldStart === $newStart && $oldEnd === $newEnd) {
                            $shouldSchedule = false;
                        } else {
                            // Dates changed! If old job is still pending (status 1), soft-delete it.
                            if ((int)$lastJob['status'] === \Enums\JobStatus::scheduled->value) {
                                if (Helpers::isPostgres()) {
                                    $this->entityManager->getConnection()->executeStatement(
                                        "UPDATE jobs SET status = :status WHERE id = :id",
                                        ['status' => \Enums\JobStatus::cancelled->value, 'id' => $lastJob['id']]
                                    );
                                } else {
                                    $qbUpdate = $this->entityManager->createQueryBuilder();
                                    $qbUpdate->update(Job::class, 'j')
                                        ->set('j.status', ':status')
                                        ->where('j.id = :id')
                                        ->setParameter('status', \Enums\JobStatus::cancelled->value)
                                        ->setParameter('id', $lastJob['id'])
                                        ->getQuery()->execute();
                                }
                            }
                        }
                    }

                    if ($shouldSchedule) {
                        $job = new Job();
                        $job->addChannel($channel);
                        $job->addEntity($entity);

                        $isRecent = str_ends_with($name, '-recent');
                        $job->addStatus($isRecent ? JobStatus::cancelled->value : JobStatus::scheduled->value);

                        $job->addUuid(bin2hex(random_bytes(16)));
                        $job->addPayload([
                            'params'        => $jobParams,
                            'instance_name' => $name
                        ]);

                        $msg = $isRecent
                            ? "Initial job cancelled during deployment to prevent redundancy. Will run via cron at next scheduled time."
                            : "Initial scheduling from deployment command" . ($accountId ? " for account $accountId" : "");
                        $job->addMessage($msg);

                        $job->addCreatedAt(new DateTime());
                        $job->addUpdatedAt(new DateTime());

                        $this->entityManager->persist($job);
                        $scheduledCount++;
                        if (Helpers::isDebug()) {
                            $statusName = $isRecent ? 'cancelled' : 'scheduled';
                            $accMsg = $accountId ? " (Account: $accountId)" : "";
                            $output->writeln("<info>Created initial $statusName job for $name$accMsg ($channel -> $entity)</info>");
                        }
                    } else {
                        $skippedCount++;
                    }
                }
            }
        }

        $this->entityManager->flush();

        $output->writeln("<info>Successfully scheduled $scheduledCount new jobs ($skippedCount skipped).</info>");

            return Command::SUCCESS;
        }

    }
