<?php

namespace Classes\Upgrades\Routines;

use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
use Classes\DimensionManager;
use Doctrine\ORM\EntityManagerInterface;
use Interfaces\UpgradeRoutineInterface;
use Services\Analytics\MetricConfigSignature;
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
            // Prevent parallel ingestion from inserting an old identity during the rewrite.
            $conn->executeStatement('LOCK TABLE metric_configs, metrics, channeled_metrics IN SHARE ROW EXCLUSIVE MODE');
            $manager = new DimensionManager($em);
            $afterId = 0;
            $updated = 0;
            $select = str_replace('ORDER BY mc.id ASC',
                'WHERE mc.channel = :channel AND mc.id > :afterId ORDER BY mc.id ASC LIMIT 500',
                MetricConfigSignature::selectSql());
            do {
                $rows = $conn->fetchAllAssociative($select, ['channel' => (int)$channel, 'afterId' => $afterId]);
                foreach ($rows as $row) {
                    $afterId = (int)$row['id'];
                    $dimensions = $conn->fetchAllAssociative(
                        'SELECT dk.name AS "dimensionKey", dv.value AS "dimensionValue"
                         FROM dimension_set_items dsi JOIN dimension_values dv ON dv.id = dsi.dimension_value_id
                         JOIN dimension_keys dk ON dk.id = dv.dimension_key_id
                         WHERE dsi.dimension_set_id = ? ORDER BY dk.name', [$row['dimension_set_id']]);
                    if (in_array('search_type', array_column($dimensions, 'dimensionKey'), true)) continue;
                    $dimensions[] = ['dimensionKey' => 'search_type', 'dimensionValue' => 'web'];
                    KeyGenerator::sortDimensions($dimensions);
                    $hash = KeyGenerator::generateDimensionsHash($dimensions);
                    $set = $manager->resolveDimensionSet($dimensions);
                    $row['dimension_set_hash'] = $hash;
                    $signature = MetricConfigSignature::fromRow($row);
                    $conflict = $conn->fetchOne('SELECT id FROM metric_configs WHERE config_signature = ? AND id <> ?', [$signature, $afterId]);
                    if ($conflict !== false) {
                        // Never guess which observation wins, nor sum duplicate cached values.
                        throw new \RuntimeException("GSC config {$afterId} collides with typed config {$conflict}; reconcile duplicates before retrying the upgrade.");
                    }
                    $conn->executeStatement('UPDATE metric_configs SET dimension_set_id = ?, config_signature = ? WHERE id = ?', [$set->getId(), $signature, $afterId]);
                    $conn->executeStatement('UPDATE metrics SET dimensions_hash = ? WHERE metric_config_id = ?', [$hash, $afterId]);
                    $conn->executeStatement('UPDATE channeled_metrics AS cm SET dimension_set_id = ? FROM metrics m WHERE cm.metric_id = m.id AND m.metric_config_id = ?', [$set->getId(), $afterId]);
                    $updated++;
                }
            } while (count($rows) === 500);
            $conn->commit();
            $output->writeln("<info>Added search_type=web to {$updated} GSC configurations; signatures and metric hashes updated.</info>");
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
