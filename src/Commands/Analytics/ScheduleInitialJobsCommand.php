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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = Helpers::getProjectConfig();
        $instances = $config['instances'] ?? [];

        if (empty($instances)) {
            $output->writeln('<comment>No instances found in project configuration.</comment>');
            return Command::SUCCESS;
        }

        $jobRepository = $this->entityManager->getRepository(Job::class);
        $scheduledCount = 0;
        $skippedCount = 0;

        foreach ($instances as $instance) {
            $channel = $instance['channel'] ?? null;
            $entity = $instance['entity'] ?? null;
            $name = $instance['name'] ?? 'unknown';

            if (!$channel || !$entity) {
                $output->writeln("<error>Instance $name is missing channel or entity. Skipping.</error>");
                continue;
            }

            $params = [];
            if (!empty($instance['start_date'])) $params['startDate'] = $instance['start_date'];
            if (!empty($instance['end_date'])) $params['endDate'] = $instance['end_date'];

            // Check if a similar job is already scheduled or processing
            $existingJob = $jobRepository->findOneBy([
                'channel' => $channel,
                'entity' => $entity,
                'status' => [JobStatus::scheduled->value, JobStatus::processing->value]
            ]);

            // We also need to check the payload to be sure it's the SAME range
            $shouldSchedule = true;
            if ($existingJob) {
                $payload = $existingJob->getPayload();
                $existingParams = $payload['params'] ?? [];
                if ($existingParams == $params) {
                    $shouldSchedule = false;
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
                $output->writeln("<info>Scheduled job for $name ($channel -> $entity)</info>");
            } else {
                $skippedCount++;
                $output->writeln("<comment>Job for $name already exists in queue. Skipping.</comment>");
            }
        }

        $this->entityManager->flush();

        $output->writeln("<info>Successfully scheduled $scheduledCount new jobs ($skippedCount skipped).</info>");

        return Command::SUCCESS;
    }
}
