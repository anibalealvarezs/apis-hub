<?php

namespace Commands\Analytics;

use Helpers\Helpers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Enums\JobStatus;
use Entities\Job;
use Throwable;

#[AsCommand(
    name: 'app:jobs-retry',
    description: 'Reschedules failed jobs back to scheduled status'
)]
class RetryFailedJobsCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('channel', 'c', InputOption::VALUE_OPTIONAL, 'Retry only for this channel');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channel = $input->getOption('channel');
        
        try {
            $em = Helpers::getManager();
            $jobRepo = $em->getRepository(Job::class);

            $criteria = ['status' => JobStatus::failed->value];
            if ($channel) {
                $criteria['channel'] = $channel;
            }

            $failedJobs = $jobRepo->findBy($criteria);

            if (empty($failedJobs)) {
                $output->writeln("<info>No failed jobs found to retry.</info>");
                return Command::SUCCESS;
            }

            $count = count($failedJobs);
            $output->writeln("🔄 <info>Rescheduling $count failed jobs...</info>");

            foreach ($failedJobs as $job) {
                // Use a direct update via connection for speed if there are many, 
                // but let's use the repository for safety.
                $job->setStatus(JobStatus::scheduled->value);
                $job->setUpdatedAt(new \DateTime());
                $job->setMessage('Manually rescheduled for retry.');
                $em->persist($job);
            }

            $em->flush();

            $output->writeln("<info>Successfully rescheduled $count jobs.</info>");
            
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }
}
