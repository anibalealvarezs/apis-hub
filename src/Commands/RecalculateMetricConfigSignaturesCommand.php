<?php

namespace Commands;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:recalculate-metric-config-signatures',
    description: 'Recalculates config_signature for all MetricConfigs using the current KeyGenerator. Safe to re-run anytime.'
)]
class RecalculateMetricConfigSignaturesCommand extends Command
{
    private EntityManagerInterface $entityManager;

    private const DEFAULT_BATCH_SIZE = 10000;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows per batch', self::DEFAULT_BATCH_SIZE)
            ->addOption('from-id', null, InputOption::VALUE_REQUIRED, 'Start ID (inclusive)')
            ->addOption('to-id', null, InputOption::VALUE_REQUIRED, 'End ID (inclusive)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Update every row, even if signature matches');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = (int) $input->getOption('batch-size');
        $fromId = $input->getOption('from-id') ? (int) $input->getOption('from-id') : null;
        $toId = $input->getOption('to-id') ? (int) $input->getOption('to-id') : null;
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        $conn = $this->entityManager->getConnection();

        // Determine total row count
        $countSql = 'SELECT COUNT(*) AS total FROM metric_configs';
        $countParams = [];
        if ($fromId !== null) {
            $countSql .= ' WHERE id >= :fromId';
            $countParams['fromId'] = $fromId;
        }
        if ($fromId !== null && $toId !== null) {
            $countSql .= ' AND id <= :toId';
            $countParams['toId'] = $toId;
        } elseif ($toId !== null) {
            $countSql .= ' WHERE id <= :toId';
            $countParams['toId'] = $toId;
        }

        $total = (int) $conn->fetchOne($countSql, $countParams);

        if ($total === 0) {
            $output->writeln('<info>No metric configs found.</info>');
            return Command::SUCCESS;
        }

        $rangeInfo = '';
        if ($fromId !== null || $toId !== null) {
            $rangeInfo = " (ID range filter applied)";
        }

        $output->writeln(sprintf(
            '<info>Recalculating signatures for %d metric configs%s</info>',
            $total, $rangeInfo
        ));

        if ($dryRun) {
            $output->writeln('<comment>Dry-run mode: no changes will be written.</comment>');
        }
        if ($force) {
            $output->writeln('<comment>Force mode: all rows will be updated regardless of current signature.</comment>');
        }

        $selectSql = $this->buildSelectSql();

        $progressBar = new ProgressBar($output, $total);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $progressBar->start();

        $updatedCount = 0;
        $batchesProcessed = 0;
        $offset = 0;

        while (true) {
            $rows = $conn->fetchAllAssociative(
                $selectSql . ' OFFSET ' . (int) $offset . ' LIMIT ' . (int) $batchSize,
                []
            );

            if (empty($rows)) {
                break;
            }

            $updates = [];
            $deletes = [];
            $merges = [];
            $targetIdsBySignature = [];

            // 1. Calculate new signatures
            $newSignaturesMap = [];
            $uniqueNewSigs = [];
            foreach ($rows as $row) {
                $sig = $this->generateSignatureFromRow($row);
                $id = (int) $row['id'];
                $newSignaturesMap[$id] = $sig;
                $uniqueNewSigs[$sig] = true;
            }
            $uniqueNewSigs = array_keys($uniqueNewSigs);

            // 2. Fetch existing targets for these signatures from DB
            if (!empty($uniqueNewSigs)) {
                $existingTargets = $conn->fetchAllAssociative(
                    'SELECT id, config_signature FROM metric_configs WHERE config_signature IN (?)',
                    [$uniqueNewSigs],
                    [\Doctrine\DBAL\ArrayParameterType::STRING]
                );
                foreach ($existingTargets as $et) {
                    $targetIdsBySignature[$et['config_signature']] = (int) $et['id'];
                }
            }

            // 3. Process each row
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $newSignature = $newSignaturesMap[$id];
                
                if (isset($targetIdsBySignature[$newSignature])) {
                    $existingId = $targetIdsBySignature[$newSignature];
                    if ($existingId !== $id) {
                        // Collision! Preserve the oldest ID
                        $targetId = min($existingId, $id);
                        $duplicateId = max($existingId, $id);
                        
                        $merges[$duplicateId] = $targetId;
                        $deletes[] = $duplicateId;
                        
                        // Update the map so subsequent rows point to the oldest ID
                        $targetIdsBySignature[$newSignature] = $targetId;
                        
                        // If the current row became the target, it still needs its signature updated
                        if ($targetId === $id) {
                            $updates[] = [
                                'id' => $id,
                                'signature' => $newSignature,
                            ];
                        }
                    } else {
                        // This row IS the target already
                        if ($force && $newSignature === $row['config_signature']) {
                            $updates[] = [
                                'id' => $id,
                                'signature' => $newSignature,
                            ];
                        }
                    }
                } else {
                    // Claim this signature
                    $targetIdsBySignature[$newSignature] = $id;
                    if ($force || $newSignature !== $row['config_signature']) {
                        $updates[] = [
                            'id' => $id,
                            'signature' => $newSignature,
                        ];
                    }
                }
            }

            if (!$dryRun) {
                // Execute deduplication merges
                foreach ($merges as $oldId => $targetId) {
                    // a) Delete colliding metrics from the TARGET row (Historical) that already exist in the DUPLICATE row (New)
                    // This ensures we keep the freshest synced data from the newly duplicated configs.
                    $conn->executeStatement('
                        DELETE FROM metrics m1
                        USING metrics m2
                        WHERE m1.metric_config_id = ? 
                          AND m2.metric_config_id = ?
                          AND m1.metric_date = m2.metric_date 
                          AND m1.dimensions_hash = m2.dimensions_hash
                    ', [$targetId, $oldId]);

                    // b) Migrate remaining non-colliding historical metrics to Target
                    $conn->executeStatement('
                        UPDATE metrics SET metric_config_id = ? WHERE metric_config_id = ?
                    ', [$targetId, $oldId]);
                }

                // Delete duplicate configs
                if (!empty($deletes)) {
                    $conn->executeStatement(
                        'DELETE FROM metric_configs WHERE id IN (?)',
                        [$deletes],
                        [\Doctrine\DBAL\ArrayParameterType::INTEGER]
                    );
                }

                // Update signatures of non-duplicate configs
                if (!empty($updates)) {
                    $this->batchUpdateSignatures($conn, $updates);
                }
            }

            $updatedCount += count($updates);
            $batchesProcessed++;
            $progressBar->advance(count($rows));

            $offset += count($rows);

            if ($batchesProcessed % 10 === 0) {
                $this->entityManager->clear();
                gc_collect_cycles();
            }
        }

        $progressBar->finish();
        $output->writeln('');

        if ($dryRun) {
            $output->writeln("<info>Dry-run complete. $updatedCount configs would be updated.</info>");
        } else {
            $output->writeln("<info>Done. $updatedCount config signatures updated.</info>");
        }

        return Command::SUCCESS;
    }

    private function buildSelectSql(): string
    {
        return \Services\Analytics\MetricConfigSignature::selectSql();
    }

    private function generateSignatureFromRow(array $row): string
    {
        return \Services\Analytics\MetricConfigSignature::fromRow($row);
    }

    private function batchUpdateSignatures(\Doctrine\DBAL\Connection $conn, array $updates): void
    {
        $cases = [];
        $ids = [];
        $params = [];
        $i = 0;
        foreach ($updates as $update) {
            $sigParam = "sig_{$i}";
            $idParam = "id_{$i}";
            $cases[] = "WHEN :{$idParam} THEN :{$sigParam}";
            $params[$sigParam] = $update['signature'];
            $params[$idParam] = $update['id'];
            $ids[] = $update['id'];
            $i++;
        }
        $idPlaceholders = [];
        foreach ($updates as $i => $update) {
            $idPlaceholders[] = ":id_{$i}";
        }
        $conn->executeStatement(
            'UPDATE metric_configs SET config_signature = CASE id ' . implode(' ', $cases)
            . ' END WHERE id IN (' . implode(',', $idPlaceholders) . ')',
            $params
        );
    }
}
