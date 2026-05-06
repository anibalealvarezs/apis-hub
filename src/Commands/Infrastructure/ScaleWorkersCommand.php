<?php

    namespace Commands\Infrastructure;

    use Doctrine\ORM\EntityManagerInterface;
    use Entities\Job;
    use Enums\JobStatus;
    use Exceptions\ConfigurationException;
    use Helpers\Helpers;
    use Symfony\Component\Console\Attribute\AsCommand;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Output\OutputInterface;

    #[AsCommand(
        name: 'app:scale-workers',
        description: 'Analyzes the job queue and scales the worker pool dynamically via Docker socket'
    )]
    class ScaleWorkersCommand extends Command
    {
        private EntityManagerInterface $entityManager;

        public function __construct(EntityManagerInterface $entityManager)
        {
            $this->entityManager = $entityManager;
            parent::__construct();
        }

        /**
         * @throws ConfigurationException
         */
        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            $jobRepo = $this->entityManager->getRepository(Job::class);

            // 1. Get Active Jobs (Scheduled + Processing)
            $scheduledJobs = $jobRepo->findBy(['status' => JobStatus::scheduled->value]);
            $processingJobs = $jobRepo->findBy(['status' => JobStatus::processing->value]);

            $activeJobsCount = count($scheduledJobs) + count($processingJobs);

            // 2. Calculate Demand
            $demand = 0;
            foreach ($scheduledJobs as $job) {
                $payload = $job->getPayload();
                $name = $payload['instance_name'] ?? '';

                if (str_ends_with($name, '-entities')) {
                    $demand += 1.0;
                } elseif (str_ends_with($name, '-recent')) {
                    $demand += 0.5;
                } else { // Historical or other
                    $demand += 2.0;
                }
            }

            // 3. Apply Scaling Formula
            $config = Helpers::getProjectConfig();
            $infraConfig = $config['infrastructure'] ?? [];

            $minWorkers = (int)($infraConfig['min_workers'] ?? 1);
            $maxWorkers = (int)($infraConfig['max_workers'] ?? 10);
            $jobsPerWorker = (int)($infraConfig['jobs_per_worker'] ?? 10);

            $targetCount = (int)ceil(max($demand, (float)$activeJobsCount) / $jobsPerWorker);
            $targetCount = max($minWorkers, min($maxWorkers, $targetCount));

            $output->writeln("Queue Demand Score: $demand. Target Workers: $targetCount");

            // 4. Execute Scaling
            $deploymentName = getenv('DEPLOYMENT_NAME') ?: 'apis-hub';

            // We use 'docker compose up -d --scale worker=N' to adjust the pool
            // We add --no-recreate to ensure we don't restart the master/mcp containers
            $cmd = "docker compose -p $deploymentName up -d --no-recreate --scale worker=$targetCount";

            $output->writeln("Executing: $cmd");

            // Important: master container must have /var/run/docker.sock mapped
            exec($cmd, $execOutput, $returnVar);

            if ($returnVar !== 0) {
                $output->writeln("<error>Scale failed (exit code $returnVar): ".implode("\n", $execOutput)."</error>");

                return Command::FAILURE;
            }

            $output->writeln("<info>Successfully scaled 'worker' service to $targetCount instances.</info>");

            return Command::SUCCESS;
        }
    }
