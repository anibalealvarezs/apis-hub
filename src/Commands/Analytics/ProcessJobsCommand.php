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
        
        $output->writeln("Querying scheduled jobs...");

        // Note: findBy is provided by Doctrine EntityRepository
        $jobs = $jobRepo->findBy(['status' => JobStatus::scheduled->value]);

        if (empty($jobs)) {
            $output->writeln("No scheduled jobs found.");
            return Command::SUCCESS;
        }

        $controller = new CacheController();

        /** @var Job $job */
        foreach ($jobs as $job) {
            $output->writeln("Processing job {$job->getUuid()} for entity {$job->getEntity()} and channel {$job->getChannel()}");

            try {
                // Update to processing
                $jobRepo->update($job->getId(), (object)['status' => JobStatus::processing->value]);
                
                $channelEnum = Channel::tryFromName($job->getChannel());
                if (!$channelEnum) {
                    throw new \Exception("Invalid channel enum: " . $job->getChannel());
                }

                // Iniciar el caching de data via fetchData
                $payload = $job->getPayload() ?? [];
                $params = $payload['params'] ?? null;
                $body = $payload['body'] ?? null;
                
                $controller->fetchData($job->getEntity(), $channelEnum, $params, $body);

                // Update to completed
                $jobRepo->update($job->getId(), (object)['status' => JobStatus::completed->value]);
                $output->writeln("<info>Successfully completed job {$job->getUuid()}</info>");
                
            } catch (Throwable $e) {
                // Update to failed
                $jobRepo->update($job->getId(), (object)['status' => JobStatus::failed->value]);
                $output->writeln("<error>Failed job {$job->getUuid()}: {$e->getMessage()}</error>");
            }
        }

        return Command::SUCCESS;
    }
}
