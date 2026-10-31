<?php

namespace Services\Aggregation;

/** End-anchored disjoint buckets. Weighted reducers run inside each bucket in SQL. */
final class BoundedTemporalBuckets
{
    public function expression(string $field, string $dateColumn, ?string $start, ?string $end): string
    {
        $interval = [
            'trailing_weekly' => 'P7D', 'trailing_monthly' => 'P1M', 'trailing_quarterly' => 'P3M',
            'trailing_semiannual' => 'P6M', 'trailing_annually' => 'P1Y', 'trailing_yearly' => 'P1Y',
        ][$field] ?? null;
        if (!$interval || !$start || !$end) throw new \InvalidArgumentException('Trailing buckets require explicit dates and a supported granularity.');
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $cursor = \DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        if (!$first || !$cursor || $first->format('Y-m-d') !== $start || $cursor->format('Y-m-d') !== $end || $first > $cursor || $first->diff($cursor)->days > 3660) throw new \InvalidArgumentException('Invalid or excessive trailing report window.');
        $cases = [];
        do {
            $previous = $cursor->sub(new \DateInterval($interval));
            $cases[] = "WHEN $dateColumn > '".$previous->format('Y-m-d')."' AND $dateColumn <= '".$cursor->format('Y-m-d')."' THEN '".$cursor->format('Y-m-d')."'";
            $cursor = $previous;
        } while ($cursor >= $first);
        return 'CASE '.implode(' ', $cases).' END';
    }
}
