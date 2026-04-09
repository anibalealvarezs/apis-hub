<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Helpers\GoogleSearchConsoleHelpers;
use PHPUnit\Framework\TestCase;

class GoogleSearchConsoleHelpersTest extends TestCase
{
    public function testIsParentOf()
    {
        $parentSubset = ['date', 'page'];
        $parentDims = ['2023-01-01', 'https://example.com/'];
        
        $childSubset = ['date', 'query', 'page'];
        $childDims = ['2023-01-01', 'test query', 'https://example.com/'];

        // Parent dims map to child dims at their respective subset indices:
        // parent 'date' (idx 0) -> child 'date' (idx 0): match
        // parent 'page' (idx 1) -> child 'page' (idx 2): match
        $this->assertTrue(GoogleSearchConsoleHelpers::isParentOf($parentSubset, $parentDims, $childSubset, $childDims));

        // Negative cases
        $this->assertFalse(GoogleSearchConsoleHelpers::isParentOf($childSubset, $childDims, $parentSubset, $parentDims)); // Child is longer
        
        $differentPage = ['2023-01-01', 'https://other.com/'];
        $this->assertFalse(GoogleSearchConsoleHelpers::isParentOf($parentSubset, $differentPage, $childSubset, $childDims));
    }

    public function testComputeChildrenSum()
    {
        $records = [
            [
                'subset' => ['date', 'page'],
                'keys' => ['2023-01-01', 'url1'],
                'impressions' => 100,
                'clicks' => 10
            ],
            [
                'subset' => ['date', 'query', 'page'],
                'keys' => ['2023-01-01', 'q1', 'url1'],
                'impressions' => 40,
                'clicks' => 4
            ],
            [
                'subset' => ['date', 'query', 'page'],
                'keys' => ['2023-01-01', 'q2', 'url1'],
                'impressions' => 30,
                'clicks' => 3
            ]
        ];

        $sums = GoogleSearchConsoleHelpers::computeChildrenSum($records);

        // Record 0 (parent) should have sum of records 1 and 2
        $this->assertEquals(70, $sums[0]['impressions']);
        $this->assertEquals(7, $sums[0]['clicks']);

        // Records 1 and 2 have no children in this set
        $this->assertEquals(0, $sums[1]['impressions']);
        $this->assertEquals(0, $sums[2]['impressions']);
    }

    public function testCalculateDifferences()
    {
        $records = [
            ['impressions' => 100, 'clicks' => 10],
            ['impressions' => 40, 'clicks' => 4]
        ];
        $sums = [
            ['impressions' => 70, 'clicks' => 7],
            ['impressions' => 0, 'clicks' => 0]
        ];

        $result = GoogleSearchConsoleHelpers::calculateDifferences($records, $sums);

        $this->assertEquals(30, $result[0]['impressions_difference']);
        $this->assertEquals(3, $result[0]['clicks_difference']);
    }

    public function testAllocatePositiveDifferences()
    {
        $records = [
            [
                'subset' => ['date', 'page'],
                'keys' => ['2023-01-01', 'url1'],
                'impressions_difference' => 30,
                'clicks_difference' => 3,
            ]
        ];
        $dims = ['date', 'query', 'page'];

        $result = GoogleSearchConsoleHelpers::allocatePositiveDifferences($records, $dims);

        // Should have 2 records: original and synthetic
        $this->assertCount(2, $result);
        $this->assertTrue($result[1]['synthetic']);
        $this->assertEquals(['date', 'page', 'query'], $result[1]['subset']);
        $this->assertEquals(['2023-01-01', 'url1', 'unknown'], $result[1]['keys']);
        $this->assertEquals(30, $result[1]['impressions']);
    }

    public function testFlagOrScaleNegativeDifferences()
    {
        $records = [
            [
                'impressions' => 100,
                'clicks' => 10,
                'impressions_difference' => -20, // Sum of children is 120
                'children_sum' => ['impressions' => 120, 'clicks' => 12]
            ]
        ];

        $result = GoogleSearchConsoleHelpers::flagOrScaleNegativeDifferences($records, true);

        $this->assertTrue($result[0]['scaled']);
        $this->assertEquals(100, $result[0]['original_impressions']);
        // Scaled values (children * factor where factor = parent/children = 100/120)
        // Actually the logic is: round($childrenImpressions * $scaleFactorImpr) where factor = impressions / children
        // round(120 * (100/120)) = 100.
        $this->assertEquals(100, $result[0]['impressions']);
    }
}
