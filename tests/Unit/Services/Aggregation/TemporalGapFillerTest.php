<?php

    declare(strict_types=1);

    namespace Tests\Unit\Services\Aggregation;

    use Exception;
    use Services\Aggregation\TemporalGapFiller;
    use Tests\Unit\BaseUnitTestCase;

    final class TemporalGapFillerTest extends BaseUnitTestCase
    {
        /**
         * @throws Exception
         */
        public function testFillsMissingDailyBucketsForTemporalOnlyGrouping(): void
        {
            $results = [
                ['daily' => '2026-05-01', 'clicks' => 10],
                ['daily' => '2026-05-03', 'clicks' => 30],
            ];

            $rows = (new TemporalGapFiller())->fill(
                results: $results,
                temporalField: 'daily',
                type: 'daily',
                startDate: '2026-05-01',
                endDate: '2026-05-03',
                aggregations: ['clicks' => 'clicks'],
                groupBy: ['daily'],
            );

            $this->assertCount(3, $rows);
            $this->assertSame('2026-05-02', $rows[1]['daily']);
            $this->assertSame(0, $rows[1]['clicks']);
        }

        /**
         * @throws Exception
         */
        public function testFillsMissingBucketsPerNonTemporalCombination(): void
        {
            $results = [
                ['daily' => '2026-05-01', 'gender' => 'female', 'clicks' => 10],
                ['daily' => '2026-05-03', 'gender' => 'female', 'clicks' => 20],
                ['daily' => '2026-05-01', 'gender' => 'male', 'clicks' => 5],
            ];

            $rows = (new TemporalGapFiller())->fill(
                results: $results,
                temporalField: 'daily',
                type: 'daily',
                startDate: '2026-05-01',
                endDate: '2026-05-03',
                aggregations: ['clicks' => 'clicks'],
                groupBy: ['daily', 'gender'],
            );

            $this->assertCount(6, $rows);

            $maleRows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => ($row['gender'] ?? null) === 'male'
            ));
            $this->assertCount(3, $maleRows);

            $maleByDate = [];
            foreach ($maleRows as $row) {
                $maleByDate[(string)$row['daily']] = $row;
            }

            $this->assertSame(5, $maleByDate['2026-05-01']['clicks']);
            $this->assertSame(0, $maleByDate['2026-05-02']['clicks']);
            $this->assertSame(0, $maleByDate['2026-05-03']['clicks']);
        }

        /**
         * @throws Exception
         */
        public function testFillsMissingTrailingAnnuallyBuckets(): void
        {
            $results = [
                ['trailing_annually' => 'TTM (to Oct 19, 2026)', 'clicks' => 500],
            ];

            $rows = (new TemporalGapFiller())->fill(
                results: $results,
                temporalField: 'trailing_annually',
                type: 'trailing_annually',
                startDate: '2024-10-20',
                endDate: '2026-10-19',
                aggregations: ['clicks' => 'clicks'],
                groupBy: ['trailing_annually'],
            );

            // Should contain TTM (to Oct 19, 2026), TTM (to Oct 19, 2025), TTM (to Oct 19, 2024)
            $this->assertCount(3, $rows);
            $this->assertSame('TTM (to Oct 19, 2024)', $rows[0]['trailing_annually']);
            $this->assertSame(0, $rows[0]['clicks']);
            $this->assertSame('TTM (to Oct 19, 2025)', $rows[1]['trailing_annually']);
            $this->assertSame(0, $rows[1]['clicks']);
            $this->assertSame('TTM (to Oct 19, 2026)', $rows[2]['trailing_annually']);
            $this->assertSame(500, $rows[2]['clicks']);
        }
    }

