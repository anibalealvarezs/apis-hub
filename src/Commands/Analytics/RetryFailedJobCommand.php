<?php

namespace Commands\Analytics;

use Entities\Job;
use Enums\JobStatus;
use Helpers\Helpers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'app:jobs:retry-failed',
    description: 'Manually re-schedules a specific job that has been marked as failed.'
)]
class RetryFailedJobCommand extends Command
{
    private \Doctrine\ORM\EntityManager $em;

    public function __construct(?\Doctrine\ORM\EntityManager $em = null)
    {
        $this->em = $em ?? Helpers::getManager();
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addArgument('job_id', InputArgument::REQUIRED, 'The ID of the failed job to retry');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobId = $input->getArgument('job_id');

        try {
            $jobRepo = $this->em->getRepository(Job::class);

            /** @var Job $failedJob */
            $failedJob = $jobRepo->find($jobId);

            if (! $failedJob) {
                $output->writeln("<error>Job with ID {$jobId} not found.</error>");

                return Command::FAILURE;
            }

            if ($failedJob->getStatus() !== JobStatus::failed->value) {
                $output->writeln("<error>Job {$jobId} is not in a failed state (Current status: " . JobStatus::from($failedJob->getStatus())->name . ").</error>");

                return Command::FAILURE;
            }

            $output->writeln("🔄 <info>Retrying failed job {$jobId}...</info>");

            // Extract the original details
            $payload = $failedJob->getPayload();
            $entity = $failedJob->getEntity();
            $channel = $failedJob->getChannel();
            $priority = $failedJob->getPriority();

            $newJob = clone $failedJob;
            $newJob->addStatus(JobStatus::scheduled->value);
            $newJob->addCreatedAt(new \DateTime());
            $newJob->addUpdatedAt(new \DateTime());
            $newJob->addMessage('Retried from failed job ID: ' . $jobId);
            // reset worker
            $newJob->setWorkerId(null);
            
            // Generate a new unique ID
            $newJob->addUuid(bin2hex(random_bytes(16)));

            $this->em->persist($newJob);
            $this->em->flush(); // Flush to get the new ID

            // Optional: mark original failed job
            $failedJob->addMessage('Retried as job ID: ' . $newJob->getId());
            $this->em->persist($failedJob);
            $this->em->flush();

            $output->writeln("<info>Successfully created new job (ID: {$newJob->getId()}) for retry.</info>");

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");

            return Command::FAILURE;
        }
    }
}
