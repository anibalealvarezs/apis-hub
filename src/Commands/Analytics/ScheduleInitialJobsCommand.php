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

            if ($channel && ($chanEnum = \Enums\Channel::tryFromName($channel))) {
                $channel = $chanEnum->name;
            }

            if (!$channel || !$entity) {
                $output->writeln("<error>Instance $name is missing channel or entity. Skipping.</error>");
                continue;
            }

            // Check if channel is enabled and history range
            $channelsConfig = Helpers::getChannelsConfig();
            $chanKey = $channel;
            if (!isset($channelsConfig[$chanKey])) {
                $chanKey = str_replace(['facebook_marketing', 'facebook_organic'], ['facebook', 'facebook'], $channel);
            }
            // Specific check for split facebook configs
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
                        $limitDate = new \DateTime();
                        $limitDate->modify('-' . $chanConfig['cache_history_range']);
                        $jobEndDate = new \DateTime($instance['end_date']);
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
            }

            $params = [];
            if (!empty($instance['start_date'])) $params['startDate'] = $instance['start_date'];
            if (!empty($instance['end_date'])) $params['endDate'] = $instance['end_date'];
            if (!empty($instance['requires'])) $params['requires'] = $instance['requires'];

            $shouldSchedule = true;
            // Try to find ANY existing job for this instance (any status)
            $existingJob = $jobRepository->findOneBy([
                'channel' => $channel,
                'entity' => $entity,
            ]);

            if ($existingJob) {
                // If we found a generic match, verify it belongs to this instance
                $payload = $existingJob->getPayload();
                $jobInstance = $payload['instance_name'] ?? null;
                $jobParams = $payload['params'] ?? [];
                
                if ($jobInstance === $name || ($jobParams == $params)) {
                    $shouldSchedule = false;
                } else {
                    // Look specifically for a job with this instance name in its payload
                    if (Helpers::isPostgres()) {
                        $sql = "SELECT count(j.id) FROM jobs j WHERE j.channel = :channel AND j.entity = :entity AND CAST(j.payload AS text) LIKE :instance_pattern";
                        $stmt = $this->entityManager->getConnection()->prepare($sql);
                        $count = $stmt->executeQuery([
                            'channel' => $channel,
                            'entity' => $entity,
                            'instance_pattern' => '%instance_name%' . $name . '%',
                        ])->fetchOne();
                    } else {
                        $qb = $this->entityManager->createQueryBuilder();
                        $count = $qb->select('count(j.id)')
                            ->from(Job::class, 'j')
                            ->where('j.channel = :channel')
                            ->andWhere('j.entity = :entity')
                            ->andWhere('j.payload LIKE :instance_pattern')
                            ->setParameter('channel', $channel)
                            ->setParameter('entity', $entity)
                            ->setParameter('instance_pattern', '%instance_name%' . $name . '%')
                            ->getQuery()
                            ->getSingleScalarResult();
                    }
                    
                    if ((int)$count > 0) {
                        $shouldSchedule = false;
                    }
                }
            }

            if ($shouldSchedule) {
                $job = new Job();
                $job->addChannel($channel);
                $job->addEntity($entity);
                
                // For '-recent' instances, we schedule them as CANCELLED initially 
                // to prevent redundancy with historical jobs during deployment,
                // while still keeping them available in the UI for re-scheduling.
                $isRecent = str_ends_with($name, '-recent');
                $job->addStatus($isRecent ? JobStatus::cancelled->value : JobStatus::scheduled->value);
                
                $job->addUuid(bin2hex(random_bytes(16)));
                $job->addPayload([
                    'params' => $params,
                    'instance_name' => $name
                ]);
                
                $msg = $isRecent 
                    ? "Initial job cancelled during deployment to prevent redundancy. Will run via cron at next scheduled time."
                    : "Initial scheduling from deployment command";
                $job->addMessage($msg);
                
                $job->addCreatedAt(new \DateTime());
                $job->addUpdatedAt(new \DateTime());

                $this->entityManager->persist($job);
                $scheduledCount++;
                if (Helpers::isDebug()) {
                    $statusName = $isRecent ? 'cancelled' : 'scheduled';
                    $output->writeln("<info>Created initial $statusName job for $name ($channel -> $entity)</info>");
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
