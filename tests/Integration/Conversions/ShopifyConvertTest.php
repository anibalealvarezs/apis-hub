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
        $rows = [
            [
                'id' => 12345,
                'created_at' => '2026-03-05T12:00:00Z',
                'email' => 'test@example.com'
            ]
        ];

        $collection = ShopifyConvert::customers($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $customer = $collection->first();
        $this->assertEquals(12345, $customer->platformId);
        $this->assertEquals(Channel::shopify->value, $customer->channel);
        $this->assertEquals('test@example.com', $customer->email);
        $this->assertInstanceOf(Carbon::class, $customer->platformCreatedAt);
    }

    public function testDiscountsConvertsDataCorrectly(): void
    {
        $rows = [
            [
                'id' => 98765,
                'created_at' => '2026-03-05T12:00:00Z',
                'code' => 'SUMMER2026'
            ]
        ];

        $collection = ShopifyConvert::discounts($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $discount = $collection->first();
        $this->assertEquals(98765, $discount->platformId);
        $this->assertEquals('SUMMER2026', $discount->code);
    }

    public function testPriceRulesConvertsDataCorrectly(): void
    {
        $rows = [
            [
                'id' => 1111,
                'created_at' => '2026-03-05T12:00:00Z',
                'value_type' => 'percentage'
            ]
        ];

        $collection = ShopifyConvert::priceRules($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $priceRule = $collection->first();
        $this->assertEquals(1111, $priceRule->platformId);
        $this->assertEquals('percentage', $priceRule->data['value_type']);
    }

    public function testOrdersConvertsDataCorrectly(): void
    {
        $rows = [
            [
                'id' => 2222,
                'created_at' => '2026-03-05T12:00:00Z',
                'customer' => ['id' => 12345],
                'discount_codes' => [['code' => 'SUMMER2026']],
                'line_items' => [['id' => 1]]
            ]
        ];

        $collection = ShopifyConvert::orders($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $order = $collection->first();
        $this->assertEquals(2222, $order->platformId);
        $this->assertEquals(12345, $order->customer->id);
        $this->assertEquals('SUMMER2026', $order->discountCodes[0]);
        $this->assertEquals(1, $order->lineItems[0]['id']);
    }

    public function testProductsAndVariantsConvertsDataCorrectly(): void
    {
        $rows = [
            [
                'id' => 3333,
                'created_at' => '2026-03-05T12:00:00Z',
                'sku' => 'PROD-1',
                'vendor' => 'TestVendor',
                'variants' => [
                    [
                        'id' => 4444,
                        'created_at' => '2026-03-05T12:00:00Z',
                        'sku' => 'PROD-1-VAR-1',
                    ]
                ]
            ]
        ];

        $collection = ShopifyConvert::products($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $product = $collection->first();
        $this->assertEquals(3333, $product->platformId);
        $this->assertEquals('PROD-1', $product->sku);
        $this->assertEquals('TestVendor', $product->vendor);

        $this->assertInstanceOf(ArrayCollection::class, $product->variants);
        $this->assertCount(1, $product->variants);

        $variant = $product->variants->first();
        $this->assertEquals(4444, $variant->platformId);
        $this->assertEquals('PROD-1-VAR-1', $variant->sku);
    }

    public function testProductCategoriesConvertsDataCorrectly(): void
    {
        $rows = [
            [
                'id' => 5555,
                'published_at' => '2026-03-05T12:00:00Z',
            ]
        ];

        $collection = ShopifyConvert::productCategories($rows, true);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $category = $collection->first();
        $this->assertEquals(5555, $category->platformId);
        $this->assertTrue($category->isSmartCollection);
    }

    public function testCollectsConvertsDataCorrectly(): void
    {
        $rows = [
            [
                'collection_id' => 5555,
                'product_id' => 3333
            ],
            [
                'collection_id' => 5555,
                'product_id' => 3334
            ]
        ];

        $collection = ShopifyConvert::collects($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        // The resulting collection groups products by collection_id
        $this->assertCount(1, $collection);
        
        $productsInCollection = $collection->get(5555);
        $this->assertCount(2, $productsInCollection);
        $this->assertEquals(3333, $productsInCollection[0]);
        $this->assertEquals(3334, $productsInCollection[1]);
    }
}
