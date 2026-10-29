<?php

namespace Services\Analytics;

use Doctrine\DBAL\Connection;

/** Existing recalculation policy: oldest config ID, values from the newer config. */
class MetricConfigDeduplicator
{
    public static function mergeMetrics(Connection $conn, int $sourceId, int $targetId): void
    {
        $collisions = $conn->fetchAllAssociative(
            'SELECT old.id AS old_metric_id, fresh.id AS new_metric_id FROM metrics old JOIN metrics fresh
             ON old.metric_date = fresh.metric_date AND old.dimensions_hash = fresh.dimensions_hash
             WHERE old.metric_config_id = ? AND fresh.metric_config_id = ?', [$targetId, $sourceId]);
        foreach ($collisions as $collision) {
            $oldMetric = (int)$collision['old_metric_id'];
            $newMetric = (int)$collision['new_metric_id'];
            // Preserve source associations when replacing an observation. Drop
            // only links whose full destination identity already exists.
            $conn->executeStatement('DELETE FROM channeled_metrics WHERE metric_id = ? AND EXISTS (
                SELECT 1 FROM channeled_metrics keep WHERE keep.metric_id = ?
                AND keep.platform_id = channeled_metrics.platform_id AND keep.channel = channeled_metrics.channel
                AND keep.platform_created_at = channeled_metrics.platform_created_at)', [$oldMetric, $newMetric]);
            $conn->executeStatement('UPDATE channeled_metrics SET metric_id = ? WHERE metric_id = ?', [$newMetric, $oldMetric]);
            $conn->executeStatement('DELETE FROM metrics WHERE id = ?', [$oldMetric]);
        }
        $conn->executeStatement('UPDATE metrics SET metric_config_id = ? WHERE metric_config_id = ?', [$targetId, $sourceId]);
    }
}
