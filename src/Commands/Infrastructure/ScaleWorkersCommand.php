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
            
            $activeJobs = array_merge($scheduledJobs, $processingJobs);
            $activeJobsCount = count($activeJobs);

            // 2. Fetch Channel Tiers
            $channelsRepo = $this->entityManager->getRepository(\Entities\Analytics\Channel::class);
            $channels = $channelsRepo->findAll();
            $channelTiers = [];
            foreach ($channels as $c) {
                $channelTiers[$c->getName()] = method_exists($c, 'getTier') ? ($c->getTier() ?? 2) : 2;
            }

            // 3. Calculate Demand Per Tier
            $demandTier2 = 0;
            $demandTier4 = 0;

            foreach ($activeJobs as $job) {
                $tier = $channelTiers[$job->getChannel()] ?? 2;
                $payload = $job->getPayload();
                $name = $payload['instance_name'] ?? '';
                
                $jobDemand = 0;
                if (str_ends_with($name, '-entities')) {
                    $jobDemand = 1.0;
                } elseif (str_ends_with($name, '-recent')) {
                    $jobDemand = 0.5;
                } else { // Historical or other
                    $jobDemand = 2.0;
                }

                if ($tier === 4) {
                    $demandTier4 += $jobDemand;
                } else {
                    $demandTier2 += $jobDemand;
                }
            }

            // 4. Apply Scaling Formula
            $config = Helpers::getProjectConfig();
            $infraConfig = $config['infrastructure'] ?? [];

            $minWorkers = (int)($infraConfig['min_workers'] ?? 1);
            $maxWorkers = (int)($infraConfig['max_workers'] ?? 12);
            $jobsPerWorker = (int)($infraConfig['jobs_per_worker'] ?? 10);

            // Scale to zero override
            if ($activeJobsCount === 0) {
                $tier2Count = 0;
                $tier4Count = 0;
            } else {
                $tier2Count = (int)ceil($demandTier2 / $jobsPerWorker);
                $tier4Count = (int)ceil($demandTier4 / $jobsPerWorker);
                
                // Ensure at least minWorkers for the active tier, usually Tier 2
                if ($demandTier2 > 0 && $tier2Count < $minWorkers) {
                    $tier2Count = $minWorkers;
                }
                if ($demandTier4 > 0 && $tier4Count < $minWorkers) {
                    $tier4Count = $minWorkers; // Only apply min if there is demand for Tier 4
                }

                // Enforce global max limits (prioritize Tier 2 for general workload)
                if (($tier2Count + $tier4Count) > $maxWorkers) {
                    $total = $tier2Count + $tier4Count;
                    $ratio = $maxWorkers / $total;
                    $tier2Count = (int)floor($tier2Count * $ratio);
                    $tier4Count = (int)floor($tier4Count * $ratio);
                }
            }

            $totalTarget = $tier2Count + $tier4Count;
            $output->writeln("Queue Demand - Tier-2: $demandTier2, Tier-4: $demandTier4. Target Workers: $totalTarget (Tier-2: $tier2Count, Tier-4: $tier4Count)");

            // 4. Execute Scaling
            $deploymentName = getenv('DEPLOYMENT_NAME') ?: 'apis-hub';

            // We use 'docker compose up -d' with explicit scaling for the separate tiers
            $cmd = "docker compose -p $deploymentName up -d --no-recreate --scale worker-tier-2=$tier2Count --scale worker-tier-4=$tier4Count";

            $output->writeln("Executing: $cmd");

            // Important: master container must have /var/run/docker.sock mapped
            $projectPathHost = getenv('PROJECT_PATH_HOST') ?: './';
            $cmd = "PROJECT_PATH_HOST=$projectPathHost " . $cmd;

            exec($cmd, $execOutput, $returnVar);

            if ($returnVar !== 0) {
                $output->writeln("<error>Scale failed (exit code $returnVar): ".implode("\n", $execOutput)."</error>");

                return Command::FAILURE;
            }

            $output->writeln("<info>Successfully scaled workers to $totalTarget instances.</info>");

            return Command::SUCCESS;
        }
    }
