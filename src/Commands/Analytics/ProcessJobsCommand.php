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

        $output->writeln("Querying scheduled and delayed jobs...");

        $envStartDate = getenv('START_DATE');
        $envEndDate = getenv('END_DATE');

        $jobs = $jobRepo->findBy(['status' => [JobStatus::scheduled->value, JobStatus::delayed->value]]);

        if (empty($jobs)) {
            $output->writeln("No jobs found.");
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

            // If instance has specific range, filter out jobs not matching it
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
                if ($chanEnum = Channel::tryFromName($envChannel)) {
                    $envChannel = $chanEnum->name;
                }
                if ($job->getChannel() !== $envChannel) {
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
                        $remaining = $cooldown - $elapsed;
                        $minutes = ceil($remaining / 60);
                        $output->writeln("<comment>Job {$job->getUuid()} is in cooldown. Skipping (available in {$minutes} mins).</comment>");
                        $stats['skipped']++;
                        continue;
                    }
                }
            }

            $output->writeln("Processing job {$job->getUuid()} for entity {$job->getEntity()} and channel {$job->getChannel()}");
            $stats['total']++;

            try {
                // Atomic claim by repository
                if (!$jobRepo->claimJob($job->getId())) {
                    $output->writeln("Job {$job->getUuid()} already claimed by another worker. Skipping.");
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

                $result = $controller->fetchData($job->getEntity(), $channelEnum, $params, $body);

                // Check if fetchData returned an error Response
                if ($result instanceof Response && $result->getStatusCode() >= 400) {
                    $content = json_decode($result->getContent(), true);
                    $errorMsg = $content['error'] ?? 'Unknown error from fetchData';
                    throw new \Exception($errorMsg);
                }

                // Update to completed
                $jobRepo->update($job->getId(), (object)[
                    'status' => JobStatus::completed->value,
                    'message' => 'Success'
                ]);
                $output->writeln("<info>Successfully completed job {$job->getUuid()}</info>");
                $stats['completed']++;

            } catch (FacebookRateLimitException $e) {
                // Update to delayed
                $currentMessage = $job->getMessage();
                $newMessage = $e->getMessage();
                $updatedMessage = $currentMessage ? $currentMessage . PHP_EOL . $newMessage : $newMessage;
                
                $jobRepo->update($job->getId(), (object)[
                    'status' => JobStatus::delayed->value,
                    'message' => $updatedMessage
                ]);
                $output->writeln("<comment>Rate limit reached for job {$job->getUuid()}. Job delayed for cooldown.</comment>");
                $stats['delayed']++;
            } catch (Throwable $e) {
                // Update to failed
                $jobRepo->update($job->getId(), (object)[
                    'status' => JobStatus::failed->value,
                    'message' => $e->getMessage()
                ]);
                $output->writeln("<error>Failed job {$job->getUuid()}: {$e->getMessage()}</error>");
                $stats['failed']++;
            }
        }

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

        return Command::SUCCESS;
    }
}
