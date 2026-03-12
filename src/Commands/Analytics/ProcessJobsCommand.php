<?php

namespace Commands\Analytics;

use Controllers\CacheController;
use Doctrine\ORM\EntityManager;
use Entities\Job;
use Enums\Channel;
use Enums\JobStatus;
use Helpers\Helpers;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Services\DateResolver;
use Symfony\Component\HttpFoundation\Response;
use Anibalealvarezs\FacebookGraphApi\Exceptions\FacebookRateLimitException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Throwable;

class ProcessJobsCommand extends Command
{
    protected static $defaultName = 'jobs:process';
    private EntityManager $em;

    public function __construct()
    {
        $this->em = Helpers::getManager();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Processes scheduled caching jobs.')
             ->setHelp('This command looks for scheduled jobs in the database and executes them via the CacheController.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var \Repositories\JobRepository $jobRepo */
        $jobRepo = $this->em->getRepository(Job::class);

        $envChannel = getenv('API_SOURCE');
        $envInstance = getenv('INSTANCE_NAME');
        
        $jobs = $jobRepo->getJobsByStatus(
            status: JobStatus::scheduled->value,
            channel: $envChannel,
            instanceName: $envInstance
        );
        $delayedJobs = $jobRepo->getJobsByStatus(
            status: JobStatus::delayed->value,
            channel: $envChannel,
            instanceName: $envInstance
        );
        $jobs = array_merge($jobs, $delayedJobs);

        $envStartDate = getenv('START_DATE');
        $envEndDate = getenv('END_DATE');

        if (empty($jobs)) {
            return Command::SUCCESS;
        }

        $controller = new CacheController();

        $stats = [
            'completed' => 0,
            'failed' => 0,
            'delayed' => 0,
            'skipped' => 0,
            'total' => 0
        ];

        /** @var Job $job */
        foreach ($jobs as $job) {
            $payload = $job->getPayload() ?? [];
            $params = $payload['params'] ?? [];

            // 1. Instance Name Match (Primary Filter)
            $envInstance = getenv('INSTANCE_NAME');
            $jobInstance = $payload['instance_name'] ?? null;
            if ($envInstance && $jobInstance && $envInstance !== $jobInstance) {
                $stats['skipped']++;
                continue;
            }

            // 2. Date Range Filters (Fallback/Secondary)
            if ($envStartDate !== false && $envStartDate !== '') {
                $jobStart = $params['startDate'] ?? $params['start_date'] ?? null;
                if ($jobStart !== $envStartDate) {
                    $stats['skipped']++;
                    continue;
                }
            }
            if ($envEndDate !== false && $envEndDate !== '') {
                $jobEnd = $params['endDate'] ?? $params['end_date'] ?? null;
                if ($jobEnd !== $envEndDate) {
                    $stats['skipped']++;
                    continue;
                }
            }

            if ($job->getStatus() !== JobStatus::scheduled->value && $job->getStatus() !== JobStatus::delayed->value) {
                $stats['skipped']++;
                continue;
            }

            // Global filters from env
            if ($envChannel = getenv('API_SOURCE')) {
                $envChannelEnum = Channel::tryFromName($envChannel);
                $jobChannelEnum = Channel::tryFromName($job->getChannel());
                
                $envMatch = $envChannelEnum ? $envChannelEnum->name : $envChannel;
                $jobMatch = $jobChannelEnum ? $jobChannelEnum->name : $job->getChannel();

                if ($jobMatch !== $envMatch) {
                    $stats['skipped']++;
                    continue;
                }
            }
            if ($envEntity = getenv('API_ENTITY')) {
                if ($job->getEntity() !== $envEntity) {
                    $stats['skipped']++;
                    continue;
                }
            }

            // Cooldown check for delayed jobs
            if ($job->getStatus() === JobStatus::delayed->value) {
                $channelEnum = Channel::tryFromName($job->getChannel());
                $cooldown = $channelEnum ? $channelEnum->getCooldown() : 600;
                $updatedAt = $job->getUpdatedAt();
                if ($updatedAt) {
                    $elapsed = time() - $updatedAt->getTimestamp();
                    if ($elapsed < $cooldown) {
                        if (Helpers::isDebug()) {
                            $remaining = $cooldown - $elapsed;
                            $minutes = ceil($remaining / 60);
                            $output->writeln("<comment>Job {$job->getUuid()} is in cooldown. Skipping (available in {$minutes} mins).</comment>");
                        }
                        $stats['skipped']++;
                        continue;
                    }
                }
            }

            // Dependency check
            $requires = $params['requires'] ?? null;
            if ($requires) {
                $requiredInstances = array_map('trim', explode(',', $requires));
                foreach ($requiredInstances as $requiredInstance) {
                    if (!$jobRepo->hasSuccessfulRecentJob($requiredInstance)) {
                        if (Helpers::isDebug()) {
                            $output->writeln("<comment>Job {$job->getUuid()} depends on '{$requiredInstance}' which has no successful recent execution. Skipping.</comment>");
                        }
                        $stats['skipped']++;
                        continue 2; // Skip to next job
                    }
                }
            }

            if (Helpers::isDebug()) {
                $output->writeln("Processing job {$job->getUuid()} for entity {$job->getEntity()} and channel {$job->getChannel()}");
            }
            $stats['total']++;

            try {
                // Atomic claim by repository
                if (!$jobRepo->claimJob($job->getId())) {
                    if (Helpers::isDebug()) {
                        $output->writeln("Job {$job->getUuid()} already claimed by another worker. Skipping.");
                    }
                    $stats['skipped']++;
                    $stats['total']--;
                    continue;
                }

                $channelEnum = Channel::tryFromName($job->getChannel());
                if (!$channelEnum) {
                    throw new \Exception("Invalid channel enum: " . $job->getChannel());
                }

                // Finish parameter resolution...
                // Resolve relative dates (e.g. 'yesterday' -> '2024-03-05')
                $params = DateResolver::resolveParams($params);
                
                $params['jobId'] = $job->getId();
                $body = $payload['body'] ?? null;

                // Increase lock wait timeout for this connection to reduce contention
                // when multiple containers write to shared tables simultaneously
                $this->em->getConnection()->executeStatement('SET SESSION innodb_lock_wait_timeout = 120');

                $result = $controller->fetchData($job->getEntity(), $channelEnum, $params, $body);

                // Check if fetchData returned an error Response
                if ($result instanceof Response && $result->getStatusCode() >= 400) {
                    $content = json_decode($result->getContent(), true);
                    $errorMsg = $content['error'] ?? 'Unknown error from fetchData';
                    if ($result->getStatusCode() === 429) {
                        throw new FacebookRateLimitException($errorMsg);
                    }
                    throw new \Exception($errorMsg);
                }

                // Update to completed
                $jobRepo->update($job->getId(), (object)[
                    'status' => JobStatus::completed->value,
                    'message' => 'Success'
                ]);
                if (Helpers::isDebug()) {
                    $output->writeln("<info>Successfully completed job {$job->getUuid()}</info>");
                }
                $stats['completed']++;

            } catch (FacebookRateLimitException $e) {
                if (Helpers::isDebug()) {
                    $output->writeln("<comment>Rate limit reached for job {$job->getUuid()}. Job delayed for cooldown.</comment>");
                }
                $jobRepo->markAsDelayed($job->getId(), $e->getMessage());
                $stats['delayed']++;
            } catch (LockWaitTimeoutException $e) {
                if (Helpers::isDebug()) {
                    $output->writeln("<comment>Lock timeout for job {$job->getUuid()}. Reset to scheduled for retry.</comment>");
                }
                $jobRepo->resetJob($job->getId());
                $stats['delayed']++;
            } catch (Throwable $e) {
                // Update to failed
                $jobRepo->update($job->getId(), (object)[
                    'status' => JobStatus::failed->value,
                    'message' => $e->getMessage()
                ]);
                $output->writeln("<error>Failed job {$job->getUuid()}: {$e->getMessage()}</error>");
                $output->writeln($e->getTraceAsString());
                $stats['failed']++;
            }
        }

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
                $output->writeln("<comment>Jobs Skipped: {$stats['skipped']} (not matching instance context or in cooldown)</comment>");
            }
        }

        return Command::SUCCESS;
    }
}
