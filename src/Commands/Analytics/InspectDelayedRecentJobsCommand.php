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
    description: 'Inspects -recent jobs (any status) and checks their entity-sync dependencies'
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
            ->setHelp('Lists jobs whose instance_name ends in -recent (defaults to facebook_marketing and facebook_organic), grouped by status with full detail, and verifies whether their "requires" dependency has a successful job.')
            ->addOption('channel', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict to specific channels (repeatable). Defaults to facebook_marketing and facebook_organic.')
            ->addOption('status', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict to specific statuses by name or int (repeatable). Defaults to all.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $statusNames = [];
        foreach (JobStatus::cases() as $case) {
            $statusNames[$case->value] = $case->name;
        }
        $statusByName = [];
        foreach (JobStatus::cases() as $case) {
            $statusByName[strtolower($case->name)] = $case->value;
        }

        $channels = $input->getOption('channel') ?: ['facebook_marketing', 'facebook_organic'];

        $statusFilter = null;
        $rawStatuses = $input->getOption('status');
        if (! empty($rawStatuses)) {
            $statusFilter = [];
            foreach ($rawStatuses as $rawStatus) {
                if (is_numeric($rawStatus)) {
                    $statusFilter[] = (int) $rawStatus;
                } elseif (isset($statusByName[strtolower($rawStatus)])) {
                    $statusFilter[] = $statusByName[strtolower($rawStatus)];
                } else {
                    $output->writeln('<error>Invalid status: ' . $rawStatus . '</error>');

                    return Command::FAILURE;
                }
            }
        }

        try {
            /** @var \Repositories\JobRepository $jobRepo */
            $jobRepo = $this->em->getRepository(Job::class);
            $conn = $this->em->getConnection();

            // 1. Delayed jobs grouped by channel (overview)
            $output->writeln('=== DELAYED JOBS BY CHANNEL (overview) ===');
            $rows = $conn->fetchAllAssociative(
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

            // 2. All -recent jobs for the channels, grouped by status
            $output->writeln('');
            $output->writeln('=== \'-recent\' JOBS [' . implode(', ', $channels) . '] BY STATUS ===');

            $sql = "SELECT j.status, COUNT(*) AS cnt FROM jobs j
                    WHERE j.channel IN (:channels)
                    AND CAST(j.payload AS TEXT) LIKE '%instance_name%' AND CAST(j.payload AS TEXT) LIKE '%-recent%'
                    GROUP BY j.status ORDER BY j.status";
            $statusRows = $conn->fetchAllAssociative($sql, ['channels' => $channels], ['channels' => \Doctrine\DBAL\ArrayParameterType::STRING]);
            $statusCounts = [];
            foreach ($statusRows as $row) {
                $statusCounts[(int) $row['status']] = (int) $row['cnt'];
            }
            if (empty($statusCounts)) {
                $output->writeln('  (NO -recent jobs found for these channels)');
            } else {
                foreach (JobStatus::cases() as $case) {
                    $cnt = $statusCounts[$case->value] ?? 0;
                    $output->writeln(sprintf('  %-12s %d', $case->name, $cnt));
                }
            }

            // 3. Detail per -recent job
            $output->writeln('');
            $output->writeln('=== \'-recent\' JOBS DETAIL ===');

            $recentJobs = array_values(array_filter(
                $jobRepo->findBy(
                    ['channel' => $channels],
                    ['updatedAt' => 'DESC']
                ),
                function (Job $job) use ($statusFilter) {
                    $payload = $job->getPayload() ?? [];
                    $instance = $payload['instance_name'] ?? null;
                    $isRecent = $instance && str_ends_with((string) $instance, '-recent');
                    if (! $isRecent) {
                        return false;
                    }

                    return $statusFilter === null || in_array($job->getStatus(), $statusFilter, true);
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

            // 4. Last completed per recent instance
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

            // 5. Latest job per entities-sync instance (the dependency targets)
            $output->writeln('');
            $output->writeln('=== LATEST JOB PER \'-entities-sync\' INSTANCE (dependencies) ===');
            foreach ($channels as $channel) {
                $syncName = str_replace('_', '-', $channel) . '-entities-sync';
                $sql = "SELECT j.status, j.message, j.updated_at FROM jobs j
                        WHERE CAST(j.payload AS TEXT) LIKE :name_pattern
                        ORDER BY j.updated_at DESC LIMIT 1";
                $syncJob = $conn->fetchAssociative($sql, ['name_pattern' => '%instance_name%' . $syncName . '%']);
                if ($syncJob) {
                    $output->writeln(sprintf(
                        '  %s: %s | updated: %s | %s',
                        $syncName,
                        $statusNames[(int) $syncJob['status']] ?? $syncJob['status'],
                        $syncJob['updated_at'],
                        ($syncJob['message'] ?? '(no message)')
                    ));
                } else {
                    $output->writeln('  ' . $syncName . ': NO JOBS EVER CREATED');
                }
            }

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }
}
