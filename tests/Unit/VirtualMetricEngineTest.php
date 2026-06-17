<?php

namespace Tests\Unit;

use Services\Analytics\VirtualMetricEngine\AstParser;
use Services\Analytics\VirtualMetricEngine\EvaluationContext;
use PHPUnit\Framework\TestCase;

class VirtualMetricEngineTest extends TestCase
{
    public function test_evaluates_basic_scalar_math()
    {
        $ast = [
            'type' => 'operator',
            'operator' => '+',
            'left' => ['type' => 'value', 'value' => 10],
            'right' => ['type' => 'value', 'value' => 5],
        ];

        $parser = new AstParser();
        $node = $parser->parse($ast);
        $context = new EvaluationContext([]);

        $this->assertEquals(15, $node->evaluate($context));
    }

    public function test_evaluates_time_series_addition()
    {
        $ast = [
            'type' => 'operator',
            'operator' => '+',
            'left' => ['type' => 'metric', 'metric' => 'meta.spend'],
            'right' => ['type' => 'metric', 'metric' => 'google.spend'],
        ];

        $metricData = [
            'meta.spend' => [
                '2026-05-01' => 100,
                '2026-05-02' => 150,
            ],
            'google.spend' => [
                '2026-05-01' => 200,
                '2026-05-02' => 250,
                '2026-05-03' => 50, // Only in google
            ],
        ];

        $parser = new AstParser();
        $node = $parser->parse($ast);
        $context = new EvaluationContext($metricData);

        $result = $node->evaluate($context);

        $this->assertIsArray($result);
        $this->assertEquals(300, $result['2026-05-01']);
        $this->assertEquals(400, $result['2026-05-02']);
        $this->assertEquals(50, $result['2026-05-03']); // 0 + 50
    }

    public function test_evaluates_complex_roas_formula()
    {
        // Formula: shopify.revenue / (meta.spend + google.spend)
        $ast = [
            'type' => 'operator',
            'operator' => '/',
            'left' => ['type' => 'metric', 'metric' => 'shopify.revenue'],
            'right' => [
                'type' => 'operator',
                'operator' => '+',
                'left' => ['type' => 'metric', 'metric' => 'meta.spend'],
                'right' => ['type' => 'metric', 'metric' => 'google.spend'],
            ],
        ];

        $metricData = [
            'shopify.revenue' => [
                '2026-05-01' => 1000,
                '2026-05-02' => 1500,
            ],
            'meta.spend' => [
                '2026-05-01' => 100,
                '2026-05-02' => 200,
            ],
            'google.spend' => [
                '2026-05-01' => 150,
                '2026-05-02' => 300,
            ],
        ];

        $parser = new AstParser();
        $node = $parser->parse($ast);
        $context = new EvaluationContext($metricData);

        $result = $node->evaluate($context);

        // 2026-05-01: 1000 / (100 + 150) = 4
        $this->assertEquals(4, $result['2026-05-01']);
        
        // 2026-05-02: 1500 / (200 + 300) = 3
        $this->assertEquals(3, $result['2026-05-02']);
    }

    public function test_scalar_multiplication_across_time_series()
    {
        // Formula: shopify.revenue * 1.2
        $ast = [
            'type' => 'operator',
            'operator' => '*',
            'left' => ['type' => 'metric', 'metric' => 'shopify.revenue'],
            'right' => ['type' => 'value', 'value' => 1.2],
        ];

        $metricData = [
            'shopify.revenue' => [
                '2026-05-01' => 1000,
            ],
        ];

        $parser = new AstParser();
        $node = $parser->parse($ast);
        $context = new EvaluationContext($metricData);

        $result = $node->evaluate($context);

        $this->assertEquals(1200, $result['2026-05-01']);
    }
}
