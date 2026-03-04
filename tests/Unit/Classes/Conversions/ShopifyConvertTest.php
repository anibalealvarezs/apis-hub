<?php

namespace Tests\Unit\Classes\Conversions;

use Classes\Conversions\ShopifyConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use PHPUnit\Framework\TestCase;

class ShopifyConvertTest extends TestCase
{
    public function testCustomers(): void
    {
        $input = [
            [
                'id' => 123,
                'created_at' => '2026-03-03T21:00:00-04:00',
                'email' => 'test@example.com'
            ]
        ];

        $result = ShopifyConvert::customers($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $customer = $result->first();
        $this->assertEquals(123, $customer->platformId);
        $this->assertEquals('test@example.com', $customer->email);
        $this->assertEquals(Channel::shopify->value, $customer->channel);
        $this->assertEquals('2026-03-04 01:00:00', $customer->platformCreatedAt->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertEquals($input[0], $customer->data);
    }

    public function testDiscounts(): void
    {
        $input = [
            [
                'id' => 456,
                'created_at' => '2026-03-03T21:00:00-04:00',
                'code' => 'DISC10'
            ]
        ];

        $result = ShopifyConvert::discounts($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $discount = $result->first();
        $this->assertEquals(456, $discount->platformId);
        $this->assertEquals('DISC10', $discount->code);
        $this->assertEquals(Channel::shopify->value, $discount->channel);
        $this->assertEquals('2026-03-04 01:00:00', $discount->platformCreatedAt->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertEquals($input[0], $discount->data);
    }

    public function testPriceRules(): void
    {
        $input = [
            [
                'id' => 789,
                'created_at' => '2026-03-03T21:00:00-04:00'
            ]
        ];

        $result = ShopifyConvert::priceRules($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $priceRule = $result->first();
        $this->assertEquals(789, $priceRule->platformId);
        $this->assertEquals(Channel::shopify->value, $priceRule->channel);
        $this->assertEquals('2026-03-04 01:00:00', $priceRule->platformCreatedAt->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertEquals($input[0], $priceRule->data);
    }

    public function testOrders(): void
    {
        $input = [
            [
                'id' => 101,
                'created_at' => '2026-03-03T21:00:00-04:00',
                'customer' => ['id' => 123],
                'discount_codes' => [
                    ['code' => 'DISC10']
                ],
                'line_items' => [
                    ['id' => 202]
                ]
            ]
        ];

        $result = ShopifyConvert::orders($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $order = $result->first();
        $this->assertEquals(101, $order->platformId);
        $this->assertEquals(Channel::shopify->value, $order->channel);
        $this->assertEquals('2026-03-04 01:00:00', $order->platformCreatedAt->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertEquals($input[0], $order->data);
        $this->assertEquals((object) ['id' => 123], $order->customer);
        $this->assertEquals(['DISC10'], $order->discountCodes);
        $this->assertEquals([['id' => 202]], $order->lineItems);
    }

    public function testProducts(): void
    {
        $input = [
            [
                'id' => 303,
                'sku' => 'PROD-SKU',
                'created_at' => '2026-03-03T21:00:00-04:00',
                'vendor' => 'Test Vendor',
                'variants' => [
                    [
                        'id' => 404,
                        'sku' => 'VAR-SKU',
                        'created_at' => '2026-03-03T21:00:00-04:00'
                    ]
                ]
            ]
        ];

        $result = ShopifyConvert::products($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $product = $result->first();
        $this->assertEquals(303, $product->platformId);
        $this->assertEquals('PROD-SKU', $product->sku);
        $this->assertEquals('Test Vendor', $product->vendor);
        $this->assertEquals(Channel::shopify->value, $product->channel);
        $this->assertEquals('2026-03-04 01:00:00', $product->platformCreatedAt->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertEquals($input[0], $product->data);
        
        $this->assertInstanceOf(ArrayCollection::class, $product->variants);
        $this->assertCount(1, $product->variants);
        $this->assertEquals(404, $product->variants->first()->platformId);
    }

    public function testProductVariants(): void
    {
        $input = [
            [
                'id' => 404,
                'sku' => 'VAR-SKU',
                'created_at' => '2026-03-03T21:00:00-04:00'
            ]
        ];

        $result = ShopifyConvert::productVariants($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $variant = $result->first();
        $this->assertEquals(404, $variant->platformId);
        $this->assertEquals('VAR-SKU', $variant->sku);
        $this->assertEquals(Channel::shopify->value, $variant->channel);
        $this->assertEquals('2026-03-04 01:00:00', $variant->platformCreatedAt->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertEquals($input[0], $variant->data);
    }

    public function testProductCategories(): void
    {
        $input = [
            [
                'id' => 505,
                'published_at' => '2026-03-03T21:00:00-04:00'
            ]
        ];

        $result = ShopifyConvert::productCategories($input, true);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $category = $result->first();
        $this->assertEquals(505, $category->platformId);
        $this->assertTrue($category->isSmartCollection);
        $this->assertEquals(Channel::shopify->value, $category->channel);
        $this->assertEquals('2026-03-04 01:00:00', $category->platformCreatedAt->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertEquals($input[0], $category->data);
    }

    public function testCollects(): void
    {
        $input = [
            ['collection_id' => 1, 'product_id' => 10],
            ['collection_id' => 1, 'product_id' => 20],
            ['collection_id' => 2, 'product_id' => 30],
        ];

        $result = ShopifyConvert::collects($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(2, $result);
        $this->assertEquals([10, 20], $result->get(1));
        $this->assertEquals([30], $result->get(2));
    }
}
