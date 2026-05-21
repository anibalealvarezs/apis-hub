<?php

namespace Commands\Analytics;

use Helpers\Helpers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Entities\Job;
use Throwable;
use Services\DockerService; // Hypothetical, need to check how to get dead workers or if we just call the repo

#[AsCommand(
    name: 'app:jobs:reset',
    description: 'Manually triggers the job rescheduling and cleanup logic for stuck jobs.'
)]
class ResetStuckJobsCommand extends Command
{
    private \Doctrine\ORM\EntityManager $em;

    public function __construct(?\Doctrine\ORM\EntityManager $em = null)
    {
        $this->em = $em ?? Helpers::getManager();
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addOption('threshold', 't', InputOption::VALUE_OPTIONAL, 'Threshold in minutes before a job is considered stuck', 120);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $threshold = (int) $input->getOption('threshold');
        
        try {
            $jobRepo = $this->em->getRepository(Job::class);

            $output->writeln("🔄 <info>Resetting orphaned jobs (threshold: {$threshold}m)...</info>");
            $orphanedCount = $jobRepo->resetAllOrphanedJobs($threshold);
            $output->writeln("<info>Successfully reset $orphanedCount orphaned jobs.</info>");

            // Optional: reset jobs by dead workers if we can determine active workers
            // This usually requires Docker API access to list running containers.
            // For now, we rely on the watchdog for dead workers, but if DockerService exists, we could use it.
            if (class_exists('\Services\DockerService')) {
                // Example pseudo-code
                // $activeWorkers = \Services\DockerService::getActiveWorkerIds();
                // $deadWorkerCount = $jobRepo->resetJobsByDeadWorkers($activeWorkers);
                // $output->writeln("<info>Successfully reset $deadWorkerCount jobs from dead workers.</info>");
            }

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }
}
