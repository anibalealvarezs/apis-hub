<?php

namespace Commands\Analytics;

use Doctrine\ORM\EntityManagerInterface;
use Services\Analytics\ScopedReportService;
use Services\Analytics\ScopedReportStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:process-scoped-reports', description: 'Process a bounded fair batch of cached source reports without blocking dashboard reads.')]
final class ProcessScopedReportsCommand extends Command
{
    public function __construct(private EntityManagerInterface $manager) { parent::__construct(); }
    protected function configure(): void { $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum jobs (1–10)', 3); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = new ScopedReportStore(dirname(__DIR__, 3).'/storage/analytics-reports');
        $completed = (new ScopedReportService($store, $this->manager->getConnection()))->process((int)$input->getOption('limit'));
        $output->writeln('Processed '.count($completed).' scoped report requests.');
        return Command::SUCCESS;
    }
}
