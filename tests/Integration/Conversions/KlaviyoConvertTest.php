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
        $platformId = 'profile_' . $this->faker->uuid;
        $email = $this->faker->safeEmail;
        $createdAt = $this->faker->iso8601;

        $rows = [
            [
                'id' => $platformId,
                'attributes' => [
                    'created' => $createdAt,
                    'email' => $email
                ]
            ]
        ];

        $collection = KlaviyoConvert::customers($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $customer = $collection->first();
        $this->assertEquals($platformId, $customer->platformId);
        $this->assertEquals(Channel::klaviyo->value, $customer->channel);
        $this->assertEquals($email, $customer->email);
        $this->assertInstanceOf(Carbon::class, $customer->platformCreatedAt);
    }

    public function testProductsAndVariantsConvertsDataCorrectly(): void
    {
        $prodId = 'prod_' . $this->faker->uuid;
        $prodSku = 'SKU-' . $this->faker->bothify('??-###');
        $prodCreated = $this->faker->iso8601;
        $varId = 'var_' . $this->faker->uuid;
        $varSku = $prodSku . '-V1';
        $varCreated = $this->faker->iso8601;

        $rows = [
            [
                'id' => $prodId,
                'sku' => $prodSku,
                'attributes' => [
                    'created' => $prodCreated,
                ],
                'included' => [
                    [
                        'id' => $varId,
                        'sku' => $varSku,
                        'attributes' => [
                            'created' => $varCreated,
                        ]
                    ]
                ]
            ]
        ];

        $collection = KlaviyoConvert::products($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $product = $collection->first();
        $this->assertEquals($prodId, $product->platformId);
        $this->assertEquals($prodSku, $product->sku);
        $this->assertNull($product->vendor);

        $this->assertInstanceOf(ArrayCollection::class, $product->variants);
        $this->assertCount(1, $product->variants);

        $variant = $product->variants->first();
        $this->assertEquals($varId, $variant->platformId);
        $this->assertEquals($varSku, $variant->sku);
    }

    public function testProductCategoriesConvertsDataCorrectly(): void
    {
        $catId = 'cat_' . $this->faker->uuid;
        $createdAt = $this->faker->iso8601;

        $rows = [
            [
                'id' => $catId,
                'attributes' => [
                    'created' => $createdAt,
                ]
            ]
        ];

        $collection = KlaviyoConvert::productCategories($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $category = $collection->first();
        $this->assertEquals($catId, $category->platformId);
        $this->assertInstanceOf(Carbon::class, $category->platformCreatedAt);
    }

    public function testMetricAggregatesConvertsDataCorrectlyWithoutLiveApi(): void
    {
        $date = $this->faker->iso8601;
        $count = $this->faker->numberBetween(1, 100);
        $country = $this->faker->countryCode;
        $metricId = 'met_' . $this->faker->uuid;
        $metricName = $this->faker->words(3, true);

        $aggregates = [
            'dates' => [$date],
            'data' => [
                [
                    'measurements' => ['count' => $count],
                    'dimensions' => ['country' => $country]
                ]
            ]
        ];

        // We provide a metric names map to bypass the Redis/Live API fallback inside the metricAggregates function
        $mockMetricNamesMap = [
            $metricId => $metricName
        ];

        $collection = KlaviyoConvert::metricAggregates($aggregates, $metricId, $mockMetricNamesMap);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $metric = $collection->first();
        $this->assertEquals($metricId, $metric->platformId);
        $this->assertEquals($metricName, $metric->name);
        $this->assertEquals(Channel::klaviyo->value, $metric->channel);
        $this->assertEquals($count, $metric->value);
        $this->assertEquals($country, $metric->data['country']);
    }
}
