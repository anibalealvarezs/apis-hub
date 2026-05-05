<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Services\Aggregation\CompanionTimeWeightedAverageFormulaBuilder;
use Tests\Unit\BaseUnitTestCase;

final class CompanionTimeWeightedAverageFormulaBuilderTest extends BaseUnitTestCase
{
    public function testBuildUsesPostgresComparatorAndMetricDateForChanneledMetric(): void
    {
        $sql = (new CompanionTimeWeightedAverageFormulaBuilder())->build(
            sourceMetricNames: ['avg_watch_time'],
            totalTimeMetricNames: ['video_view_total_time'],
            valCol: 'm.value',
            isPostgres: true,
            periodCondition: "LOWER(mc.period) = 'daily'",
            isChanneledMetric: true,
            toSqlStringList: static fn(array $values): string => "'".implode("','", $values)."'",
        );

        $this->assertStringContainsString('IS NOT DISTINCT FROM', $sql);
        $this->assertStringContainsString('m.metric_date', $sql);
        $this->assertStringContainsString("LOWER(mc2.period) = 'daily'", $sql);
    }

    public function testBuildUsesMySqlComparatorAndMetricDateForMetricEntity(): void
    {
        $sql = (new CompanionTimeWeightedAverageFormulaBuilder())->build(
            sourceMetricNames: ['avg_watch_time'],
            totalTimeMetricNames: ['video_view_total_time'],
            valCol: 'e.value',
            isPostgres: false,
            periodCondition: "mc.period = 'daily'",
            isChanneledMetric: false,
            toSqlStringList: static fn(array $values): string => "'".implode("','", $values)."'",
        );

        $this->assertStringContainsString('<=>', $sql);
        $this->assertStringContainsString('e.metric_date', $sql);
        $this->assertStringContainsString("mc2.period = 'daily'", $sql);
    }
}

