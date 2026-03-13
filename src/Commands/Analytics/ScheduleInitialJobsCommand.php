<?php

namespace Commands\Analytics;

use Doctrine\ORM\EntityManagerInterface;
use Entities\Job;
use Enums\JobStatus;
use Helpers\Helpers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
        $this->addOption('instance', null, \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'Schedule only for this instance name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = Helpers::getProjectConfig();
        $instances = $config['instances'] ?? [];
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

            // Filter by instance if provided
            if ($targetInstance && $targetInstance !== $name) {
                continue;
            }

            $channel = $instance['channel'] ?? null;
            $entity = $instance['entity'] ?? null;

            if (!$channel || !$entity) {
                $output->writeln("<error>Instance $name is missing channel or entity. Skipping.</error>");
                continue;
            }

            $params = [];
            if (!empty($instance['start_date'])) $params['startDate'] = $instance['start_date'];
            if (!empty($instance['end_date'])) $params['endDate'] = $instance['end_date'];
            if (!empty($instance['requires'])) $params['requires'] = $instance['requires'];

            $shouldSchedule = true;
            // 1. Try to find by instance_name in payload first (most accurate)
            $existingJob = $jobRepository->findOneBy([
                'channel' => $channel,
                'entity' => $entity,
                'status' => [JobStatus::scheduled->value, JobStatus::processing->value]
            ]);

            // If we found one, we must verify it really belongs to THIS instance
            // because findOneBy above is too generic
            if ($existingJob) {
                $payload = $existingJob->getPayload();
                $jobInstance = $payload['instance_name'] ?? null;
                $jobParams = $payload['params'] ?? [];
                
                // Match either by explicit instance name OR by exact params/dates
                if ($jobInstance === $name || ($jobParams == $params)) {
                    $shouldSchedule = false;
                } else {
                    // It was a job for a DIFFERENT instance, so we need to look specifically for ours
                    $specificJob = $jobRepository->getJobsByStatus(
                        status: JobStatus::scheduled->value,
                        channel: $channel,
                        instanceName: $name
                    );
                    if (empty($specificJob)) {
                        $specificJob = $jobRepository->getJobsByStatus(
                            status: JobStatus::processing->value,
                            channel: $channel,
                            instanceName: $name
                        );
                    }
                    if (!empty($specificJob)) {
                        $shouldSchedule = false;
                    }
                }
            }

            if ($shouldSchedule) {
                $job = new Job();
                $job->addChannel($channel);
                $job->addEntity($entity);
                $job->addStatus(JobStatus::scheduled->value);
                $job->addUuid(bin2hex(random_bytes(16)));
                $job->addPayload([
                    'params' => $params,
                    'instance_name' => $name
                ]);
                $job->addMessage("Initial scheduling from deployment command");
                $job->addCreatedAt(new \DateTime());
                $job->addUpdatedAt(new \DateTime());

                $this->entityManager->persist($job);
                $scheduledCount++;
                if (Helpers::isDebug()) {
                    $output->writeln("<info>Scheduled job for $name ($channel -> $entity)</info>");
                }
            } else {
                $skippedCount++;
                if (Helpers::isDebug()) {
                    $output->writeln("<comment>Job for $name already exists in queue. Skipping.</comment>");
                }
            }
        }

        $this->entityManager->flush();

        if (Helpers::isDebug()) {
            $output->writeln("<info>Successfully scheduled $scheduledCount new jobs ($skippedCount skipped).</info>");
        }

        return Command::SUCCESS;
    }
}
