<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Anibalealvarezs\ApiDriverCore\Classes\CanonicalMetricDefinitionRegistry;
use PHPUnit\Framework\TestCase;
use Services\Aggregation\AggregationPlanner;
use Repositories\BaseRepository;
use ReflectionMethod;

final class AggregationPlannerHardeningTest extends TestCase
{
    private AggregationPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AggregationPlanner();
    }

    public function testAnalyzeReducersAllowsRegisteredCanonicalMetrics(): void
    {
        // 1. Arrange: Register a temporary "new" metric that wasn't in the old whitelist
        CanonicalMetricDefinitionRegistry::register('custom_growth_metric', [
            'label' => 'Custom Growth',
            'category' => 'organic'
        ]);

        // 2. Access private method analyzeReducers via reflection
        $method = new ReflectionMethod(AggregationPlanner::class, 'analyzeReducers');
        $method->setAccessible(true);

        // 3. Act: Analyze aggregations including the new metric
        $aggregations = [
            'my_custom_alias' => 'custom_growth_metric',
            'standard_clicks' => 'clicks'
        ];
        
        $result = $method->invoke($this->planner, $aggregations, true);

        // 4. Assert: custom_growth_metric should NOT be in missing_reducer_expressions
        $this->assertEmpty($result['missing_reducer_expressions'], 'New registered metric should be allowed by the planner.');
        $this->assertContains('custom_growth_metric', array_values($aggregations));
    }

    public function testAnalyzeReducersAllowsAliasesOfCanonicalMetrics(): void
    {
        // 1. Arrange: 'leads' is an alias for 'conversions' (registered earlier)
        CanonicalMetricDefinitionRegistry::register('conversions', [
            'label' => 'Conversions',
            'category' => 'base'
        ], ['leads']);

        $method = new ReflectionMethod(AggregationPlanner::class, 'analyzeReducers');
        $method->setAccessible(true);

        // 2. Act
        $aggregations = ['my_leads' => 'leads'];
        $result = $method->invoke($this->planner, $aggregations, true);

        // 3. Assert
        $this->assertEmpty($result['missing_reducer_expressions'], 'Registered alias should be allowed by the planner.');
    }

    public function testAnalyzeReducersBlocksUnknownMetrics(): void
    {
        $method = new ReflectionMethod(AggregationPlanner::class, 'analyzeReducers');
        $method->setAccessible(true);

        // Act: 'non_existent_metric' is NOT in the registry
        $aggregations = ['invalid' => 'non_existent_metric'];
        $result = $method->invoke($this->planner, $aggregations, true);

        // Assert: It should be marked as missing
        $this->assertContains('non_existent_metric', $result['missing_reducer_expressions']);
    }
}
