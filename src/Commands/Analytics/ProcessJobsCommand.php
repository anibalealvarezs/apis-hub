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
    use Anibalealvarezs\ApiSkeleton\Classes\Exceptions\PermanentAuthenticationException;
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
        private bool $shouldShutdown = false;
        private ?int $currentJobId = null;

        /**
         * @throws ConfigurationException
         * @throws \Doctrine\DBAL\Exception
         */
        public function __construct(?EntityManager $em = null)
        {
            $this->em = $em ?? Helpers::getManager();
            $this->logger = Helpers::setLogger('jobs.log');
            $this->logger->info("ProcessJobsCommand INSTANTIATED by " . getenv('INSTANCE_NAME'));
            parent::__construct();

        }

        public function getSubscribedSignals(): array
        {
            return [SIGTERM, SIGINT];
        }

        public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
        {
            $this->logger->info("Received termination signal ($signal). Instant graceful shutdown initiated.");
            $this->shouldShutdown = true;

            if ($this->currentJobId) {
                try {
                    Helpers::reconnectIfNeeded($this->em);
                    $jobRepo = $this->em->getRepository(Job::class);
                    $jobRepo->update($this->currentJobId, (object)['status' => JobStatus::scheduled->value]);
                    $this->logger->info("Job {$this->currentJobId} safely returned to scheduled state. Exiting immediately.");
                } catch (\Exception $e) {
                    $this->logger->error("Failed to return job to scheduled state during shutdown: " . $e->getMessage());
                }
                exit(0); // Instantly exit, dropping container gracefully in < 1s
            }

            return false; // Return false to continue normal execution if no job is active
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
            $this->logger->info("WORKER IS EXECUTING: " . getenv('INSTANCE_NAME'));
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

            // 0. Cleanup stuck jobs
            $timeoutMinutes = Helpers::getProjectConfig()['jobs']['timeout_minutes'] ?? 60;
            $cleaned = $jobRepo->cleanupStuckJobs($timeoutMinutes);
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

                // Global recovery: If this is the Master (identified by INSTANCE_NAME containing 'master')
                $isMaster = str_contains($envInstance, 'master') && file_exists('/var/run/docker.sock');

                if ($isMaster) {
                    // 1. Reset by Timeout (Safety net for other issues, using configurable timeout)
                    $jobRepo->resetAllOrphanedJobs($timeoutMinutes);

                    // 2. Reset by Dead Containers (Accurate detection via Docker CLI)
                    exec('docker ps --format "{{.ID}}|{{.Names}}" 2>&1', $dockerOutput, $dockerReturn);

                    if ($dockerReturn === 0 && !empty($dockerOutput)) {
                        $activeIds = [];
                        foreach ($dockerOutput as $line) {
                            $parts = explode('|', trim($line));
                            if (count($parts) >= 2) {
                                $shortId = $parts[0];
                                $nameStr = $parts[1]; // Comma-separated if multiple names
                                
                                $activeIds[] = $shortId;
                                $activeIds[] = "worker-" . $shortId;

                                $names = explode(',', $nameStr);
                                foreach ($names as $name) {
                                    $cleanName = ltrim(trim($name), '/');
                                    $activeIds[] = $cleanName;

                                    // Dynamically support all suffix matches to avoid deployment prefix mismatches
                                    $nameParts = explode('-', $cleanName);
                                    $numParts = count($nameParts);
                                    for ($i = 1; $i < $numParts; $i++) {
                                        $suffix = implode('-', array_slice($nameParts, $i));
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
                    } else {
                        $this->logger->warning("Cleanup: Failed to run docker ps for container discovery. Return code: $dockerReturn");
                    }
                    
                    $this->logger->info("Master watchdog routine complete. Master instance will not process jobs.");
                    if (Helpers::isDebug()) {
                        $output->writeln("<info>Watchdog complete. Exiting to prevent master from processing jobs.</info>");
                    }
                    return Command::SUCCESS;
                }
            }

            $stats = [
                'completed' => 0,
                'failed'    => 0,
                'delayed'   => 0,
                'skipped'   => 0,
                'total'     => 0,
            ];

            $controller = new CacheController();

            do {
                if ($this->shouldShutdown) {
                    if (Helpers::isDebug()) {
                        $output->writeln("<comment>Graceful shutdown requested. Exiting loop.</comment>");
                    }
                    $this->logger->info("Graceful shutdown requested. Exiting loop.");

                    break;
                }

                $progressMade = false;
                Helpers::reconnectIfNeeded($this->em);
                $jobRepo = $this->em->getRepository(Job::class);

                $jobId = $input->getOption('job-id');
                if ($jobId) {
                    $job = $jobRepo->find($jobId);
                } else {
                    $this->logger->info("WORKER DEBUG ($envInstance): Attempting to claim job... [Tier: " . ($workerTier ?? 'none') . " | Channel: " . ($envChannel ?? 'none') . "]");
                    $job = $jobRepo->claimAvailableJob(
                        status: [JobStatus::scheduled->value, JobStatus::delayed->value],
                        workerId: $envInstance,
                        channel: ($forceAll || empty($envChannel) || $envChannel === 'none' ? null : $envChannel),
                        instanceName: ($forceAll || $isGenericWorker ? null : $envInstance),
                        workerTier: $workerTier
                    );
                    if (!$job) {
                        $this->logger->info("WORKER DEBUG ($envInstance): Found no jobs! Queue empty or locked.");
                    } else {
                        $this->logger->info("WORKER DEBUG ($envInstance): CLAIMED job " . $job->getUuid() . " successfully.");
                    }
                }

                if (!$job) {
                    break;
                }

                $this->currentJobId = $job->getId();

                $payload = $job->getPayload() ?? [];
                $params = $payload['params'] ?? [];
                error_log("DEBUG PROCESS: Params for job ".$job->getUuid()." are: ".json_encode($params));

                // Dependency check
                $requires = $params['requires'] ?? null;
                if (!$jobId && !$forceAll && $requires) {
                    $requiredInstances = array_map('trim', explode(',', $requires));
                    $allMet = true;
                    $accountId = $params['account_id'] ?? null;
                    $missingDependency = '';
                    foreach ($requiredInstances as $requiredInstance) {
                        // Historical chunks (e.g. 2026-1) might have completed more than 24 hours ago.
                        // We pass 87600 hours (10 years) to ensure they are recognized regardless of completion date.
                        if (!$jobRepo->hasSuccessfulRecentJob($requiredInstance, 87600, $accountId)) {
                            $allMet = false;
                            $missingDependency = $requiredInstance;
                            break;
                        }
                    }
                    if (!$allMet) {
                        $msg = "Job depends on '{$missingDependency}' which has no successful recent execution.";
                        $this->logger->warning("Job {$job->getUuid()} (Channel: {$job->getChannel()}) depends on '{$missingDependency}' which has no successful recent execution. Skipping.");
                        if (Helpers::isDebug() || $output->isVerbose()) {
                            $output->writeln("<comment>Job {$job->getUuid()} dependencies not met. Moving to delayed.</comment>");
                        }
                        $jobRepo->update($job->getId(), (object)['status' => JobStatus::delayed->value, 'message' => $msg]);
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

                        if ($result->getStatusCode() === Response::HTTP_UNAUTHORIZED) {
                            throw new PermanentAuthenticationException($content['error'] ?? $content['message'] ?? 'Authentication revoked');
                        }

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
                } catch (PermanentAuthenticationException $e) {
                    $jobRepo->update($job->getId(), (object)[
                        'status'  => JobStatus::failed->value,
                        'message' => 'Permanent Auth Error: ' . $e->getMessage(),
                    ]);
                    $output->writeln("<error>Permanent Auth Error for job {$job->getUuid()}: {$e->getMessage()}</error>");
                    $this->logger->error("Permanent Auth Error for job {$job->getUuid()}: {$e->getMessage()}", [
                        'exception' => $e,
                    ]);
                    
                    try {
                        $payload = is_string($job->getPayload()) ? json_decode($job->getPayload(), true) : $job->getPayload();
                        $accountId = $payload['account_id'] ?? $payload['params']['account_id'] ?? null;
                        
                        if ($accountId) {
                            $output->writeln("<comment>Permanent auth error for account {$accountId} on channel {$job->getChannel()}. Notifying Facade...</comment>");
                            
                            // Notify Facade to clear connection
                            $facadeUrl = getenv('MONITOR_FACADE_URL') ?: ($_ENV['MONITOR_FACADE_URL'] ?? null);
                            $token = getenv('MONITOR_TOKEN') ?: ($_ENV['MONITOR_TOKEN'] ?? null);
                                
                                if ($facadeUrl && $token) {
                                    $payloadData = [
                                        'channel' => $job->getChannel(),
                                        'account_id' => $accountId,
                                        'error' => $e->getMessage()
                                    ];
                                    
                                    $parsedUrl = parse_url($facadeUrl);
                                    $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . (isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '');
                                    
                                    $ch = curl_init($baseUrl . '/api/channels/auth-failed');
                                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                    curl_setopt($ch, CURLOPT_POST, true);
                                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadData));
                                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                        'Content-Type: application/json',
                                        'X-Monitoring-Token: ' . $token,
                                    ]);
                                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                                    
                                    $response = curl_exec($ch);
                                    $info = curl_getinfo($ch);
                                    if ($response === false || $info['http_code'] !== 200) {
                                        $this->logger->error("Failed to notify Facade of auth error. HTTP " . $info['http_code'] . ". Error: " . curl_error($ch));
                                    } else {
                                        $output->writeln("<comment>Successfully notified Facade of permanent auth error.</comment>");
                                    }
                                    curl_close($ch);
                                }
                        }
                    } catch (Throwable $caError) {
                        $this->logger->error("Failed to process permanent auth error for ChanneledAccount: " . $caError->getMessage());
                    }

                    $stats['failed']++;
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
                $this->currentJobId = null;

                if ($this->shouldShutdown) {
                    break;
                }
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
