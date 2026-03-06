<?php

namespace Tests\Integration\Conversions;

use Classes\Conversions\KlaviyoConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use Tests\Integration\BaseIntegrationTestCase;
use Carbon\Carbon;

class KlaviyoConvertTest extends BaseIntegrationTestCase
{
    public function testCustomersConvertsDataCorrectly(): void
    {
        $rows = [
            [
                'id' => 'profile_123',
                'attributes' => [
                    'created' => '2026-03-05T12:00:00Z',
                    'email' => 'test@klaviyo.com'
                ]
            ]
        ];

        $collection = KlaviyoConvert::customers($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $customer = $collection->first();
        $this->assertEquals('profile_123', $customer->platformId);
        $this->assertEquals(Channel::klaviyo->value, $customer->channel);
        $this->assertEquals('test@klaviyo.com', $customer->email);
        $this->assertInstanceOf(Carbon::class, $customer->platformCreatedAt);
    }

    public function testProductsAndVariantsConvertsDataCorrectly(): void
    {
        $rows = [
            [
                'id' => 'prod_123',
                'sku' => 'PROD-K-1',
                'attributes' => [
                    'created' => '2026-03-05T12:00:00Z',
                ],
                // Klaviyo structure includes variants in an 'included' array
                'included' => [
                    [
                        'id' => 'var_123',
                        'sku' => 'PROD-K-1-V1',
                        'attributes' => [
                            'created' => '2026-03-05T12:01:00Z',
                        ]
                    ]
                ]
            ]
        ];

        $collection = KlaviyoConvert::products($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $product = $collection->first();
        $this->assertEquals('prod_123', $product->platformId);
        $this->assertEquals('PROD-K-1', $product->sku);
        $this->assertNull($product->vendor);

        $this->assertInstanceOf(ArrayCollection::class, $product->variants);
        $this->assertCount(1, $product->variants);

        $variant = $product->variants->first();
        $this->assertEquals('var_123', $variant->platformId);
        $this->assertEquals('PROD-K-1-V1', $variant->sku);
    }

    public function testProductCategoriesConvertsDataCorrectly(): void
    {
        $rows = [
            [
                'id' => 'cat_123',
                'attributes' => [
                    'created' => '2026-03-05T12:00:00Z',
                ]
            ]
        ];

        $collection = KlaviyoConvert::productCategories($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $category = $collection->first();
        $this->assertEquals('cat_123', $category->platformId);
        $this->assertInstanceOf(Carbon::class, $category->platformCreatedAt);
    }

    public function testMetricAggregatesConvertsDataCorrectlyWithoutLiveApi(): void
    {
        $aggregates = [
            'dates' => ['2026-03-05T00:00:00Z'],
            'data' => [
                [
                    'measurements' => ['count' => 45],
                    'dimensions' => ['country' => 'US']
                ]
            ]
        ];

        // We provide a metric names map to bypass the Redis/Live API fallback inside the metricAggregates function
        $mockMetricNamesMap = [
            'met_123' => 'Placed Order'
        ];

        $collection = KlaviyoConvert::metricAggregates($aggregates, 'met_123', $mockMetricNamesMap);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $metric = $collection->first();
        $this->assertEquals('met_123', $metric->platformId);
        $this->assertEquals('Placed Order', $metric->name);
        $this->assertEquals(Channel::klaviyo->value, $metric->channel);
        $this->assertEquals(45, $metric->value);
        $this->assertEquals('US', $metric->data['country']);
    }
}
