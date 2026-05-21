<?php

    declare(strict_types=1);

    namespace Tests\Unit\Services\Aggregation;

    use Services\Aggregation\AggregationPlan;
    use Tests\Unit\BaseUnitTestCase;

    final class AggregationPlanTest extends BaseUnitTestCase
    {
        public function testExposesContextAndStageConvenienceAccessors(): void
        {
            $plan = new AggregationPlan(
                aggregations: ['clicks' => 'clicks'],
                context: [
                    'entity_name' => 'Entities\\Analytics\\Metric',
                    'is_metric'   => true,
                ],
                stages: [
                    'grouping' => [
                        'group_by'              => ['daily'],
                        'has_temporal_grouping' => true,
                    ],
                ],
            );

            $this->assertSame('Entities\\Analytics\\Metric', $plan->getContextValue('entity_name'));
            $this->assertTrue($plan->getContextValue('is_metric'));
            $this->assertSame(['daily'], $plan->getStageValue('grouping', 'group_by'));
            $this->assertTrue($plan->getStageValue('grouping', 'has_temporal_grouping'));
            $this->assertSame([], $plan->getStage('missing_stage'));
            $this->assertSame('fallback', $plan->getStageValue('missing_stage', 'unknown', 'fallback'));
        }
    }

