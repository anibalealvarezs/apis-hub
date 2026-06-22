<?php

    namespace Commands\Analytics;

    use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
    use Anibalealvarezs\ApiDriverCore\Enums\AssetCategory;
    use DateTime;
    use Doctrine\DBAL\Exception;
    use Doctrine\ORM\EntityManagerInterface;
    use Entities\Analytics\Channeled\ChanneledAccount;
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
    use Throwable;

    #[AsCommand(
        name: 'app:schedule-initial-jobs',
        description: 'Schedules initial caching jobs for all instances defined in project.yaml'
    )]
    class ScheduleInitialJobsCommand extends Command
    {
        private EntityManagerInterface $entityManager;

        public function __construct(EntityManagerInterface $entityManager)
        {
            $this->entityManager = $entityManager;
            parent::__construct();
        }

        protected function configure(): void
        {
            $this->addOption('instance', null, InputOption::VALUE_OPTIONAL, 'Schedule only for this instance name');
            $this->addOption('channel', null, InputOption::VALUE_OPTIONAL, 'Schedule only for this channel');
        }

        /**
         * @throws RandomException
         * @throws ConfigurationException
         * @throws Exception
         */
        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            $config = Helpers::getProjectConfig();
            $instances = $config['instances'] ?? [];
            $targetInstance = $input->getOption('instance');
            $targetChannel = $input->getOption('channel');

            if (empty($instances)) {
                if (Helpers::isDebug()) {
                    $output->writeln('<comment>No instances found in project configuration.</comment>');
                }

                return Command::SUCCESS;
            }

            $scheduledCount = 0;
            $skippedCount = 0;

            foreach ($instances as $instance) {
                $name = $instance['name'] ?? 'unknown';

                // Filter by instance if provided
                if ($targetInstance && $targetInstance !== $name) {
                    continue;
                }

                $channel = $instance['channel'] ?? null;
                $entity = $instance['entity'] ?? null;

                // Filter by channel if provided
                if ($targetChannel && $targetChannel !== 'all' && $channel !== $targetChannel) {
                    continue;
                }

                if ($channel && ($chanEnum = Channel::tryFromName($channel))) {
                    $channel = $chanEnum->getName();
                }

                if (!$channel || !$entity) {
                    $output->writeln("<error>Instance $name is missing channel or entity. Skipping.</error>");
                    continue;
                }

                // Check if channel is enabled and history range
                $channelsConfig = Helpers::getChannelsConfig();
                $chanKey = $channel;
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
                            $limitDate->modify('-'.$chanConfig['cache_history_range']);
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

                    $isGranular = (bool)($instance['granular_sync'] ?? $chanConfig['granular_sync'] ?? false);
                    $accounts = [];

                    if ($isGranular) {
                        try {
                            $regConfig = DriverFactory::getChannelConfig($channel);
                            $resourceKey = $regConfig['resource_key'] ?? null;
                            $accounts = $resourceKey ? ($chanConfig[$resourceKey] ?? []) : [];

                            // If no accounts in config, fetch from database
                            if (empty($accounts)) {
                                if (str_contains($channel, 'facebook')) echo "DEBUG: No accounts in YAML for $channel, checking DB...\n";
                                $caRepo = $this->entityManager->getRepository(ChanneledAccount::class);
                                $dbAccounts = $caRepo->findBy(['channel' => $channel]);
                                if (str_contains($channel, 'facebook')) echo "DEBUG: Found ".count($dbAccounts)." accounts in DB\n";
                                foreach ($dbAccounts as $dbAcc) {
                                    if (!$dbAcc->isEnabled()) {
                                        continue;
                                    }
                                    $accounts[] = [
                                        'id'      => $dbAcc->getPlatformId(),
                                        'name'    => $dbAcc->getName(),
                                        'enabled' => true
                                    ];
                                }
                            }
                        } catch (Throwable $e) {
                            if (str_contains($channel, 'facebook')) echo "DEBUG: Error in granular logic: ".$e->getMessage()."\n";
                        }
                    }

                    if (empty($accounts)) {
                        $accounts = [null];
                    }

                    foreach ($accounts as $account) {
                        if ($account instanceof ChanneledAccount) {
                            if (!$account->isEnabled()) {
                                continue;
                            }
                        } elseif (is_array($account)) {
                            if (isset($account['enabled']) && !$account['enabled']) {
                                continue;
                            }
                        }

                        $accountId = null;
                        if ($account instanceof ChanneledAccount) {
                            $accountId = $account->getPlatformId();
                        } elseif (is_array($account)) {
                            $accountId = $account['id'] ?? $account['identifier'] ?? $account['url'] ?? $account['platformId'] ?? $account['location_id'] ?? null;
                        } else {
                            $accountId = $account;
                        }

                        // Agnostic Canonical ID resolution
                        if ($account && !$accountId) {
                            $assetData = is_array($account) ? $account : ['id' => $account];
                            $driverClass = $regConfig['driver'] ?? null;
                            if ($driverClass && method_exists($driverClass, 'getCanonicalId')) {
                                // 1. Resolve context and category from driver patterns
                                $resourceKey = $regConfig['resource_key'] ?? null;
                                $patterns = method_exists($driverClass, 'getAssetPatterns') ? $driverClass::getAssetPatterns() : [];
                                $category = AssetCategory::IDENTITY; // Default
                                $context = $channel;

                                if (!empty($patterns)) {
                                    foreach ($patterns as $pKey => $pattern) {
                                        if (($pattern['key'] ?? null) === $resourceKey) {
                                            $categories = (array)($pattern['category'] ?? []);
                                            if (!empty($categories) && isset($categories[0])) {
                                                $category = $categories[0];
                                                $context = $pKey;
                                                break;
                                            }
                                        }
                                    }
                                }
                                // 2. Ask the driver for the ID using the resolved category
                                $accountId = $driverClass::getPlatformId($assetData, $category, $context);
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
                                $sql .= " AND (CAST(j.payload AS JSONB)->'params'->>'account_id' = :account_id OR CAST(j.payload AS JSONB)->>'account_id' = :account_id)";
                                $sqlParams['account_id'] = $accountId;
                            } else {
                                $sql .= " AND (CAST(j.payload AS JSONB)->'params'->>'account_id' IS NULL AND CAST(j.payload AS JSONB)->>'account_id' IS NULL)";
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
                            } else {
                                $qb->andWhere('j.payload NOT LIKE :account_pattern')
                                    ->setParameter('account_pattern', '%account_id%');
                            }
                            $qb->orderBy('j.id', 'DESC')->setMaxResults(1);
                            $result = $qb->getQuery()->getOneOrNullResult();
                            $lastJob = $result ? ['id' => $result->getId(), 'status' => $result->getStatus(), 'payload' => $result->getPayload()] : null;
                        }

                        if ($lastJob) {
                            $payloadObj = is_string($lastJob['payload']) ? json_decode($lastJob['payload'], true) : $lastJob['payload'];
                            $foundAcc = $payloadObj['params']['account_id'] ?? $payloadObj['account_id'] ?? null;

                            // FINAL GUARD: If account IDs don't match, this is NOT a duplicate
                            if (($accountId ?: null) !== ($foundAcc ?: null)) {
                                $lastJob = null;
                            }
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
                                $jobStatus = (int)$lastJob['status'];
                                
                                // Detect if this new job represents a fully closed quarter
                                $isQuarterEnd = false;
                                if ($newStart && $newEnd) {
                                    try {
                                        $startDateObj = new DateTimeImmutable($newStart);
                                        $month = (int)$startDateObj->format('n');
                                        $year = (int)$startDateObj->format('Y');
                                        $currentQuarter = (int)ceil($month / 3);
                                        $endMonth = $currentQuarter * 3;
                                        $quarterEndObj = $startDateObj->setDate($year, $endMonth, 1)->modify('last day of this month');
                                        if ($newEnd === $quarterEndObj->format('Y-m-d')) {
                                            $isQuarterEnd = true;
                                        }
                                    } catch (Exception $e) {}
                                }

                                // Prevent redundant rescheduling when end dates naturally shift for the current ONGOING chunk.
                                // If a job already exists for this exact instance/account in a valid state, we preserve it.
                                // HOWEVER, if $isQuarterEnd is true, we DO schedule it to ensure a final, consolidated
                                // capstone sync is performed when a quarter officially closes.
                                if (!$isQuarterEnd && in_array($jobStatus, [
                                    JobStatus::scheduled->value,
                                    JobStatus::processing->value,
                                    JobStatus::completed->value,
                                    JobStatus::delayed->value
                                ])) {
                                    $shouldSchedule = false;
                                } else {
                                    // If it was cancelled or failed, OR if it's the final quarter close, allow scheduling.
                                    // Soft-delete the old job if it's somehow still scheduled (fallback).
                                    if ($jobStatus === JobStatus::scheduled->value) {
                                        if (Helpers::isPostgres()) {
                                            $this->entityManager->getConnection()->executeStatement(
                                                "UPDATE jobs SET status = :status WHERE id = :id",
                                                ['status' => JobStatus::cancelled->value, 'id' => $lastJob['id']]
                                            );
                                        } else {
                                            $qbUpdate = $this->entityManager->createQueryBuilder();
                                            $qbUpdate->update(Job::class, 'j')
                                                ->set('j.status', ':status')
                                                ->where('j.id = :id')
                                                ->setParameter('status', JobStatus::cancelled->value)
                                                ->setParameter('id', $lastJob['id'])
                                                ->getQuery()->execute();
                                        }
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
                                : "Initial scheduling from deployment command".($accountId ? " for account $accountId" : "");
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
