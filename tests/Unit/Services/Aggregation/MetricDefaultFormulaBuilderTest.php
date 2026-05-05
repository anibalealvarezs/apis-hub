<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Services\Aggregation\MetricDefaultFormulaBuilder;
use Tests\Unit\BaseUnitTestCase;

final class MetricDefaultFormulaBuilderTest extends BaseUnitTestCase
{
    public function testBuildIncludesCanonicalMetricFormulas(): void
    {
        $formulas = (new MetricDefaultFormulaBuilder())->build(
            valCol: 'm.value',
            isPostgres: false,
            periodCondition: "mc.period = 'daily'",
        );

        $this->assertArrayHasKey('spend', $formulas);
        $this->assertArrayHasKey('clicks', $formulas);
        $this->assertArrayHasKey('ctr', $formulas);
        $this->assertArrayHasKey('campaign_status', $formulas);
        $this->assertSame('MIN(rcc.status)', $formulas['campaign_status']);
    }

    public function testPeriodAwareOverridesUseProvidedPeriodCondition(): void
    {
        $periodCondition = "LOWER(mc.period) = 'weekly'";

        $formulas = (new MetricDefaultFormulaBuilder())->build(
            valCol: 'm.value',
            isPostgres: true,
            periodCondition: $periodCondition,
        );

        $this->assertArrayHasKey('total_interactions', $formulas);
        $this->assertStringContainsString($periodCondition, $formulas['total_interactions']);
    }

    public function testBuildReturnsNonEmptyFormulaSet(): void
    {
        $formulas = (new MetricDefaultFormulaBuilder())->build(
            valCol: 'e.value',
            isPostgres: false,
            periodCondition: "mc.period = 'daily'",
        );

        $this->assertNotEmpty($formulas);
        $this->assertGreaterThan(10, count($formulas));
    }
}

