<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Services\Aggregation\SnapshotAggregateMetaExtractor;
use Tests\Unit\BaseUnitTestCase;

final class SnapshotAggregateMetaExtractorTest extends BaseUnitTestCase
{
    public function testReturnsEmptyMetaWhenNoSnapshotMarkerExists(): void
    {
        $rows = [
            ['clicks' => 10],
            ['clicks' => 20],
        ];

        $meta = (new SnapshotAggregateMetaExtractor())->extract(
            results: $rows,
            startDate: '2026-05-01',
            endDate: '2026-05-31',
            snapshotFallbackMode: 'resilient',
        );

        $this->assertSame([], $meta);
        $this->assertSame([['clicks' => 10], ['clicks' => 20]], $rows);
    }

    public function testExtractsMetaAndRemovesSnapshotMarker(): void
    {
        $rows = [
            ['clicks' => 10, '__snapshot_effective_date' => '2026-05-29'],
            ['clicks' => 20, '__snapshot_effective_date' => '2026-05-30'],
            ['clicks' => 30, '__snapshot_effective_date' => '2026-05-30'],
        ];

        $meta = (new SnapshotAggregateMetaExtractor())->extract(
            results: $rows,
            startDate: '2026-05-01',
            endDate: '2026-05-31',
            snapshotFallbackMode: 'resilient',
        );

        $this->assertSame('resilient', $meta['snapshot_fallback_mode']);
        $this->assertSame(['2026-05-29', '2026-05-30'], $meta['effective_end_date']);
        $this->assertSame(['2026-05-29', '2026-05-30'], $meta['fallback_end_date']);
        $this->assertSame('2026-05-01', $meta['requested_start_date']);
        $this->assertSame('2026-05-31', $meta['requested_end_date']);

        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('__snapshot_effective_date', $row);
        }
    }

    public function testFallbackEndDateIsOmittedWhenRequestedEndDateMatchesEffectiveDate(): void
    {
        $rows = [
            ['clicks' => 10, '__snapshot_effective_date' => '2026-05-31'],
        ];

        $meta = (new SnapshotAggregateMetaExtractor())->extract(
            results: $rows,
            startDate: null,
            endDate: '2026-05-31',
            snapshotFallbackMode: 'strict',
        );

        $this->assertSame('strict', $meta['snapshot_fallback_mode']);
        $this->assertSame('2026-05-31', $meta['effective_end_date']);
        $this->assertArrayNotHasKey('fallback_end_date', $meta);
        $this->assertArrayNotHasKey('requested_start_date', $meta);
        $this->assertSame('2026-05-31', $meta['requested_end_date']);
    }
}

