<?php

namespace Tests\Integration\Conversions;

use Classes\Conversions\ShopifyConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use Tests\Integration\BaseIntegrationTestCase;
use Carbon\Carbon;

class ShopifyConvertTest extends BaseIntegrationTestCase
{
    public function testCustomersConvertsDataCorrectly(): void
    {
        $platformId = $this->faker->numberBetween(1000, 99999);
        $email = $this->faker->safeEmail;
        $createdAt = $this->faker->iso8601;

        $rows = [
            [
                'id' => $platformId,
                'created_at' => $createdAt,
                'email' => $email
            ]
        ];

        $collection = ShopifyConvert::customers($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $customer = $collection->first();
        $this->assertEquals($platformId, $customer->platformId);
        $this->assertEquals(Channel::shopify->value, $customer->channel);
        $this->assertEquals($email, $customer->email);
        $this->assertInstanceOf(Carbon::class, $customer->platformCreatedAt);
    }

    public function testDiscountsConvertsDataCorrectly(): void
    {
        $platformId = $this->faker->numberBetween(1000, 99999);
        $code = strtoupper($this->faker->word . $this->faker->year);
        $rows = [
            [
                'id' => $platformId,
                'created_at' => $this->faker->iso8601,
                'code' => $code
            ]
        ];

        $collection = ShopifyConvert::discounts($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $discount = $collection->first();
        $this->assertEquals($platformId, $discount->platformId);
        $this->assertEquals($code, $discount->code);
    }

    public function testPriceRulesConvertsDataCorrectly(): void
    {
        $platformId = $this->faker->numberBetween(1000, 9999);
        $type = $this->faker->randomElement(['percentage', 'fixed_amount']);
        $rows = [
            [
                'id' => $platformId,
                'created_at' => $this->faker->iso8601,
                'value_type' => $type
            ]
        ];

        $collection = ShopifyConvert::priceRules($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $priceRule = $collection->first();
        $this->assertEquals($platformId, $priceRule->platformId);
        $this->assertEquals($type, $priceRule->data['value_type']);
    }

    public function testOrdersConvertsDataCorrectly(): void
    {
        $orderId = $this->faker->numberBetween(1000, 9999);
        $customerId = $this->faker->numberBetween(1000, 99999);
        $code = strtoupper($this->faker->word);
        $lineId = $this->faker->numberBetween(1, 100);

        $rows = [
            [
                'id' => $orderId,
                'created_at' => $this->faker->iso8601,
                'customer' => ['id' => $customerId],
                'discount_codes' => [['code' => $code]],
                'line_items' => [['id' => $lineId]]
            ]
        ];

        $collection = ShopifyConvert::orders($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $order = $collection->first();
        $this->assertEquals($orderId, $order->platformId);
        $this->assertEquals($customerId, $order->customer->id);
        $this->assertEquals($code, $order->discountCodes[0]);
        $this->assertEquals($lineId, $order->lineItems[0]['id']);
    }

    public function testProductsAndVariantsConvertsDataCorrectly(): void
    {
        $prodId = $this->faker->numberBetween(1000, 9999);
        $prodSku = 'SKU-' . $this->faker->bothify('??-###');
        $vendor = $this->faker->company;
        $varId = $this->faker->numberBetween(1000, 9999);
        $varSku = $prodSku . '-V1';

        $rows = [
            [
                'id' => $prodId,
                'created_at' => $this->faker->iso8601,
                'sku' => $prodSku,
                'vendor' => $vendor,
                'variants' => [
                    [
                        'id' => $varId,
                        'created_at' => $this->faker->iso8601,
                        'sku' => $varSku,
                    ]
                ]
            ]
        ];

        $collection = ShopifyConvert::products($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $product = $collection->first();
        $this->assertEquals($prodId, $product->platformId);
        $this->assertEquals($prodSku, $product->sku);
        $this->assertEquals($vendor, $product->vendor);

        $this->assertInstanceOf(ArrayCollection::class, $product->variants);
        $this->assertCount(1, $product->variants);

        $variant = $product->variants->first();
        $this->assertEquals($varId, $variant->platformId);
        $this->assertEquals($varSku, $variant->sku);
    }

    public function testProductCategoriesConvertsDataCorrectly(): void
    {
        $catId = $this->faker->numberBetween(1000, 9999);
        $rows = [
            [
                'id' => $catId,
                'published_at' => $this->faker->iso8601,
            ]
        ];

        $collection = ShopifyConvert::productCategories($rows, true);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $category = $collection->first();
        $this->assertEquals($catId, $category->platformId);
        $this->assertTrue($category->isSmartCollection);
    }

    public function testCollectsConvertsDataCorrectly(): void
    {
        $catId = $this->faker->numberBetween(1000, 9999);
        $prodId1 = $this->faker->numberBetween(1000, 9999);
        $prodId2 = $this->faker->numberBetween(1000, 9999);

        $rows = [
            [
                'collection_id' => $catId,
                'product_id' => $prodId1
            ],
            [
                'collection_id' => $catId,
                'product_id' => $prodId2
            ]
        ];

        $collection = ShopifyConvert::collects($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        // The resulting collection groups products by collection_id
        $this->assertCount(1, $collection);
        
        $productsInCollection = $collection->get($catId);
        $this->assertCount(2, $productsInCollection);
        $this->assertEquals($prodId1, $productsInCollection[0]);
        $this->assertEquals($prodId2, $productsInCollection[1]);
    }

    public function testRobustness(): void
    {
        // 1. Order without customer
        $rows = [['id' => 123, 'created_at' => $this->faker->iso8601]];
        $result = ShopifyConvert::orders($rows);
        $this->assertNull($result->first()->customer);

        // 2. Product without variants
        $rows = [['id' => 456, 'sku' => 'TEST']];
        $result = ShopifyConvert::products($rows);
        $this->assertCount(0, $result->first()->variants);
    }
}
