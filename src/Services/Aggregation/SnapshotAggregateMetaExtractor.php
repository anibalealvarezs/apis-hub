<?php

declare(strict_types=1);

namespace Services\Aggregation;

final class SnapshotAggregateMetaExtractor
{
    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<string, mixed>
     */
    public function extract(
        array &$results,
        ?string $startDate,
        ?string $endDate,
        string $snapshotFallbackMode
    ): array {
        $effectiveDates = [];
        foreach ($results as &$row) {
            if (!array_key_exists('__snapshot_effective_date', $row)) {
                continue;
            }

            $date = $row['__snapshot_effective_date'];
            if (is_string($date) && trim($date) !== '') {
                $effectiveDates[] = trim($date);
            }
            unset($row['__snapshot_effective_date']);
        }
        unset($row);

        if ($effectiveDates === []) {
            return [];
        }

        $effectiveDates = array_values(array_unique($effectiveDates));
        sort($effectiveDates);

        $meta = [
            'snapshot_fallback_mode' => $snapshotFallbackMode,
            'effective_end_date' => count($effectiveDates) === 1 ? $effectiveDates[0] : $effectiveDates,
        ];

        if ($endDate !== null) {
            $fallbackDates = array_values(array_filter(
                $effectiveDates,
                static fn(string $d): bool => $d !== $endDate
            ));

            if ($fallbackDates !== []) {
                $meta['fallback_end_date'] = count($fallbackDates) === 1 ? $fallbackDates[0] : $fallbackDates;
            }
        }

        if ($startDate !== null) {
            $meta['requested_start_date'] = $startDate;
        }
        if ($endDate !== null) {
            $meta['requested_end_date'] = $endDate;
        }

        return $meta;
    }
}

