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
            
            $activeChannelsTier2 = [];
            $activeChannelsTier4 = [];

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
                    $activeChannelsTier4[$job->getChannel()] = true;
                } else {
                    $demandTier2 += $jobDemand;
                    $activeChannelsTier2[$job->getChannel()] = true;
                }
            }
            
            $uniqueChannelsTier2 = count($activeChannelsTier2);
            $uniqueChannelsTier4 = count($activeChannelsTier4);

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
                
                // Guarantee at least 1 worker per active channel to ensure parallel execution
                if ($tier2Count < $uniqueChannelsTier2) {
                    $tier2Count = $uniqueChannelsTier2;
                }
                if ($tier4Count < $uniqueChannelsTier4) {
                    $tier4Count = $uniqueChannelsTier4;
                }
                
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

            // To achieve true auto-healing resilience, the master container must dynamically
            // discover its own absolute host path from the Docker Daemon. This prevents
            // "Docker-in-Docker" volume mapping corruption and heals already broken workers.
            $masterContainerId = gethostname();
            $ch = curl_init('http://localhost/containers/'.$masterContainerId.'/json');
            curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            $json = curl_exec($ch);
            curl_close($ch);
            
            $data = json_decode($json ?: '{}', true);
            $hostPath = './';
            if (is_array($data) && isset($data['Mounts'])) {
                foreach ($data['Mounts'] as $mount) {
                    if (isset($mount['Destination']) && $mount['Destination'] === '/app') {
                        $hostPath = $mount['Source'];
                        break;
                    }
                }
            }

            // Dynamically target only the services that actually exist in the tenant's docker-compose.yml
            // This prevents "no such service" errors when a tenant doesn't require a specific tier.
            $composeFile = '/app/docker-compose.yml';
            $composeContent = file_exists($composeFile) ? file_get_contents($composeFile) : '';
            
            $scaleArgs = [];
            $targetServices = [];
            
            if (str_contains($composeContent, 'worker-tier-2:')) {
                $scaleArgs[] = "--scale worker-tier-2=$tier2Count";
                $targetServices[] = 'worker-tier-2';
            }
            if (str_contains($composeContent, 'worker-tier-4:')) {
                $scaleArgs[] = "--scale worker-tier-4=$tier4Count";
                $targetServices[] = 'worker-tier-4';
            }

            if (empty($targetServices)) {
                $output->writeln("<info>No worker tiers found in docker-compose.yml. Nothing to scale.</info>");
                return Command::SUCCESS;
            }

            $scaleStr = implode(' ', $scaleArgs);
            $targetStr = implode(' ', $targetServices);

            // We use 'docker compose up -d' passing the specific services and --no-deps to guarantee it NEVER touches the master container.
            $cmd = "docker compose -p $deploymentName up -d --no-deps $scaleStr $targetStr";
            $cmd = "PROJECT_PATH_HOST=\"$hostPath\" " . $cmd;

            $output->writeln("Executing auto-healing scaling: $cmd");
            exec($cmd, $execOutput, $returnVar);

            if ($returnVar !== 0) {
                $output->writeln("<error>Scale failed (exit code $returnVar): ".implode("\n", $execOutput)."</error>");
                return Command::FAILURE;
            }

            $output->writeln("<info>Successfully auto-healed and scaled workers (Tier 2: $tier2Count, Tier 4: $tier4Count).</info>");

            return Command::SUCCESS;
        }
    }
