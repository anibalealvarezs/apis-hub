<?php

namespace Commands\Analytics;

use Helpers\Helpers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Enums\Channel;
use Throwable;

#[AsCommand(
    name: 'app:check-coverage',
    description: 'Checks for data gaps in metrics for a specific channel and time range'
)]
class CheckCoverageCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('channel', 'c', InputOption::VALUE_REQUIRED, 'The channel name (e.g. gsc, facebook_marketing)')
             ->addOption('days', 'd', InputOption::VALUE_OPTIONAL, 'Number of days to look back', 30);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channelName = $input->getOption('channel');
        $days = (int) $input->getOption('days');

        if (!$channelName) {
            $output->writeln("<error>Error: --channel option is required.</error>");
            return Command::FAILURE;
        }

        $channel = Channel::tryFromName($channelName);
        if (!$channel) {
            $output->writeln("<error>Error: Invalid channel name '$channelName'.</error>");
            return Command::FAILURE;
        }

        $output->writeln("📊 <info>Analyzing coverage for: {$channel->getCommonName()}</info>");
        $output->writeln("📅 <info>Target Window: Last $days days</info>\n");

        try {
            $em = Helpers::getManager();
            $conn = $em->getConnection();

            // SQL to find dates present in the channeled_metrics table for the specific channel
            $sql = "SELECT DISTINCT DATE(platformCreatedAt) as date 
                    FROM channeled_metrics 
                    WHERE channel = :channel 
                    AND platformCreatedAt >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                    ORDER BY date DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue('channel', $channel->value);
            $stmt->bindValue('days', $days);
            $result = $stmt->executeQuery();
            $datesFound = $result->fetchFirstColumn();

            // Generate expected dates
            $expectedDates = [];
            for ($i = 0; $i < $days; $i++) {
                $expectedDates[] = (new \DateTime())->modify("-$i days")->format('Y-m-d');
            }

            $missingDates = array_diff($expectedDates, $datesFound);
            
            // Exclude today since it might be in progress
            $today = (new \DateTime())->format('Y-m-d');
            $missingDates = array_values(array_filter($missingDates, fn($d) => $d !== $today));

            if (empty($missingDates)) {
                $output->writeln("✅ <info>No gaps found! 100% coverage in the requested window.</info>");
            } else {
                $output->writeln("⚠️  <comment>Gaps found! Missing " . count($missingDates) . " days:</comment>");
                foreach ($missingDates as $date) {
                    $output->writeln("   - $date");
                }
                $coverage = round((($days - count($missingDates)) / $days) * 100, 2);
                $output->writeln("\n📈 <comment>Total Coverage: $coverage%</comment>");
            }

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }
}
