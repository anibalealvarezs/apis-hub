<?php

namespace Commands\Analytics;

use Helpers\Helpers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Enums\JobStatus;
use Enums\Channel;
use Throwable;

#[AsCommand(
    name: 'app:jobs-stats',
    description: 'Displays statistics about the current job queue'
)]
class InspectJobsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("📋 <info>Job Queue Statistics</info>\n");

        try {
            $em = Helpers::getManager();
            $conn = $em->getConnection();

            // Total by Status
            $sqlStatus = "SELECT status, COUNT(*) as count FROM jobs GROUP BY status";
            $results = $conn->fetchAllAssociative($sqlStatus);

            $output->writeln("<comment>By Status:</comment>");
            if (empty($results)) {
                $output->writeln("  - No jobs found in database.");
            } else {
                foreach ($results as $row) {
                    $statusLabel = "Unknown ({$row['status']})";
                    foreach (JobStatus::cases() as $case) {
                        if ($case->value == $row['status']) {
                            $statusLabel = $case->name;
                            break;
                        }
                    }
                    $color = $this->getStatusColor($statusLabel);
                    $output->writeln("  - <$color>$statusLabel</$color>: {$row['count']}");
                }
            }

            // Failed jobs by Channel (Last 24h)
            $sqlFailed = "SELECT channel, COUNT(*) as count FROM jobs 
                          WHERE status = :status 
                          AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                          GROUP BY channel";
            $failedResults = $conn->fetchAllAssociative($sqlFailed, ['status' => JobStatus::failed->value]);

            if (!empty($failedResults)) {
                $output->writeln("\n<error>Failed Jobs (Last 24h) by Channel:</error>");
                foreach ($failedResults as $row) {
                    $channel = Channel::tryFromName($row['channel'])?->getCommonName() ?? $row['channel'];
                    $output->writeln("  - $channel: {$row['count']}");
                }
            }

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }

    private function getStatusColor(string $status): string
    {
        return match (strtolower($status)) {
            'completed' => 'info',
            'failed' => 'error',
            'scheduled' => 'comment',
            'delayed' => 'comment',
            'processing' => 'info',
            default => 'default',
        };
    }
}
