<?php

namespace Commands;

use Doctrine\ORM\EntityManagerInterface;
use Services\Sync\QueryClassificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:classify-queries',
    description: 'Classifies Search Console queries semantically using TypeSafe AI (Intent, Brand, Relevance).'
)]
class ClassifyQueriesCommand extends Command
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
    }

    protected function configure(): void
    {
        $this
            ->addOption('on-upgrade', null, InputOption::VALUE_NONE, 'Non-blocking execution for automated tenant version upgrades')
            ->addOption('asset', 'a', InputOption::VALUE_OPTIONAL, 'Filter by specific channeled_account_id')
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Batch size per classification cycle', 100)
            ->addOption('min-impressions', 'm', InputOption::VALUE_OPTIONAL, 'Minimum impressions threshold', 1);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $onUpgrade = (bool) $input->getOption('on-upgrade');
        $assetFilter = $input->getOption('asset');
        $batchSize = (int) $input->getOption('batch-size');
        $minImpressions = (int) $input->getOption('min-impressions');

        $service = new QueryClassificationService();

        // Non-blocking guard for automated tenant releases
        if (!$service->isAvailable()) {
            if ($onUpgrade) {
                $output->writeln("<comment>[SKIP] AI Classification skipped: No active TypeSafe API Key found.</comment>");
                return Command::SUCCESS;
            }

            $output->writeln("<error>[ERROR] TypeSafe API Key is not configured (TYPESAFE_API_KEY).</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>--- Starting TypeSafe AI Semantic Query Classification ---</info>");

        $conn = $this->entityManager->getConnection();

        // Retrieve active GSC assets from metric_configs
        $sql = "SELECT DISTINCT channeled_account_id FROM metric_configs WHERE channeled_account_id IS NOT NULL";
        if (!empty($assetFilter)) {
            $sql .= " AND channeled_account_id = " . ((int) $assetFilter);
        }

        $assets = $conn->fetchFirstColumn($sql);

        if (empty($assets)) {
            $output->writeln("<comment>No Search Console assets found with query metrics.</comment>");
            return Command::SUCCESS;
        }

        $totalClassified = 0;
        foreach ($assets as $assetId) {
            $assetId = (int) $assetId;
            $output->writeln("<info>Processing asset #{$assetId}...</info>");

            $result = $service->classifyForAsset(
                channeledAccountId: $assetId,
                batchSize: $batchSize,
                minImpressions: $minImpressions
            );

            $classifiedCount = $result['classified'] ?? 0;
            $totalClassified += $classifiedCount;

            $output->writeln("  - Candidates: " . ($result['candidates'] ?? 0) . " | Classified: {$classifiedCount}");
        }

        $output->writeln("<info>Classification completed. Total classified queries: {$totalClassified}</info>");

        return Command::SUCCESS;
    }
}