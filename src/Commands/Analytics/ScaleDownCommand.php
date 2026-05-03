<?php

namespace Commands\Analytics;

use Doctrine\ORM\EntityManager;
use Entities\Job;
use Enums\JobStatus;
use Helpers\Helpers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:scale-down',
    description: 'Automatically shuts down idle historical data containers.',
    aliases: ['infra:scale-down']
)]
class ScaleDownCommand extends Command
{
    private EntityManager $em;

    public function __construct(?EntityManager $em = null)
    {
        $this->em = $em ?? Helpers::getManager();
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (! Helpers::isMaster()) {
            $output->writeln("<error>Scale Down can only be executed from the Master container.</error>");

            return Command::FAILURE;
        }

        $config = Helpers::getProjectConfig();
        $instances = $config['instances'] ?? [];

        /** @var \Repositories\JobRepository $jobRepo */
        $jobRepo = $this->em->getRepository(Job::class);

        $output->writeln("Checking for idle historical containers...");

        foreach ($instances as $instance) {
            $name = $instance['name'];

            // Rule: Only historical containers (format: [channel]-YYYY-MM)
            if (! preg_match('/-[0-9]{4}-[0-9]{2}$/', $name)) {
                continue;
            }

            // 1. Check if container is actually running
            $dockerStatus = shell_exec("docker inspect -f '{{.State.Running}}' {$name} 2>/dev/null");
            if (trim($dockerStatus) !== 'true') {
                continue;
            }

            // 2. Check for active/pending jobs
            $pendingCount = $jobRepo->count([
                'status' => [JobStatus::scheduled->value, JobStatus::processing->value, JobStatus::delayed->value],
                'payload' => ['instance_name' => $name], // Note: Repository filter might need JSON support
            ]);

            // If getByStatus is more reliable for JSON payload filtering:
            $remaining = $jobRepo->getJobsByStatus(
                status: JobStatus::scheduled->value,
                instanceName: $name
            );
            $processing = $jobRepo->getJobsByStatus(
                status: JobStatus::processing->value,
                instanceName: $name
            );

            if (! empty($remaining) || ! empty($processing)) {
                $output->writeln("<comment>Container {$name} is BUSY (" . (count($remaining) + count($processing)) . " jobs).</comment>");

                continue;
            }

            // 3. Cooldown Check: Don't kill if the last job finished < 15 minutes ago
            $lastJob = $jobRepo->findOneBy(
                ['status' => [JobStatus::completed->value, JobStatus::failed->value]],
                ['updated_at' => 'DESC']
            );
            // Wait, we need the last job FOR THIS INSTANCE.
            // Since we're iterating instances, let's query specific to the instance.

            $lastJobTime = $jobRepo->getLastSuccessfulJobTime($name);
            if ($lastJobTime) {
                $elapsed = time() - $lastJobTime->getTimestamp();
                if ($elapsed < 900) { // 15 minutes
                    $output->writeln("<comment>Container {$name} is IDLE but in 15m cooldown.</comment>");

                    continue;
                }
            }

            // 4. ACTION: Scale Down
            $output->writeln("<info>Container {$name} has been IDLE for >15m. Shutting down...</info>");
            shell_exec("docker stop {$name}");
        }

        $output->writeln("Scale Down check complete.");

        return Command::SUCCESS;
    }
}
