<?php

namespace Commands\Analytics;

use Doctrine\ORM\EntityManager;
use Entities\Job;
use Enums\JobStatus;
use Helpers\Helpers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'app:inspect-delayed-recent',
    description: 'Inspects delayed -recent jobs and checks their entity-sync dependencies'
)]
class InspectDelayedRecentJobsCommand extends Command
{
    private EntityManager $em;

    public function __construct(?EntityManager $em = null)
    {
        $this->em = $em ?? Helpers::getManager();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Lists delayed jobs whose instance_name ends in -recent (defaults to facebook_marketing and facebook_organic), prints their params, and verifies whether their "requires" dependency has a successful job.')
            ->addOption('channel', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict to specific channels (repeatable). Defaults to facebook_marketing and facebook_organic.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $statusNames = [];
        foreach (JobStatus::cases() as $case) {
            $statusNames[$case->value] = $case->name;
        }

        $channels = $input->getOption('channel') ?: ['facebook_marketing', 'facebook_organic'];

        try {
            // 1. Delayed jobs grouped by channel (overview)
            $output->writeln('=== DELAYED JOBS BY CHANNEL ===');
            $rows = $this->em->getConnection()->fetchAllAssociative(
                'SELECT channel, COUNT(*) AS cnt FROM jobs WHERE status = :status GROUP BY channel ORDER BY cnt DESC',
                ['status' => JobStatus::delayed->value]
            );
            if (empty($rows)) {
                $output->writeln('  (none)');
            } else {
                foreach ($rows as $row) {
                    $output->writeln(sprintf('  %s: %d', $row['channel'], $row['cnt']));
                }
            }

            // 2. Delayed -recent jobs detail
            $output->writeln('');
            $output->writeln('=== DELAYED \'-recent\' JOBS [' . implode(', ', $channels) . '] ===');

            /** @var \Repositories\JobRepository $jobRepo */
            $jobRepo = $this->em->getRepository(Job::class);

            $recentJobs = array_values(array_filter(
                $jobRepo->findBy(
                    ['status' => JobStatus::delayed->value, 'channel' => $channels],
                    ['updatedAt' => 'DESC']
                ),
                function (Job $job) {
                    $payload = $job->getPayload() ?? [];
                    $instance = $payload['instance_name'] ?? null;

                    return $instance && str_ends_with((string) $instance, '-recent');
                }
            ));

            if (empty($recentJobs)) {
                $output->writeln('  (none found)');
            }

            foreach ($recentJobs as $job) {
                $payload = $job->getPayload() ?? [];
                $params = $payload['params'] ?? [];
                $instance = $payload['instance_name'] ?? null;
                $requires = $params['requires'] ?? null;

                $output->writeln(str_repeat('-', 59));
                $output->writeln('id:        ' . $job->getId());
                $output->writeln('uuid:      ' . $job->getUuid());
                $output->writeln('channel:   ' . $job->getChannel());
                $output->writeln('entity:    ' . $job->getEntity());
                $output->writeln('status:    ' . ($statusNames[$job->getStatus()] ?? $job->getStatus()));
                $output->writeln('worker_id: ' . ($job->getWorkerId() ?? '(none)'));
                $output->writeln('created:   ' . ($job->getCreatedAt()?->format('Y-m-d H:i:s') ?? '(null)'));
                $output->writeln('updated:   ' . ($job->getUpdatedAt()?->format('Y-m-d H:i:s') ?? '(null)'));
                $output->writeln('instance:  ' . $instance);
                $output->writeln('message:   ' . ($job->getMessage() ?? '(none)'));

                if ($requires) {
                    $accountId = $params['account_id'] ?? null;
                    $met = $jobRepo->hasSuccessfulRecentJob($requires, 87600, $accountId);
                    $output->writeln('requires:  ' . $requires . ' => ' . ($met ? 'MET (has successful job)' : 'NOT MET (no successful job within 87600h)'));
                }

                $output->writeln('params:    ' . json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $output->writeln(str_repeat('-', 59));
            }

            // 3. Last completed per recent instance
            $output->writeln('');
            $output->writeln('=== LATEST COMPLETED JOB PER RECENT INSTANCE ===');
            $instances = array_unique(array_values(array_map(function (Job $job) {
                return $job->getPayload()['instance_name'] ?? null;
            }, $recentJobs)));
            foreach ($instances as $instance) {
                if (! $instance) {
                    continue;
                }
                $last = $jobRepo->getLastSuccessfulJobTime($instance);
                $output->writeln('  ' . $instance . ': ' . ($last?->format('Y-m-d H:i:s') ?? 'NEVER COMPLETED'));
            }

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }
}
