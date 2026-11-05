<?php

declare(strict_types=1);

namespace Services\Analytics;

use Doctrine\DBAL\Connection;

/** Configuration identity follows the fields actually persisted by sync. */
final class MetricConfigIdentity
{
    private const RELATIONS = [
        'account_id' => ['accounts', 'name', 'account_name'],
        'channeled_account_id' => ['channeled_accounts', 'platform_id', 'channeled_account_platform_id'],
        'campaign_id' => ['campaigns', 'campaign_id', 'campaign_campaign_id'],
        'channeled_campaign_id' => ['channeled_campaigns', 'platform_id', 'channeled_campaign_platform_id'],
        'channeled_ad_group_id' => ['channeled_ad_groups', 'platform_id', 'channeled_ad_group_platform_id'],
        'channeled_ad_id' => ['channeled_ads', 'platform_id', 'channeled_ad_platform_id'],
        'creative_id' => ['creatives', 'creative_id', 'creative_creative_id'],
        'page_id' => ['pages', 'url', 'page_url'],
        'query_id' => ['queries', 'query', 'query_query'],
        'post_id' => ['posts', 'post_id', 'post_post_id'],
        'product_id' => ['products', 'product_id', 'product_product_id'],
        'customer_id' => ['customers', 'email', 'customer_email'],
        'order_id' => ['orders', 'order_id', 'order_order_id'],
        'country_id' => ['countries', 'code', 'country_code'],
        'device_id' => ['devices', 'type', 'device_type'],
        'dimension_set_id' => ['dimension_sets', 'hash', 'dimension_set_hash'],
        'location_id' => ['locations', 'platform_id', 'location_platform_id'],
        'state_id' => ['states', 'name', 'state_name'],
        'city_id' => ['cities', 'name', 'city_name'],
        'event_id' => ['events', 'name', 'event_name'],
        'channeled_event_id' => ['channeled_events', 'platform_id', 'channeled_event_platform_id'],
    ];

    public const COLUMNS = [
        'channel', 'name', 'period', 'account_id', 'channeled_account_id',
        'campaign_id', 'channeled_campaign_id', 'channeled_ad_group_id',
        'channeled_ad_id', 'creative_id', 'query_id', 'page_id', 'post_id',
        'product_id', 'customer_id', 'order_id', 'location_id', 'state_id',
        'city_id', 'country_id', 'device_id', 'dimension_set_id',
        'event_id', 'channeled_event_id',
    ];

    public static function key(array $config): string
    {
        $identity = ['version' => 2];
        foreach (self::COLUMNS as $column) {
            $value = $config[$column] ?? null;
            $identity[$column] = $value === null ? null : (string) $value;
        }
        return md5(json_encode($identity, JSON_THROW_ON_ERROR));
    }

    /** Use the same persisted values and signature format as reconciliation. */
    public static function signatures(Connection $connection, array $configs): array
    {
        $values = [];
        foreach (self::RELATIONS as $column => [$table, $field, $alias]) {
            $ids = array_values(array_unique(array_filter(array_column($configs, $column))));
            $values[$column] = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                $sql = "SELECT id, $field AS identity_value FROM $table WHERE id IN (".implode(', ', array_fill(0, count($chunk), '?')).')';
                foreach ($connection->fetchAllAssociative($sql, $chunk) as $row) {
                    $values[$column][$row['id']] = $row['identity_value'];
                }
            }
        }
        $signatures = [];
        foreach ($configs as $key => $config) {
            $row = $config;
            foreach (self::RELATIONS as $column => [$table, $field, $alias]) {
                $row[$alias] = $values[$column][$config[$column] ?? ''] ?? null;
            }
            $signatures[$key] = MetricConfigSignature::fromRow($row);
        }
        return $signatures;
    }

    /** Reuse legacy signatures without rewriting observations during ingestion. */
    public static function findExisting(Connection $connection, array $configs): array
    {
        $matches = [];
        $incomingKeys = [];
        foreach ($configs as $key => $config) {
            $incomingKeys[self::key($config)] = $key;
        }
        // Bound parameter counts even on SQLite builds with a 999-variable limit.
        foreach (array_chunk($configs, 25, true) as $chunk) {
            $rows = [];
            $params = [];
            foreach ($chunk as $config) {
                $conditions = [];
                foreach (self::COLUMNS as $column) {
                    $value = $config[$column] ?? null;
                    if ($value === null) {
                        $conditions[] = "$column IS NULL";
                    } else {
                        $conditions[] = "$column = ?";
                        $params[] = $value;
                    }
                }
                $rows[] = '('.implode(' AND ', $conditions).')';
            }
            $sql = 'SELECT id, '.implode(', ', self::COLUMNS).' FROM metric_configs WHERE '.implode(' OR ', $rows).' ORDER BY id ASC';
            foreach ($connection->fetchAllAssociative($sql, $params) as $row) {
                $key = $incomingKeys[self::key($row)];
                $matches[$key] ??= (int) $row['id'];
            }
        }
        return $matches;
    }
}
