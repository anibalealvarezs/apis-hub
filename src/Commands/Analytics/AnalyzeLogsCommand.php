<?php

namespace Commands\Analytics;

use Helpers\Helpers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

#[AsCommand(
    name: 'app:analyze-errors',
    description: 'Scans log files for recent errors and critical failures'
)]
class AnalyzeLogsCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit of errors to show per file', '5')
             ->addOption('hours', 't', InputOption::VALUE_OPTIONAL, 'Scan logs from the last X hours', '24');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        $hours = (int) $input->getOption('hours');
        $logDir = __DIR__ . '/../../../logs';

        $output->writeln("🔍 <info>Analyzing logs for errors (Last $hours hours)</info>\n");

        if (!is_dir($logDir)) {
            $output->writeln("<error>Error: Logs directory not found at $logDir</error>");
            return Command::FAILURE;
        }

        $files = glob($logDir . '/*.log');
        if (empty($files)) {
            $output->writeln("  - No log files found.");
            return Command::SUCCESS;
        }

        $foundAny = false;
        foreach ($files as $file) {
            $filename = basename($file);
            
            // Basic date filter (if log entries have timestamps)
            // For now we just read the last lines and look for "error" or "critical"
            $content = file($file);
            $errors = [];
            
            foreach (array_reverse($content) as $line) {
                if (stripos($line, 'error') !== false || stripos($line, 'critical') !== false || stripos($line, 'fatal') !== false) {
                    $errors[] = trim($line);
                }
                if (count($errors) >= $limit) break;
            }

            if (!empty($errors)) {
                $foundAny = true;
                $output->writeln("<comment>[$filename]</comment>");
                foreach ($errors as $error) {
                    $output->writeln("  - $error");
                }
                $output->writeln("");
            }
        }

        if (!$foundAny) {
            $output->writeln("✅ <info>No errors found in the scanned log files.</info>");
        }

        return Command::SUCCESS;
    }
}
