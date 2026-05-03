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
        Helpers::reconnectIfNeeded($this->em);
        /** @var \Repositories\JobRepository $jobRepo */
        $jobRepo = $this->em->getRepository(Job::class);

        $envChannel = getenv('API_SOURCE');
        $envInstance = getenv('INSTANCE_NAME');
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
                    channel: ($forceAll ? null : $envChannel),
                    instanceName: ($forceAll ? null : $envInstance)
                );
                $delayedJobs = $jobRepo->getJobsByStatus(
                    status: JobStatus::delayed->value,
                    channel: ($forceAll ? null : $envChannel),
                    instanceName: ($forceAll ? null : $envInstance)
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
                if (! $jobId && ! $forceAll && $envInstance && $jobInstance && $envInstance !== $jobInstance) {
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
                if (! $jobId && $envChannel = getenv('API_SOURCE')) {
                    if ($job->getChannel() !== $envChannel) {
                        $stats['skipped']++;

                        continue;
                    }
                }
                if (! $jobId && $envEntity = getenv('API_ENTITY')) {
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
                    if (! $jobRepo->claimJob($job->getId())) {
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
                    $this->logger->debug("PROCESS_JOBS DEBUG: Instance " . ($instanceName ?? 'unknown') . " - Loaded chanConfig keys: " . implode(', ', array_keys($chanConfig ?? [])));

                    // Check if channel is enabled
                    if ($chanConfig && isset($chanConfig['enabled']) && ! $chanConfig['enabled']) {
                        $jobRepo->update($job->getId(), (object)[
                            'status' => JobStatus::failed->value,
                            'message' => 'Channel is disabled in configuration',
                        ]);
                        if (Helpers::isDebug()) {
                            $output->writeln("<comment>Job {$job->getUuid()} marked as failed because channel {$channelName} is disabled.</comment>");
                        }
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
