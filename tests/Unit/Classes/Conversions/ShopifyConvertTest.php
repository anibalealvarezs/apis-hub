<?php

namespace Tests\Unit\Classes\Conversions;

use Anibalealvarezs\ShopifyApi\Conversions\ShopifyConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use Tests\Unit\BaseUnitTestCase;

class ShopifyConvertTest extends BaseUnitTestCase
{
    public function testCustomers(): void
    {
        $id = $this->faker->numberBetween(1, 1000);
        $email = $this->faker->safeEmail;
        $createdAt = $this->faker->iso8601;

        $input = [
            [
                'id' => $id,
                'created_at' => $createdAt,
                'email' => $email
            ]
        ];

        $result = ShopifyConvert::customers($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $customer = $result->first();
        $this->assertEquals($id, $customer->platformId);
        $this->assertEquals($email, $customer->email);
        $this->assertEquals(Channel::shopify->value, $customer->channel);
        $this->assertEquals($input[0], $customer->data);
    }

    public function testDiscounts(): void
    {
        $id = $this->faker->numberBetween(1, 1000);
        $code = strtoupper($this->faker->word);

        $input = [
            [
                'id' => $id,
                'created_at' => $this->faker->iso8601,
                'code' => $code
            ]
        ];

        $result = ShopifyConvert::discounts($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $discount = $result->first();
        $this->assertEquals($id, $discount->platformId);
        $this->assertEquals($code, $discount->code);
        $this->assertEquals(Channel::shopify->value, $discount->channel);
        $this->assertEquals($input[0], $discount->data);
    }

    public function testPriceRules(): void
    {
        $id = $this->faker->numberBetween(1, 1000);
        $input = [
            [
                'id' => $id,
                'created_at' => $this->faker->iso8601
            ]
        ];

        $result = ShopifyConvert::priceRules($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $priceRule = $result->first();
        $this->assertEquals($id, $priceRule->platformId);
        $this->assertEquals(Channel::shopify->value, $priceRule->channel);
        $this->assertEquals($input[0], $priceRule->data);
    }

    public function testOrders(): void
    {
        $id = $this->faker->numberBetween(1, 1000);
        $custId = $this->faker->numberBetween(1, 1000);
        $code = strtoupper($this->faker->word);
        $lineId = $this->faker->numberBetween(1, 1000);

        $input = [
            [
                'id' => $id,
                'created_at' => $this->faker->iso8601,
                'customer' => ['id' => $custId],
                'discount_codes' => [
                    ['code' => $code]
                ],
                'line_items' => [
                    ['id' => $lineId]
                ]
            ]
        ];

        $result = ShopifyConvert::orders($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $order = $result->first();
        $this->assertEquals($id, $order->platformId);
        $this->assertEquals(Channel::shopify->value, $order->channel);
        $this->assertEquals($input[0], $order->data);
        $this->assertEquals((object) ['id' => $custId], $order->customer);
        $this->assertEquals([$code], $order->discountCodes);
        $this->assertEquals([['id' => $lineId]], $order->lineItems);
    }

    public function testProducts(): void
    {
        $prodId = $this->faker->numberBetween(1, 1000);
        $prodSku = 'SKU-' . $this->faker->unique()->word;
        $vendor = $this->faker->company;
        $varId = $this->faker->numberBetween(1000, 2000);
        $varSku = 'VAR-' . $this->faker->unique()->word;

        $input = [
            [
                'id' => $prodId,
                'sku' => $prodSku,
                'created_at' => $this->faker->iso8601,
                'vendor' => $vendor,
                'variants' => [
                    [
                        'id' => $varId,
                        'sku' => $varSku,
                        'created_at' => $this->faker->iso8601
                    ]
                ]
            ]
        ];

        $result = ShopifyConvert::products($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $product = $result->first();
        $this->assertEquals($prodId, $product->platformId);
        $this->assertEquals($prodSku, $product->sku);
        $this->assertEquals($vendor, $product->vendor);
        $this->assertEquals(Channel::shopify->value, $product->channel);
        $this->assertEquals($input[0], $product->data);

        $this->assertInstanceOf(ArrayCollection::class, $product->variants);
        $this->assertCount(1, $product->variants);
        $this->assertEquals($varId, $product->variants->first()->platformId);
    }

    public function testProductVariants(): void
    {
        $id = $this->faker->numberBetween(1, 1000);
        $sku = 'SKU-' . $this->faker->word;
        $createdAt = $this->faker->iso8601;

        $input = [
            [
                'id' => $id,
                'sku' => $sku,
                'created_at' => $createdAt
            ]
        ];

        $result = ShopifyConvert::productVariants($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $variant = $result->first();
        $this->assertEquals($id, $variant->platformId);
        $this->assertEquals($sku, $variant->sku);
        $this->assertEquals(Channel::shopify->value, $variant->channel);
        $this->assertEquals($input[0], $variant->data);
    }

    public function testProductCategories(): void
    {
        $id = $this->faker->numberBetween(1, 1000);
        $publishedAt = $this->faker->iso8601;

        $input = [
            [
                'id' => $id,
                'published_at' => $publishedAt
            ]
        ];

        $result = ShopifyConvert::productCategories($input, true);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $category = $result->first();
        $this->assertEquals($id, $category->platformId);
        $this->assertTrue($category->isSmartCollection);
        $this->assertEquals(Channel::shopify->value, $category->channel);
        $this->assertEquals($input[0], $category->data);
    }

    public function testCollects(): void
    {
        $cat1 = $this->faker->numberBetween(1, 100);
        $cat2 = $this->faker->numberBetween(101, 200);
        $prod1 = $this->faker->numberBetween(1, 100);
        $prod2 = $this->faker->numberBetween(101, 200);
        $prod3 = $this->faker->numberBetween(201, 300);

        $input = [
            ['collection_id' => $cat1, 'product_id' => $prod1],
            ['collection_id' => $cat1, 'product_id' => $prod2],
            ['collection_id' => $cat2, 'product_id' => $prod3],
        ];

        $result = ShopifyConvert::collects($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(2, $result);
        $this->assertEquals([$prod1, $prod2], $result->get($cat1));
        $this->assertEquals([$prod3], $result->get($cat2));
    }

    public function testRobustness(): void
    {
        // 1. Order without customer (POS/walk-in)
        $rows = [
            [
                'id' => 123,
                'created_at' => '2023-01-01T00:00:00Z',
                // 'customer' is missing
                'discount_codes' => [],
                'line_items' => []
            ]
        ];
        $result = ShopifyConvert::orders($rows);
        $this->assertNull($result->first()->customer);

        // 2. Product without variants or vendor
        $rows = [
            [
                'id' => 456,
                'created_at' => '2023-01-01T00:00:00Z',
                // 'vendor' and 'variants' are missing
            ]
        ];
        $result = ShopifyConvert::products($rows);
        $product = $result->first();
        $this->assertEquals('', $product->vendor);
        $this->assertCount(0, $product->variants);

        // 3. Customer without email
        $rows = [
            [
                'id' => 789,
                'created_at' => '2023-01-01T00:00:00Z',
                // 'email' is missing
            ]
        ];
        $result = ShopifyConvert::customers($rows);
        $this->assertEquals('', $result->first()->email);
    }
}
