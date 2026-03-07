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
        $filters = ['status' => JobStatus::scheduled->value];

        if ($envChannel = getenv('API_SOURCE')) {
            // Normalize container alias (e.g. 'fb-ads') to channel name (e.g. 'facebook')
            if ($chanEnum = Channel::tryFromName($envChannel)) {
                $envChannel = $chanEnum->name;
            }
            $filters['channel'] = $envChannel;
        }
        if ($envEntity = getenv('API_ENTITY')) {
            $filters['entity'] = $envEntity;
        }

        $envStartDate = getenv('START_DATE');
        $envEndDate = getenv('END_DATE');

        $jobs = $jobRepo->findBy($filters);

        if (empty($jobs)) {
            $output->writeln("No scheduled jobs found.");
            return Command::SUCCESS;
        }

        $controller = new CacheController();

        /** @var Job $job */
        foreach ($jobs as $job) {
            $payload = $job->getPayload() ?? [];
            $params = $payload['params'] ?? [];

            // If instance has specific range, filter out jobs not matching it
            if ($envStartDate !== false && $envStartDate !== '') {
                $jobStart = $params['startDate'] ?? $params['start_date'] ?? null;
                if ($jobStart !== $envStartDate) {
                    continue;
                }
            }
            if ($envEndDate !== false && $envEndDate !== '') {
                $jobEnd = $params['endDate'] ?? $params['end_date'] ?? null;
                if ($jobEnd !== $envEndDate) {
                    continue;
                }
            }

            $output->writeln("Processing job {$job->getUuid()} for entity {$job->getEntity()} and channel {$job->getChannel()}");

            try {
                // Atomic claim by repository
                if (!$jobRepo->claimJob($job->getId())) {
                    $output->writeln("Job {$job->getUuid()} already claimed by another worker. Skipping.");
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
