<?php

declare(strict_types=1);

namespace Services\Aggregation;

use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
use Anibalealvarezs\ApiDriverCore\Interfaces\PreAggregationProviderInterface;
use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Exception;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;

/**
 * AgnosticPreAggregationEngine
 *
 * Implements the asynchronous, idempotent batch processing pipeline that rolls raw
 * atomic event streams (orders, recipient events, engagement logs) into universal 1-day
 * minimal grain metric slices.
 *
 * It is strictly channel-agnostic:
 * - Does not know provider-specific names or business logic.
 * - Discovers derivation rules and reducer strategies via PreAggregationProviderInterface.
 * - Operates over conformed identity hashes and canonical entities.
 */
class AgnosticPreAggregationEngine
{
    private static array $channelIdMap = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Executes rollup based on rules and date range.
     *
     * @param array<string, array<string, mixed>> $rules
     * @param string $channel
     * @param DateTimeInterface|string $startDate
     * @param DateTimeInterface|string $endDate
     * @param int $attributionWindowDays
     * @return array{processed_days: int, metrics_emitted: int, records_evaluated: int, rows_rolled_up: int}
     */
    public function rollup(
        array $rules,
        string $channel,
        DateTimeInterface|string $startDate,
        DateTimeInterface|string $endDate,
        int $attributionWindowDays = 30
    ): array {
        $start = is_string($startDate) ? new DateTime($startDate) : clone $startDate;
        $end = is_string($endDate) ? new DateTime($endDate) : clone $endDate;

        $processedDays = 0;
        $metricsEmitted = 0;
        $recordsEvaluated = 0;

        $current = clone $start;
        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            $dayResult = $this->preAggregateDay($rules, $channel, $dateStr, $attributionWindowDays);

            $processedDays++;
            $metricsEmitted += $dayResult['metrics_emitted'];
            $recordsEvaluated += $dayResult['records_evaluated'];

            $current->modify('+1 day');
        }

        return [
            'processed_days' => $processedDays,
            'metrics_emitted' => $metricsEmitted,
            'records_evaluated' => $recordsEvaluated,
            'rows_rolled_up' => $recordsEvaluated,
        ];
    }

    /**
     * Executes the daily pre-aggregation rollup for a given date range across one or more driver contracts.
     *
     * @param class-string<PreAggregationProviderInterface> $driverClass
     * @param string $channel
     * @param DateTimeInterface $startDate
     * @param DateTimeInterface $endDate
     * @return array{processed_days: int, metrics_emitted: int, records_evaluated: int, rows_rolled_up: int}
     * @throws Exception
     */
    public function preAggregateDateRange(
        string $driverClass,
        string $channel,
        DateTimeInterface $startDate,
        DateTimeInterface $endDate
    ): array {
        if (!is_subclass_of($driverClass, PreAggregationProviderInterface::class)) {
            throw new Exception("Driver class [{$driverClass}] must implement PreAggregationProviderInterface.");
        }

        $rules = $driverClass::getPreAggregationRules();
        $attributionWindowDays = $driverClass::getDefaultAttributionWindowDays();

        return $this->rollup($rules, $channel, $startDate, $endDate, $attributionWindowDays);
    }

    /**
     * Rolls up atomic records for a single day based on driver rules.
     *
     * @param array<string, array<string, mixed>> $rules
     * @param string $channel
     * @param string $dateStr
     * @param int $attributionWindowDays
     * @return array{metrics_emitted: int, records_evaluated: int}
     */
    public function preAggregateDay(
        array $rules,
        string $channel,
        string $dateStr,
        int $attributionWindowDays
    ): array {
        $metricsEmitted = 0;
        $recordsEvaluated = 0;

        foreach ($rules as $ruleName => $ruleDef) {
            $sourceEntity = $ruleDef['source_entity'] ?? 'channeled_events';
            $metricDefinitions = $ruleDef['metrics'] ?? [];

            // Query atomic records for this channel & target date
            $atomicRecords = $this->fetchAtomicRecordsForDate($sourceEntity, $channel, $dateStr);
            $recordsEvaluated += count($atomicRecords);

            if (empty($atomicRecords)) {
                continue;
            }

            // Group records by scope (e.g. platform_id or associated campaign/asset)
            $groupedByScope = $this->partitionByScope($atomicRecords, $ruleDef);

            foreach ($groupedByScope as $scopeKey => $records) {
                // 1. Compute unsegmented total metrics for the scope
                $computedMetrics = $this->computeMetricsFromPayloads($records, $metricDefinitions);

                foreach ($computedMetrics as $metricKey => $metricValue) {
                    $this->persistCanonicalMetricSlice(
                        channel: $channel,
                        scopeKey: $scopeKey,
                        metricKey: $metricKey,
                        value: $metricValue,
                        date: $dateStr,
                        ruleDef: $ruleDef,
                        sampleRecord: $records[0] ?? null
                    );
                    $metricsEmitted++;
                }

                // 2. Compute dimensional slices if any metric declares dimension_fields
                foreach ($metricDefinitions as $metricKey => $metricDef) {
                    $dimFields = $metricDef['dimension_fields'] ?? [];
                    if (empty($dimFields)) {
                        continue;
                    }

                    // Slices partitioned by declared dimension fields (e.g. ['page' => 'url'])
                    $dimPartitions = [];
                    foreach ($records as $record) {
                        $dimKeyValues = [];
                        $hasAllDimFields = true;
                        foreach ($dimFields as $targetDim => $sourceField) {
                            $val = $record['data'][$sourceField] ?? ($record[$sourceField] ?? null);
                            if ($val === null || $val === '') {
                                $hasAllDimFields = false;
                                break;
                            }
                            $dimKeyValues[$targetDim] = $this->normalizeDimensionValue($targetDim, (string) $val);
                        }

                        if (!$hasAllDimFields) {
                            continue;
                        }

                        // Unique signature for this dimension tuple
                        $tupleKey = json_encode($dimKeyValues);
                        if (!isset($dimPartitions[$tupleKey])) {
                            $dimPartitions[$tupleKey] = [
                                'dims' => $dimKeyValues,
                                'records' => [],
                            ];
                        }
                        $dimPartitions[$tupleKey]['records'][] = $record;
                    }

                    foreach ($dimPartitions as $partition) {
                        $sliceMetrics = $this->computeMetricsFromPayloads(
                            $partition['records'],
                            [$metricKey => $metricDef]
                        );

                        $sliceValue = $sliceMetrics[$metricKey] ?? 0;
                        if ($sliceValue <= 0) {
                            continue;
                        }

                        // Build dimension pairs array for DimensionManager / KeyGenerator
                        $dimensionPairs = [];
                        foreach ($partition['dims'] as $dKey => $dVal) {
                            $dimensionPairs[] = [
                                'dimensionKey' => $dKey,
                                'dimensionValue' => $dVal,
                            ];
                        }

                        $dimSetId = $this->resolveDimensionSetId($dimensionPairs);
                        $dimensionsHash = KeyGenerator::generateDimensionsHash($dimensionPairs);

                        $this->persistCanonicalMetricSlice(
                            channel: $channel,
                            scopeKey: $scopeKey,
                            metricKey: $metricKey,
                            value: $sliceValue,
                            date: $dateStr,
                            ruleDef: $ruleDef,
                            sampleRecord: $partition['records'][0] ?? null,
                            dimensionSetId: $dimSetId,
                            dimensionsHash: $dimensionsHash
                        );
                        $metricsEmitted++;
                    }
                }
            }
        }

        return [
            'metrics_emitted' => $metricsEmitted,
            'records_evaluated' => $recordsEvaluated,
        ];
    }

    /**
     * Computes metric values for a given partition of atomic records using declared reducer strategies.
     *
     * @param array<int, array<string, mixed>> $records
     * @param array<string, array<string, mixed>> $metricDefinitions
     * @return array<string, float|int>
     */
    public function computeMetricsFromPayloads(array $records, array $metricDefinitions): array
    {
        $computed = [];

        foreach ($metricDefinitions as $metricKey => $def) {
            $reducer = $def['reducer'] ?? 'count';
            $field = $def['field'] ?? null;
            $conditions = $def['condition'] ?? [];

            // Filter records matching the rule condition
            $matchingRecords = array_filter($records, function ($record) use ($conditions) {
                foreach ($conditions as $condKey => $condVal) {
                    $recordVal = $record['data'][$condKey] ?? ($record[$condKey] ?? null);
                    if ($recordVal !== $condVal) {
                        return false;
                    }
                }
                return true;
            });

            if (empty($matchingRecords)) {
                $computed[$metricKey] = 0;
                continue;
            }

            $computed[$metricKey] = match ($reducer) {
                'sum' => array_reduce($matchingRecords, function ($carry, $r) use ($field) {
                    $val = $field ? ($r['data'][$field] ?? ($r[$field] ?? 0)) : 0;
                    return $carry + (float) $val;
                }, 0.0),

                'count' => count($matchingRecords),

                'count_distinct' => count(array_unique(array_filter(array_map(function ($r) use ($field) {
                    if (!$field) {
                        return null;
                    }
                    $val = $r['data'][$field] ?? ($r[$field] ?? null);
                    if ($val === null && $field === 'identity_hash') {
                        $val = $r['data']['email_id'] ?? ($r['data']['identity'] ?? ($r['email_id'] ?? null));
                    }
                    return $val;
                }, $matchingRecords)))),

                default => count($matchingRecords),
            };
        }

        return $computed;
    }

    /**
     * Resolves deterministic or identity-based attribution between an engagement event and an order.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $order
     * @param int $windowDays
     * @return bool
     */
    public function matchesAttributionWindow(array $event, array $order, int $windowDays = 30): bool
    {
        $eventHash = $event['identity_hash'] ?? null;
        $orderHash = $order['identity_hash'] ?? null;

        if (empty($eventHash) || empty($orderHash) || $eventHash !== $orderHash) {
            return false;
        }

        $eventTs = strtotime((string) ($event['timestamp'] ?? $event['platform_created_at'] ?? ''));
        $orderTs = strtotime((string) ($order['timestamp'] ?? $order['platform_created_at'] ?? ''));

        if (!$eventTs || !$orderTs) {
            return false;
        }

        // Order must happen at or after the engagement event
        if ($orderTs < $eventTs) {
            return false;
        }

        $windowSeconds = $windowDays * 86400;
        return ($orderTs - $eventTs) <= $windowSeconds;
    }

    /**
     * Fetches atomic records for a given date partition.
     */
    protected function fetchAtomicRecordsForDate(string $tableName, string $channel, string $dateStr): array
    {
        try {
            $channelId = $this->resolveChannelId($channel);
            $channelParam = $channelId > 0 ? $channelId : $channel;

            $dateColExpr = "DATE(created_at)";
            if ($tableName === 'channeled_events') {
                $dateColExpr = "DATE(COALESCE(NULLIF(data->>'timestamp', '')::timestamp, created_at))";
            } elseif ($tableName === 'channeled_orders') {
                $dateColExpr = "DATE(COALESCE(platform_created_at, created_at))";
            }

            $sql = "SELECT * FROM {$tableName} WHERE channel = :channel AND {$dateColExpr} = :date";
            $rows = $this->connection->fetchAllAssociative($sql, [
                'channel' => $channelParam,
                'date' => $dateStr,
            ]);

            foreach ($rows as &$row) {
                if (isset($row['data']) && is_string($row['data'])) {
                    $decoded = json_decode($row['data'], true);
                    if (is_array($decoded)) {
                        $row['data'] = $decoded;
                    }
                }
            }
            unset($row);

            return $rows;
        } catch (Exception $e) {
            $this->logger?->warning("Could not fetch atomic records from {$tableName}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Partitions atomic records by scope key.
     */
    protected function partitionByScope(array $records, array $ruleDef): array
    {
        $scopeField = $ruleDef['scope_field'] ?? 'platform_id';
        $partitions = [];

        foreach ($records as $r) {
            $key = (string) ($r['data'][$scopeField] ?? ($r[$scopeField] ?? 'global'));
            $partitions[$key][] = $r;
        }

        return $partitions;
    }

    /**
     * Persists a canonical 1-day metric slice into APIs Hub metric store.
     */
    protected function persistCanonicalMetricSlice(
        string $channel,
        string $scopeKey,
        string $metricKey,
        float|int $value,
        string $date,
        array $ruleDef = [],
        ?array $sampleRecord = null,
        ?int $dimensionSetId = null,
        ?string $dimensionsHash = null
    ): void {
        $this->logger?->debug("Persisting pre-aggregated metric: [{$channel}] [{$scopeKey}] {$metricKey} = {$value} on {$date}" . ($dimensionSetId ? " (dim_set: {$dimensionSetId})" : ""));

        try {
            $channelId = $this->resolveChannelId($channel);

            $scopeField = $ruleDef['scope_field'] ?? 'platform_id';
            $channeledCampaignId = null;
            $campaignId = null;
            $channeledAccountId = $sampleRecord['channeled_account_id'] ?? null;
            $accountId = null;

            if ($scopeField === 'campaign_id' && $scopeKey !== 'global' && $scopeKey !== '') {
                $campRow = $this->connection->fetchAssociative(
                    "SELECT id, campaign_id, channeled_account_id FROM channeled_campaigns WHERE platform_id = :pId AND channel = :ch LIMIT 1",
                    ['pId' => $scopeKey, 'ch' => $channelId]
                );

                if ($campRow) {
                    $channeledCampaignId = (int)$campRow['id'];
                    $campaignId = !empty($campRow['campaign_id']) ? (int)$campRow['campaign_id'] : null;
                    if (!$channeledAccountId && !empty($campRow['channeled_account_id'])) {
                        $channeledAccountId = (int)$campRow['channeled_account_id'];
                    }
                }
            }

            if ($channeledAccountId) {
                $accRow = $this->connection->fetchAssociative(
                    "SELECT account_id FROM channeled_accounts WHERE id = :id LIMIT 1",
                    ['id' => $channeledAccountId]
                );
                if ($accRow && !empty($accRow['account_id'])) {
                    $accountId = (int)$accRow['account_id'];
                }
            }

            $sigParams = [
                'channel' => (string) $channelId,
                'name' => $metricKey,
                'period' => 'daily',
            ];

            if ($accountId) {
                $sigParams['account'] = (string)$accountId;
            }
            if ($channeledAccountId) {
                $sigParams['channeledAccount'] = (string)$channeledAccountId;
            }
            if ($campaignId) {
                $sigParams['campaign'] = (string)$campaignId;
            }
            if ($scopeField === 'campaign_id' && $scopeKey !== 'global' && $scopeKey !== '') {
                $sigParams['channeledCampaign'] = (string)$scopeKey;
            }
            if ($dimensionSetId) {
                $sigParams['dimensionSet'] = (string)$dimensionsHash;
            }

            $configSignature = KeyGenerator::generateMetricConfigKey(...$sigParams);

            $mcRow = $this->connection->fetchAssociative(
                "SELECT id FROM metric_configs WHERE config_signature = :sig LIMIT 1",
                ['sig' => $configSignature]
            );

            if ($mcRow) {
                $metricConfigId = (int)$mcRow['id'];
            } else {
                $cols = ['channel', 'name', 'period', 'account_id', 'channeled_account_id', 'campaign_id', 'channeled_campaign_id', 'dimension_set_id', 'config_signature'];
                $insertValues = [
                    'channel' => $channelId,
                    'name' => $metricKey,
                    'period' => 'daily',
                    'account_id' => $accountId,
                    'channeled_account_id' => $channeledAccountId,
                    'campaign_id' => $campaignId,
                    'channeled_campaign_id' => $channeledCampaignId,
                    'dimension_set_id' => $dimensionSetId,
                    'config_signature' => $configSignature,
                ];

                $sql = Helpers::buildUpsertSql('metric_configs', $cols, ['name'], ['config_signature'], 1);
                $this->connection->executeStatement($sql, array_values($insertValues));

                $metricConfigId = (int)$this->connection->fetchOne(
                    "SELECT id FROM metric_configs WHERE config_signature = :sig LIMIT 1",
                    ['sig' => $configSignature]
                );
            }

            $effectiveDimensionsHash = $dimensionsHash ?? KeyGenerator::generateDimensionsHash([]);

            $metricCols = ['value', 'metadata', 'dimensions_hash', 'metric_config_id', 'metric_date'];
            $metricValues = [
                $value,
                null,
                $effectiveDimensionsHash,
                $metricConfigId,
                $date,
            ];

            $metricSql = Helpers::buildUpsertSql(
                'metrics',
                $metricCols,
                ['value'],
                ['metric_config_id', 'dimensions_hash', 'metric_date'],
                1
            );

            $this->connection->executeStatement($metricSql, $metricValues);
        } catch (Exception $e) {
            $this->logger?->warning("[AgnosticPreAggregationEngine] Failed to persist canonical metric slice: " . $e->getMessage(), [
                'channel' => $channel,
                'metric' => $metricKey,
                'date' => $date,
                'value' => $value,
            ]);
        }
    }

    /**
     * Resolves or inserts dimension sets and values into dimension tables.
     *
     * @param array<int, array{dimensionKey: string, dimensionValue: string}> $dimensions
     */
    public function resolveDimensionSetId(array $dimensions): int
    {
        $hash = KeyGenerator::generateDimensionsHash($dimensions);

        $row = $this->connection->fetchAssociative('SELECT id FROM dimension_sets WHERE hash = :hash LIMIT 1', ['hash' => $hash]);
        if ($row) {
            return (int) $row['id'];
        }

        $this->connection->executeStatement(
            'INSERT INTO dimension_sets (hash) VALUES (?) ON CONFLICT (hash) DO NOTHING',
            [$hash]
        );

        $setId = (int) $this->connection->fetchOne('SELECT id FROM dimension_sets WHERE hash = ?', [$hash]);

        foreach ($dimensions as $dim) {
            $keyName = $dim['dimensionKey'];
            $valStr = (string) ($dim['dimensionValue'] ?? '');

            // 1. Resolve dimension key
            $keyRow = $this->connection->fetchAssociative('SELECT id FROM dimension_keys WHERE name = :name LIMIT 1', ['name' => $keyName]);
            if ($keyRow) {
                $keyId = (int) $keyRow['id'];
            } else {
                $this->connection->executeStatement(
                    'INSERT INTO dimension_keys (name) VALUES (?) ON CONFLICT (name) DO NOTHING',
                    [$keyName]
                );
                $keyId = (int) $this->connection->fetchOne('SELECT id FROM dimension_keys WHERE name = ?', [$keyName]);
            }

            // 2. Resolve dimension value
            $valRow = $this->connection->fetchAssociative(
                'SELECT id FROM dimension_values WHERE dimension_key_id = :kId AND value = :val LIMIT 1',
                ['kId' => $keyId, 'val' => $valStr]
            );
            if ($valRow) {
                $valId = (int) $valRow['id'];
            } else {
                $this->connection->executeStatement(
                    'INSERT INTO dimension_values (dimension_key_id, value) VALUES (?, ?) ON CONFLICT (dimension_key_id, value) DO NOTHING',
                    [$keyId, $valStr]
                );
                $valId = (int) $this->connection->fetchOne(
                    'SELECT id FROM dimension_values WHERE dimension_key_id = ? AND value = ?',
                    [$keyId, $valStr]
                );
            }

            // 3. Link item to set
            $this->connection->executeStatement(
                'INSERT INTO dimension_set_items (dimension_set_id, dimension_value_id) VALUES (?, ?) ON CONFLICT DO NOTHING',
                [$setId, $valId]
            );
        }

        return $setId;
    }

    /**
     * Resolves integer channel ID from channel name or number.
     */
    protected function resolveChannelId(string|int $channel): int
    {
        if (is_numeric($channel) && (int)$channel > 0) {
            return (int)$channel;
        }

        $channelName = (string)$channel;
        if (isset(self::$channelIdMap[$channelName])) {
            return self::$channelIdMap[$channelName];
        }

        try {
            $id = $this->connection->fetchOne("SELECT id FROM channels WHERE name = :name LIMIT 1", ['name' => $channelName]);
            if ($id) {
                return self::$channelIdMap[$channelName] = (int)$id;
            }
        } catch (Exception $e) {
            // fallback
        }

        return 0;
    }

    /**
     * Normalizes a dimension value before grouping and storing in dimension tables.
     */
    public function normalizeDimensionValue(string $dimensionKey, string $value): string
    {
        $cleanDim = strtolower(trim($dimensionKey));
        if (in_array($cleanDim, ['page', 'page_path', 'landing_page', 'url'], true)) {
            return $this->normalizeUrlDimension($value);
        }

        return trim($value);
    }

    /**
     * Canonicalizes a URL dimension into a clean path without email/ad tracking query strings.
     */
    protected function normalizeUrlDimension(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '/';
        }

        // If it starts with http:// or https://, extract path and query
        if (preg_match('/^https?:\/\//i', $url)) {
            $path = parse_url($url, PHP_URL_PATH);
            $query = parse_url($url, PHP_URL_QUERY);
            $canonical = ($path !== null && $path !== '') ? $path : '/';
            if (!empty($query)) {
                $canonical .= '?' . $query;
            }
        } else {
            $canonical = str_starts_with($url, '/') ? $url : '/' . $url;
        }

        // Strip marketing/email tracking parameters (utm_*, mc_cid, mc_eid, etc.)
        if (str_contains($canonical, '?')) {
            [$basePath, $rawQuery] = explode('?', $canonical, 2);
            parse_str($rawQuery, $queryParams);
            if (is_array($queryParams) && !empty($queryParams)) {
                $filtered = [];
                $trackingPrefixes = ['utm_', 'mc_'];
                $trackingKeys = ['gclid', 'fbclid', 'msclkid', 'ttclid', 'dclid', '_hsenc', '_hsmi', 'ref', 'source'];

                foreach ($queryParams as $pKey => $pVal) {
                    $lowerKey = strtolower((string) $pKey);
                    $isTracking = in_array($lowerKey, $trackingKeys, true);
                    if (!$isTracking) {
                        foreach ($trackingPrefixes as $prefix) {
                            if (str_starts_with($lowerKey, $prefix)) {
                                $isTracking = true;
                                break;
                            }
                        }
                    }
                    if (!$isTracking) {
                        $filtered[$pKey] = $pVal;
                    }
                }

                if (!empty($filtered)) {
                    ksort($filtered);
                    $canonical = $basePath . '?' . http_build_query($filtered);
                } else {
                    $canonical = $basePath;
                }
            } else {
                $canonical = $basePath;
            }
        }

        // Remove trailing slash except for root '/'
        if (strlen($canonical) > 1 && str_ends_with($canonical, '/')) {
            $canonical = rtrim($canonical, '/');
        }

        return $canonical !== '' ? $canonical : '/';
    }
}
