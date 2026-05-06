<?php

namespace Commands\Analytics;

use Anibalealvarezs\ApiDriverCore\Exceptions\RateLimitException;
use Anibalealvarezs\ApiDriverCore\Services\CacheStrategyService;
use Controllers\CacheController;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManager;
use Entities\Analytics\Channel as ChannelEntity;
use Entities\Job;
use Enums\JobStatus;
use Helpers\Helpers;
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
    protected static $defaultName = 'jobs:process';
    private EntityManager $em;
    private \Psr\Log\LoggerInterface $logger;

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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $envInstance = getenv('INSTANCE_NAME');
        $lockFile = sys_get_temp_dir() . '/process_jobs_' . ($envInstance ?: 'default') . '.lock';
        $fp = fopen($lockFile, 'w+');

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            if (Helpers::isDebug()) {
                $output->writeln("<comment>Another instance of this command is already running for '{$envInstance}'. Skipping.</comment>");
            }
            fclose($fp);
            return Command::SUCCESS;
        }

        Helpers::reconnectIfNeeded($this->em);
        /** @var \Repositories\JobRepository $jobRepo */
        $jobRepo = $this->em->getRepository(Job::class);

        $envChannel = getenv('API_SOURCE');
        $isGenericWorker = str_contains($envInstance ?? '', 'worker');
        $forceAll = $input->getOption('force-all');

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

            // Global recovery: If this is the Master, detect dead containers via Docker Socket
            if (! $isGenericWorker) {
                // 1. Reset by Timeout (Safety net for other issues, increased to 2 hours)
                $jobRepo->resetAllOrphanedJobs(120);

                // 2. Reset by Dead Containers (Accurate detection)
                // We use docker ps to get names and short IDs of running containers
                $activeContainersStr = shell_exec("docker ps --format '{{.Names}}|{{.ID}}' 2>/dev/null");
                if ($activeContainersStr) {
                    $activeIds = [];
                    foreach (explode("\n", trim($activeContainersStr)) as $line) {
                        if (empty($line)) continue;
                        $parts = explode('|', $line); // [Name, ID]
                        $activeIds[] = $parts[0]; // Full container name
                        $activeIds[] = $parts[1]; // Short container ID
                        $activeIds[] = "worker-" . $parts[1]; // Our unique worker identity
                    }
                    // Also include ourselves and known master/specific instances
                    $activeIds[] = $envInstance;
                    
                    $resetDead = $jobRepo->resetJobsByDeadWorkers($activeIds);
                    if ($resetDead > 0) {
                        $this->logger->info("Global Recovery: Reset {$resetDead} jobs from dead containers.");
                    }
                }
            }
        }

        $envStartDate = getenv('START_DATE');
        $envEndDate = getenv('END_DATE');

        $stats = [
            'completed' => 0,
            'failed' => 0,
            'delayed' => 0,
            'skipped' => 0,
            'total' => 0,
        ];

        $controller = new CacheController();

        do {
            $progressMade = false;
            Helpers::reconnectIfNeeded($this->em);
            $jobRepo = $this->em->getRepository(Job::class);

            $jobId = $input->getOption('job-id');
            if ($jobId) {
                $specificJob = $jobRepo->find($jobId);
                $jobsList = $specificJob ? [$specificJob] : [];
            } else {
                $jobs = $jobRepo->getJobsByStatus(
                    status: JobStatus::scheduled->value,
                    channel: ($forceAll || $envChannel === 'none' ? null : $envChannel),
                    instanceName: ($forceAll || $isGenericWorker ? null : $envInstance)
                );
                $delayedJobs = $jobRepo->getJobsByStatus(
                    status: JobStatus::delayed->value,
                    channel: ($forceAll || $envChannel === 'none' ? null : $envChannel),
                    instanceName: ($forceAll || $isGenericWorker ? null : $envInstance)
                );
                $jobsList = array_merge($jobs, $delayedJobs);
            }

            if (empty($jobsList)) {
                break;
            }

            /** @var Job $job */
            foreach ($jobsList as $job) {
                Helpers::reconnectIfNeeded($this->em);
                $jobRepo = $this->em->getRepository(Job::class);

                $payload = $job->getPayload() ?? [];
                $params = $payload['params'] ?? [];

                // 1. Instance Name Match (Primary Filter)
                $jobInstance = $payload['instance_name'] ?? null;
                if (! $jobId && ! $forceAll && $envInstance && $jobInstance && $envInstance !== $jobInstance && ! $isGenericWorker) {
                    $stats['skipped']++;

                    continue;
                }

                // 2. Date Range Filters (Fallback/Secondary)
                if (! $jobId && $envStartDate !== false && $envStartDate !== '') {
                    $jobStart = $params['startDate'] ?? $params['start_date'] ?? null;
                    if ($jobStart !== $envStartDate) {
                        $stats['skipped']++;

                        continue;
                    }
                }
                if (! $jobId && $envEndDate !== false && $envEndDate !== '') {
                    $jobEnd = $params['endDate'] ?? $params['end_date'] ?? null;
                    if ($jobEnd !== $envEndDate) {
                        $stats['skipped']++;

                        continue;
                    }
                }

                if (! $jobId && $job->getStatus() !== JobStatus::scheduled->value && $job->getStatus() !== JobStatus::delayed->value) {
                    $stats['skipped']++;

                    continue;
                }

                // Global filters from env
                if (! $jobId && ($envChannel = getenv('API_SOURCE')) && $envChannel !== 'none') {
                    if ($job->getChannel() !== $envChannel) {
                        $stats['skipped']++;

                        continue;
                    }
                }
                if (! $jobId && ($envEntity = getenv('API_ENTITY')) && $envEntity !== 'none') {
                    if ($job->getEntity() !== $envEntity) {
                        $stats['skipped']++;

                        continue;
                    }
                }

                // Cooldown check for delayed jobs
                if (! $jobId && $job->getStatus() === JobStatus::delayed->value) {
                    $channelEntity = $this->em->getRepository(ChannelEntity::class)->findOneBy(['name' => $job->getChannel()]);
                    $cooldown = $channelEntity ? $channelEntity->getCooldown() : 600;
                    $updatedAt = $job->getUpdatedAt();
                    if ($updatedAt) {
                        $elapsed = time() - $updatedAt->getTimestamp();
                        if ($elapsed < $cooldown) {
                            $stats['skipped']++;

                            continue;
                        }
                    }
                }

                // Dependency check
                $requires = $params['requires'] ?? null;
                if (! $jobId && $requires) {
                    $requiredInstances = array_map('trim', explode(',', $requires));
                    foreach ($requiredInstances as $requiredInstance) {
                        if (! $jobRepo->hasSuccessfulRecentJob($requiredInstance)) {
                            if (Helpers::isDebug()) {
                                $output->writeln("<comment>Job {$job->getUuid()} depends on '{$requiredInstance}' which has no successful recent execution. Skipping.</comment>");
                            }
                            $this->logger->info("Job {$job->getUuid()} depends on '{$requiredInstance}' which has no successful recent execution. Skipping.");
                            $stats['skipped']++;

                            continue 2; // Skip to next job
                        }
                    }
                }

                // Guard: Prevent another job for the same instance from running if one is already processing
                $instanceName = $payload['instance_name'] ?? null;
                if (! $jobId && $instanceName && $jobRepo->isAnotherJobProcessing($instanceName)) {
                    if (Helpers::isDebug()) {
                        $output->writeln("<comment>Job {$job->getUuid()} skipped because another job for instance '{$instanceName}' is already processing.</comment>");
                    }
                    $this->logger->info("Job {$job->getUuid()} skipped because another job for instance '{$instanceName}' is already processing.");
                    $stats['skipped']++;

                    continue;
                }

                if (Helpers::isDebug()) {
                    $output->writeln("Processing job {$job->getUuid()} for entity {$job->getEntity()} and channel {$job->getChannel()}");
                }
                $this->logger->info("Processing job {$job->getUuid()} for entity {$job->getEntity()} and channel {$job->getChannel()}", [
                    'uuid' => $job->getUuid(),
                    'entity' => $job->getEntity(),
                    'channel' => $job->getChannel(),
                ]);
                $stats['total']++;

                try {
                    // Atomic claim by repository
                    // Even if we provide job-id, we must ensure it's not already being processed by another worker
                    if (! $jobRepo->claimJob($job->getId(), $envInstance)) {
                        if (Helpers::isDebug() || $jobId) {
                            $output->writeln("<comment>Job {$job->getUuid()} already claimed or in progress. Skipping.</comment>");
                        }
                        $stats['skipped']++;
                        $stats['total']--;

                        continue;
                    }

                    $channelName = $job->getChannel();
                    $channelEntity = $this->em->getRepository(ChannelEntity::class)->findOneBy(['name' => $channelName]);
                    if (! $channelEntity) {
                        throw new \Exception("Invalid channel entity: " . $channelName);
                    }

                    // Load full channel configuration
                    $channelsConfig = Helpers::getChannelsConfig();

                    // Normalize channel key (handle common config keys)
                    $chanKey = $channelName;

                    try {
                        $driver = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::get($chanKey);
                        $commonKey = $driver::getCommonConfigKey();
                        if ($commonKey && ! isset($channelsConfig[$chanKey])) {
                            $chanKey = $commonKey;
                        }
                    } catch (\Exception $e) {
                    }

                    $chanConfig = $channelsConfig[$chanKey] ?? null;
                    
                    $isEnabled = true;
                    if ($chanConfig && isset($chanConfig['enabled'])) {
                        $isEnabled = (bool) $chanConfig['enabled'];
                    }

                    // Check if channel is enabled
                    if (!$isEnabled) {
                        $output->writeln("<error>FAILURE: Channel '$chanKey' is EXPLICITLY DISABLED in the loaded configuration.</error>");
                        $output->writeln("<comment>Loaded Config for '$chanKey': " . json_encode($chanConfig) . "</comment>");
                        
                        $jobRepo->update($job->getId(), (object)[
                            'status' => JobStatus::failed->value,
                            'message' => 'Channel is disabled in configuration',
                        ]);
                        $stats['failed']++;
                        continue;
                    }

                    // Resolve relative dates (e.g. 'yesterday' -> '2024-03-05')
                    $resolvedParams = DateResolver::resolveParams($params);

                    // Inject channel configuration into parameters
                    if ($chanConfig) {
                        $resolvedParams = array_merge($chanConfig, $resolvedParams);
                    }

                    // Intelligent incremental sync for entities
                    $instanceName = $payload['instance_name'] ?? null;
                    if ($instanceName && str_ends_with($instanceName, '-entities-sync')) {
                        $smartResume = $params['smart_resume'] ?? $params['resume'] ?? false;
                        if (filter_var($smartResume, FILTER_VALIDATE_BOOLEAN) && empty($resolvedParams['startDate'])) {
                            $lastRun = $jobRepo->getLastSuccessfulJobTime($instanceName);
                            if ($lastRun) {
                                // Fetch only what changed since the last successful sync
                                $resolvedParams['startDate'] = $lastRun->format('Y-m-d H:i:s');
                            }
                        }
                    }

                    $resolvedParams['jobId'] = $job->getId();
                    $body = $payload['body'] ?? null;

                    // Set long timeout for the session to avoid lock waits
                    $connection = $this->em->getConnection();
                    $platform = $connection->getDatabasePlatform();
                    if ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
                        // PostgreSQL uses milliseconds for lock_timeout
                        $connection->executeStatement("SET lock_timeout = '300s'");
                    } else {
                        $connection->executeStatement("SET SESSION innodb_lock_wait_timeout = 300");
                    }

                    $result = $controller->fetchData($job->getEntity(), $channelEntity, $resolvedParams, $body);

                    if ($result instanceof Response && $result->getStatusCode() >= 400) {
                        $content = json_decode($result->getContent(), true);
                        $errorMsg = $content['error'] ?? 'Unknown error from fetchData';

                        // Handle Rate Limits in a generic way
                        // The driver should throw RateLimitException
                        // but if we have specific legacy exceptions we can handle them here for now
                        throw new \Exception($errorMsg);
                    }

                    // Update to completed
                    $jobRepo->update($job->getId(), (object)[
                        'status' => JobStatus::completed->value,
                        'message' => 'Success',
                    ]);

                    // Invalidate recent aggregation caches for this channel
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
                    // Update to failed
                    $jobRepo->update($job->getId(), (object)[
                        'status' => JobStatus::failed->value,
                        'message' => $e->getMessage(),
                    ]);
                    $output->writeln("<error>Failed job {$job->getUuid()}: {$e->getMessage()}</error>");
                    $this->logger->error("Failed job {$job->getUuid()}: {$e->getMessage()}", [
                        'exception' => $e,
                    ]);
                    if (Helpers::isDebug()) {
                        $output->writeln($e->getTraceAsString());
                    }
                    $stats['failed']++;
                    $progressMade = true;
                }
            }

            // Clear EM to avoid memory leaks
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
