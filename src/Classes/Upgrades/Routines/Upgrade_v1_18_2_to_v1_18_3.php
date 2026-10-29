<?php

namespace Classes\Upgrades\Routines;

use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
use Classes\DimensionManager;
use Doctrine\ORM\EntityManagerInterface;
use Interfaces\UpgradeRoutineInterface;
use Services\Analytics\MetricConfigSignature;
use Services\Analytics\MetricConfigDeduplicator;
use Symfony\Component\Console\Output\OutputInterface;

/** Upgrade with sync workers stopped: dimension identities change together. */
class Upgrade_v1_18_2_to_v1_18_3 implements UpgradeRoutineInterface
{
    public function getFromVersions(): array { return ['1.18.2']; }
    public function getToVersion(): string { return '1.18.3'; }
    public function getDescription(): string { return 'Labels legacy GSC metrics as Web and rebuilds their dimension identities and configuration signatures.'; }
    public function requiresNuclearResync(): bool { return false; }

    public function up(EntityManagerInterface $em, OutputInterface $output): void
    {
        $conn = $em->getConnection();
        $channel = $conn->fetchOne("SELECT id FROM channels WHERE name = 'google_search_console'");
        if ($channel === false) return;
        $conn->beginTransaction();
        try {
            $output->writeln('<info>GSC Web backfill: waiting for metric-table locks...</info>');
            // Prevent parallel ingestion from inserting an old identity during the rewrite.
            $conn->executeStatement('LOCK TABLE metric_configs, metrics, channeled_metrics IN SHARE ROW EXCLUSIVE MODE');
            $manager = new DimensionManager($em);
            $dimensionMap = [];
            $conn->executeStatement('CREATE TEMP TABLE gsc_type_upgrade_map (config_id INTEGER PRIMARY KEY, config_signature VARCHAR(32), dimension_set_id INTEGER, dimensions_hash VARCHAR(32))');
            $afterId = 0;
            $updated = 0;
            $select = str_replace('ORDER BY mc.id ASC',
                'WHERE mc.channel = :channel AND mc.id > :afterId ORDER BY mc.id ASC LIMIT 500',
                MetricConfigSignature::selectSql());
            do {
                $rows = $conn->fetchAllAssociative($select, ['channel' => (int)$channel, 'afterId' => $afterId]);
                $batch = [];
                foreach ($rows as $row) {
                    $afterId = (int)$row['id'];
                    $oldSetId = (int)($row['dimension_set_id'] ?? 0);
                    if (!array_key_exists($oldSetId, $dimensionMap)) {
                        $dimensions = $conn->fetchAllAssociative(
                            'SELECT dk.name AS "dimensionKey", dv.value AS "dimensionValue"
                             FROM dimension_set_items dsi JOIN dimension_values dv ON dv.id = dsi.dimension_value_id
                             JOIN dimension_keys dk ON dk.id = dv.dimension_key_id
                             WHERE dsi.dimension_set_id = ? ORDER BY dk.name', [$row['dimension_set_id']]);
                        if (in_array('search_type', array_column($dimensions, 'dimensionKey'), true)) {
                            $dimensionMap[$oldSetId] = null;
                        } else {
                            $dimensions[] = ['dimensionKey' => 'search_type', 'dimensionValue' => 'web'];
                            KeyGenerator::sortDimensions($dimensions);
                            $hash = KeyGenerator::generateDimensionsHash($dimensions);
                            $set = $manager->resolveDimensionSet($dimensions);
                            $dimensionMap[$oldSetId] = [$set->getId(), $hash];
                        }
                    }
                    if ($dimensionMap[$oldSetId] === null) continue;
                    [$setId, $hash] = $dimensionMap[$oldSetId];
                    $row['dimension_set_hash'] = $hash;
                    $signature = MetricConfigSignature::fromRow($row);
                    $batch[] = [$afterId, $signature, $setId, $hash];
                    $updated++;
                }
                foreach (array_chunk($batch, 200) as $chunk) {
                    $conn->executeStatement('INSERT INTO gsc_type_upgrade_map VALUES '.implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?)')), array_merge(...$chunk));
                }
                if ($rows) $output->writeln("GSC Web backfill: staged {$updated} configurations (last ID {$afterId}).");
            } while (count($rows) === 500);
            // Session-local index: repeated collision lookups must not scan the
            // full staging table. Dropped automatically with this temporary table.
            $conn->executeStatement('CREATE INDEX gsc_type_upgrade_signature_idx ON gsc_type_upgrade_map (config_signature)');
            $conn->executeStatement('ANALYZE gsc_type_upgrade_map');
            // Include already typed targets so the same established deduplication
            // policy handles both legacy/legacy and legacy/typed collisions.
            $conn->executeStatement('INSERT INTO gsc_type_upgrade_map
                SELECT DISTINCT mc.id, map.config_signature, map.dimension_set_id, map.dimensions_hash
                FROM metric_configs mc JOIN gsc_type_upgrade_map map ON mc.config_signature = map.config_signature
                WHERE mc.channel = ? AND NOT EXISTS (SELECT 1 FROM gsc_type_upgrade_map present WHERE present.config_id = mc.id)', [(int)$channel]);
            $groups = $conn->fetchAllAssociative('SELECT config_signature, MIN(config_id) AS target_id FROM gsc_type_upgrade_map GROUP BY config_signature HAVING COUNT(*) > 1');
            $merged = 0;
            $output->writeln('GSC Web backfill: reconciling '.count($groups).' duplicate identity groups.');
            foreach ($groups as $group) {
                $members = $conn->fetchAllAssociative('SELECT * FROM gsc_type_upgrade_map WHERE config_signature = ? ORDER BY config_id', [$group['config_signature']]);
                $ids = array_map('intval', array_column($members, 'config_id'));
                // Both sides must have their final dimension hash before comparing
                // observations; equal dates alone are insufficient for deduplication.
                $conn->executeStatement('UPDATE metrics SET dimensions_hash = ? WHERE metric_config_id IN (?)', [$members[0]['dimensions_hash'], $ids], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::INTEGER]);
                $targetId = (int)$group['target_id'];
                foreach ($ids as $sourceId) {
                    if ($sourceId === $targetId) continue;
                    MetricConfigDeduplicator::mergeMetrics($conn, $sourceId, $targetId);
                    $conn->executeStatement('DELETE FROM metric_configs WHERE id = ?', [$sourceId]);
                    $conn->executeStatement('DELETE FROM gsc_type_upgrade_map WHERE config_id = ?', [$sourceId]);
                    $merged++;
                }
                if ($merged % 100 === 0) $output->writeln("GSC Web backfill: merged {$merged} duplicate configurations.");
            }
            $output->writeln('<info>GSC Web backfill: updating configuration signatures...</info>');
            $conn->executeStatement('UPDATE metric_configs AS mc SET dimension_set_id = map.dimension_set_id, config_signature = map.config_signature FROM gsc_type_upgrade_map map WHERE mc.id = map.config_id');
            $output->writeln('<info>GSC Web backfill: updating metric hashes...</info>');
            $conn->executeStatement('UPDATE metrics AS m SET dimensions_hash = map.dimensions_hash FROM gsc_type_upgrade_map map WHERE m.metric_config_id = map.config_id');
            $output->writeln('<info>GSC Web backfill: updating channeled dimension links...</info>');
            $conn->executeStatement('UPDATE channeled_metrics AS cm SET dimension_set_id = map.dimension_set_id FROM metrics m JOIN gsc_type_upgrade_map map ON m.metric_config_id = map.config_id WHERE cm.metric_id = m.id');
            $conn->executeStatement('DROP TABLE gsc_type_upgrade_map');
            $conn->commit();
            $output->writeln("<info>Added search_type=web to {$updated} GSC configurations; merged {$merged} duplicates without summing observations; signatures and metric hashes updated.</info>");
        } catch (\Throwable $error) {
            $conn->rollBack();
            throw $error;
        }
    }

    public function down(EntityManagerInterface $em, OutputInterface $output): void
    {
        throw new \RuntimeException('Search-type identity migration is forward-only; restore the pre-upgrade database backup to downgrade.');
    }
}
