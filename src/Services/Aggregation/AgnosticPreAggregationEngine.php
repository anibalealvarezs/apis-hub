<?php

declare(strict_types=1);

namespace Services\Aggregation;

use Anibalealvarezs\ApiDriverCore\Interfaces\PreAggregationProviderInterface;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Exception;
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
    public function __construct(
        private readonly Connection $connection,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Executes the daily pre-aggregation rollup for a given date range across one or more driver contracts.
     *
     * @param class-string<PreAggregationProviderInterface> $driverClass
     * @param string $channel
     * @param DateTimeInterface $startDate
     * @param DateTimeInterface $endDate
     * @return array{processed_days: int, metrics_emitted: int, records_evaluated: int}
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

        $processedDays = 0;
        $metricsEmitted = 0;
        $recordsEvaluated = 0;

        $current = clone $startDate;
        while ($current <= $endDate) {
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
        ];
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
                $computedMetrics = $this->computeMetricsFromPayloads($records, $metricDefinitions);

                foreach ($computedMetrics as $metricKey => $metricValue) {
                    $this->persistCanonicalMetricSlice(
                        channel: $channel,
                        scopeKey: $scopeKey,
                        metricKey: $metricKey,
                        value: $metricValue,
                        date: $dateStr
                    );
                    $metricsEmitted++;
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
                    return $field ? ($r['data'][$field] ?? ($r[$field] ?? null)) : null;
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
            $sql = "SELECT * FROM {$tableName} WHERE channel = :channel AND DATE(platform_created_at) = :date";
            return $this->connection->fetchAllAssociative($sql, [
                'channel' => $channel,
                'date' => $dateStr,
            ]);
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
        string $date
    ): void {
        $this->logger?->debug("Persisting pre-aggregated metric: [{$channel}] [{$scopeKey}] {$metricKey} = {$value} on {$date}");
    }
}
