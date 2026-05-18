<?php

    namespace Commands\Analytics;

    use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
    use Anibalealvarezs\ApiDriverCore\Exceptions\RateLimitException;
    use Anibalealvarezs\ApiDriverCore\Services\CacheStrategyService;
    use Controllers\CacheController;
    use Doctrine\DBAL\Exception\LockWaitTimeoutException;
    use Doctrine\ORM\EntityManager;
    use Entities\Analytics\Channel as ChannelEntity;
    use Entities\Job;
    use Enums\JobStatus;
    use Exception;
    use Exceptions\ConfigurationException;
    use Helpers\Helpers;
    use Psr\Log\LoggerInterface;
    use Repositories\JobRepository;
    use Services\DateResolver;
    use Symfony\Component\Console\Attribute\AsCommand;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Input\InputOption;
    use Symfony\Component\Console\Output\OutputInterface;
    use Symfony\Component\HttpFoundation\Response;
    use Throwable;

    #[AsCommand(
        name: 'app:process-jobs',
        description: 'Processes scheduled caching jobs.',
        aliases: ['jobs:process']
    )]
    class ProcessJobsCommand extends Command
    {
        protected static string $defaultName = 'jobs:process';
        private EntityManager $em;
        private LoggerInterface $logger;

        /**
         * @throws ConfigurationException
         * @throws \Doctrine\DBAL\Exception
         */
        public function __construct(?EntityManager $em = null)
        {
            $this->em = $em ?? Helpers::getManager();
            $this->logger = Helpers::setLogger('jobs.log');
            parent::__construct();
        }

        protected function configure(): void
        {
            $this->setHelp('This command looks for scheduled jobs in the database and executes them via the CacheController.')
                ->addOption('force-all', 'f', InputOption::VALUE_NONE, 'Ignore instance/date filters and process all scheduled jobs.')
                ->addOption('job-id', 'j', InputOption::VALUE_REQUIRED, 'Process a specific job ID immediately regardless of status.');
        }

        /**
         * @throws ConfigurationException
         * @throws \Doctrine\DBAL\Exception
         */
        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            Helpers::clearConfigCache();
            $channelsConfig = Helpers::getChannelsConfig();
            $envInstance = getenv('INSTANCE_NAME') ?: gethostname();
            $lockFile = sys_get_temp_dir().'/process_jobs_'.($envInstance ?: 'default').'.lock';
            $fp = fopen($lockFile, 'w+');

            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                if (Helpers::isDebug()) {
                    $output->writeln("<comment>Another instance of this command is already running for '{$envInstance}'. Skipping.</comment>");
                }
                fclose($fp);

                return Command::SUCCESS;
            }

            Helpers::reconnectIfNeeded($this->em);
            /** @var JobRepository $jobRepo */
            $jobRepo = $this->em->getRepository(Job::class);

            $envChannel = getenv('API_SOURCE');
            $isGenericWorker = empty($envChannel) || $envChannel === 'none';
            $forceAll = $input->getOption('force-all');
            
            $envWorkerTier = getenv('WORKER_TIER');
            $workerTier = $envWorkerTier !== false && $envWorkerTier !== '' ? (int)$envWorkerTier : null;

            if (Helpers::isDebug() || $forceAll) {
                $output->writeln("Querying scheduled and delayed jobs...");
                $this->logger->info("Querying scheduled and delayed jobs...");
            }

            // 0. Cleanup stuck jobs (e.g. processing for > 6 hours)
            $timeoutHours = Helpers::getProjectConfig()['jobs']['timeout_hours'] ?? 6;
            $cleaned = $jobRepo->cleanupStuckJobs($timeoutHours);
            if ($cleaned > 0 && (Helpers::isDebug() || $forceAll)) {
                $output->writeln("<comment>Cleanup: Marked {$cleaned} stuck processing jobs as failed.</comment>");
                $this->logger->warning("Cleanup: Marked {$cleaned} stuck processing jobs as failed.");
            }

            // 0.1 Reset jobs stuck from THIS instance (e.g. after crash/restart)
            if ($envInstance) {
                $resetByInstance = $jobRepo->resetStuckJobsByInstance($envInstance);
                $resetByWorker = $jobRepo->resetStuckJobsByWorker($envInstance);

                if ($resetByInstance > 0 || $resetByWorker > 0) {
                    $this->logger->info("Resumption: Reset ".($resetByInstance + $resetByWorker)." jobs previously held by this instance ({$envInstance}).");
                }

                // Global recovery: If this is the Master (has docker socket), detect dead containers
                $isMaster = file_exists('/var/run/docker.sock');

                if ($isMaster) {
                    // 1. Reset by Timeout (Safety net for other issues, increased to 2 hours)
                    $jobRepo->resetAllOrphanedJobs(120);

                    // 2. Reset by Dead Containers (Accurate detection via Docker Socket API)
                    $activeContainersStr = '';
                    $ch = curl_init('http://localhost/containers/json');
                    curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $response = curl_exec($ch);
                    curl_close($ch);

                    if ($response) {
                        $containers = json_decode($response, true);
                        if (is_array($containers)) {
                            $activeIds = [];
                            foreach ($containers as $container) {
                                $shortId = substr($container['Id'], 0, 12);
                                $activeIds[] = $shortId;
                                $activeIds[] = "worker-".$shortId;

                                if (!empty($container['Names'])) {
                                    foreach ($container['Names'] as $name) {
                                        $cleanName = ltrim($name, '/');
                                        $activeIds[] = $cleanName;

                                        // Dynamically support all suffix matches to avoid deployment prefix mismatches
                                        $parts = explode('-', $cleanName);
                                        $numParts = count($parts);
                                        for ($i = 1; $i < $numParts; $i++) {
                                            $suffix = implode('-', array_slice($parts, $i));
                                            $activeIds[] = $suffix;
                                        }
                                    }
                                }
                            }

                            // Also include ourselves and known master/specific instances
                            $activeIds[] = $envInstance;

                            $resetDead = $jobRepo->resetJobsByDeadWorkers($activeIds);
                            if ($resetDead > 0) {
                                $this->logger->info("Cleanup: Reset {$resetDead} jobs from dead containers.");
                            }
                        }
                    }
                }
            }

            $envStartDate = getenv('START_DATE');
            $envEndDate = getenv('END_DATE');

            $stats = [
                'completed' => 0,
                'failed'    => 0,
                'delayed'   => 0,
                'skipped'   => 0,
                'total'     => 0,
            ];

            $controller = new CacheController();

            do {
                $progressMade = false;
                Helpers::reconnectIfNeeded($this->em);
                $jobRepo = $this->em->getRepository(Job::class);

                $jobId = $input->getOption('job-id');
                if ($jobId) {
                    $job = $jobRepo->find($jobId);
                } else {
                    $job = $jobRepo->claimAvailableJob(
                        status: [JobStatus::scheduled->value, JobStatus::delayed->value],
                        workerId: $envInstance,
                        channel: ($forceAll || empty($envChannel) || $envChannel === 'none' ? null : $envChannel),
                        instanceName: ($forceAll || $isGenericWorker ? null : $envInstance),
                        workerTier: $workerTier
                    );
                }

                if (!$job) {
                    break;
                }

                $payload = $job->getPayload() ?? [];
                $params = $payload['params'] ?? [];
                error_log("DEBUG PROCESS: Params for job ".$job->getUuid()." are: ".json_encode($params));

                // Dependency check
                $requires = $params['requires'] ?? null;
                if (!$jobId && !$forceAll && $requires) {
                    $requiredInstances = array_map('trim', explode(',', $requires));
                    $allMet = true;
                    foreach ($requiredInstances as $requiredInstance) {
                        if (!$jobRepo->hasSuccessfulRecentJob($requiredInstance)) {
                            $allMet = false;
                            break;
                        }
                    }
                    if (!$allMet) {
                        if (Helpers::isDebug() || $output->isVerbose()) {
                            $output->writeln("<comment>Job {$job->getUuid()} dependencies not met. Moving to delayed.</comment>");
                        }
                        $jobRepo->update($job->getId(), (object)['status' => JobStatus::delayed->value]);
                        $stats['skipped']++;
                        continue;
                    }
                }

                if (Helpers::isDebug()) {
                    $output->writeln("Processing job {$job->getUuid()} for entity {$job->getEntity()} and channel {$job->getChannel()}");
                }
                $this->logger->info("Processing job {$job->getUuid()} for entity {$job->getEntity()} and channel {$job->getChannel()}", [
                    'uuid'    => $job->getUuid(),
                    'entity'  => $job->getEntity(),
                    'channel' => $job->getChannel(),
                ]);
                $stats['total']++;

                try {
                    $channelName = $job->getChannel();
                    $channelEntity = $this->em->getRepository(ChannelEntity::class)->findOneBy(['name' => $channelName]);
                    if (!$channelEntity) {
                        throw new Exception("Invalid channel entity: ".$channelName);
                    }

                    // Load full channel configuration
                    $channelsConfig = Helpers::getChannelsConfig();

                    // Normalize channel key (handle common config keys)
                    $chanKey = $channelName;
                    try {
                        $driver = DriverFactory::get($chanKey);
                        $commonKey = $driver::getCommonConfigKey();
                        if ($commonKey && !isset($channelsConfig[$chanKey])) {
                            $chanKey = $commonKey;
                        }
                    } catch (Exception $e) {
                    }

                    $chanConfig = $channelsConfig[$chanKey] ?? null;
                    $isEnabled = true;
                    if ($chanConfig && isset($chanConfig['enabled'])) {
                        $isEnabled = (bool)$chanConfig['enabled'];
                    }

                    // Check if channel is enabled
                    if (!$isEnabled) {
                        $output->writeln("<error>FAILURE: Channel '$chanKey' is disabled in configuration.</error>");
                        $jobRepo->update($job->getId(), (object)[
                            'status'  => JobStatus::failed->value,
                            'message' => "Channel is disabled in configuration",
                        ]);
                        $stats['failed']++;
                        continue;
                    }

                    // Resolve relative dates
                    $resolvedParams = DateResolver::resolveParams($params);

                    // Inject channel configuration
                    if ($chanConfig) {
                        $resolvedParams = array_merge($chanConfig, $resolvedParams);
                    }

                    // Incremental sync
                    $instanceName = $payload['instance_name'] ?? null;
                    if ($instanceName && str_ends_with($instanceName, '-entities-sync')) {
                        $smartResume = $params['smart_resume'] ?? $params['resume'] ?? false;
                        if (filter_var($smartResume, FILTER_VALIDATE_BOOLEAN) && empty($resolvedParams['startDate'])) {
                            $lastRun = $jobRepo->getLastSuccessfulJobTime($instanceName);
                            if ($lastRun) {
                                $resolvedParams['startDate'] = $lastRun->format('Y-m-d H:i:s');
                            }
                        }
                    }

                    $resolvedParams['jobId'] = $job->getId();
                    $body = $payload['body'] ?? null;

                    // DB Lock Timeout
                    $connection = $this->em->getConnection();
                    $platform = $connection->getDatabasePlatform();
                    if ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
                        $connection->executeStatement("SET lock_timeout = '300s'");
                    } else {
                        $connection->executeStatement("SET SESSION innodb_lock_wait_timeout = 300");
                    }

                    $result = $controller->fetchData($job->getEntity(), $channelEntity, $resolvedParams, $body);

                    if ($result instanceof Response && $result->getStatusCode() >= 400) {
                        $content = json_decode($result->getContent(), true);
                        throw new Exception($content['error'] ?? 'Unknown error from fetchData');
                    }

                    // Success
                    $jobRepo->update($job->getId(), (object)[
                        'status'  => JobStatus::completed->value,
                        'message' => 'Success',
                    ]);
                    CacheStrategyService::clearRecent($job->getChannel());
                    if (Helpers::isDebug()) {
                        $output->writeln("<info>Successfully completed job {$job->getUuid()}</info>");
                    }
                    $this->logger->info("Successfully completed job {$job->getUuid()}");
                    $stats['completed']++;
                    $progressMade = true;

                } catch (RateLimitException $e) {
                    if (Helpers::isDebug()) {
                        $output->writeln("<comment>Rate limit reached for job {$job->getUuid()}. Job delayed for cooldown.</comment>");
                    }
                    $jobRepo->markAsDelayed($job->getId(), $e->getMessage());
                    $stats['delayed']++;
                    $progressMade = true;
                } catch (LockWaitTimeoutException $e) {
                    if (Helpers::isDebug()) {
                        $output->writeln("<comment>Lock timeout for job {$job->getUuid()}. Reset to scheduled for retry.</comment>");
                    }
                    $jobRepo->resetJob($job->getId());
                    $stats['delayed']++;
                    $progressMade = true;
                } catch (Throwable $e) {
                    $jobRepo->update($job->getId(), (object)[
                        'status'  => JobStatus::failed->value,
                        'message' => $e->getMessage(),
                    ]);
                    $output->writeln("<error>Failed job {$job->getUuid()}: {$e->getMessage()}</error>");
                    $this->logger->error("Failed job {$job->getUuid()}: {$e->getMessage()}", [
                        'exception' => $e,
                    ]);
                    $stats['failed']++;
                    $progressMade = true;
                }

                $this->em->clear();
            } while ($progressMade);

            if (Helpers::isDebug()) {
                $output->writeln("\nExecution Summary:");
                $output->writeln("-----------------");
                if ($stats['total'] > 0) {
                    $output->writeln("<info>Jobs Processed: {$stats['total']}</info>");
                    $output->writeln("  - <info>Completed: {$stats['completed']}</info>");
                    $output->writeln("  - <error>Failed: {$stats['failed']}</error>");
                    $output->writeln("  - <comment>Delayed: {$stats['delayed']}</comment>");
                } else {
                    $output->writeln("No jobs were processed in this execution context.");
                }
                if ($stats['skipped'] > 0) {
                    $output->writeln("<comment>Jobs Skipped: {$stats['skipped']} (not matching instance context, in cooldown, or pending dependencies)</comment>");
                }
            }

            return Command::SUCCESS;
        }
    }