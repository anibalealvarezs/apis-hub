<?php

namespace Tests\Integration\Conversions;

use Classes\Conversions\NetSuiteConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use Tests\Integration\BaseIntegrationTestCase;
use Carbon\Carbon;

class NetSuiteConvertTest extends BaseIntegrationTestCase
{
    public function testCustomersConvertsDataCorrectly(): void
    {
        $rows = [
            [
                'entityid' => 'cust_001',
                'email' => 'customer1@example.com',
                'datecreated' => '2026-03-05T12:00:00Z',
                'addressaddr1' => '123 Main St',
                'addresscity' => 'New York'
            ],
            [
                'entityid' => 'cust_001',
                'email' => 'customer1@example.com',
                'datecreated' => '2026-03-05T12:00:00Z',
                'addressaddr1' => '456 Side St',
                'addresscity' => 'Brooklyn'
            ]
        ];

        $collection = NetSuiteConvert::customers($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $customer = $collection->first();
        $this->assertEquals('cust_001', $customer->platformId);
        $this->assertEquals('customer1@example.com', $customer->email);
        $this->assertCount(2, $customer->data['addresses']);
        $this->assertEquals('123 Main St', $customer->data['addresses'][0]['addressaddr1']);
        $this->assertEquals('456 Side St', $customer->data['addresses'][1]['addressaddr1']);
    }

    public function testDiscountsConvertsDataCorrectly(): void
    {
        $rows = [
            [
                'id' => 'disc_123',
                'created_at' => '2026-03-05T12:00:00Z',
                'code' => 'SAVE10'
            ]
        ];

        $collection = NetSuiteConvert::discounts($rows);

        $this->assertCount(1, $collection);
        $discount = $collection->first();
        $this->assertEquals('disc_123', $discount->platformId);
        $this->assertEquals('SAVE10', $discount->code);
    }

    public function testPriceRulesConvertsDataCorrectly(): void
    {
        $rows = [
            [
                'id' => 'rule_123',
                'created_at' => '2026-03-05T12:00:00Z'
            ]
        ];

        $collection = NetSuiteConvert::priceRules($rows);

        $this->assertCount(1, $collection);
        $rule = $collection->first();
        $this->assertEquals('rule_123', $rule->platformId);
    }

    public function testProductsAndVariantsConvertsDataCorrectly(): void
    {
        $rows = [
            [
                'id' => 'parent_001',
                'itemid' => 'SKU-PARENT-001',
                'createddate' => '2026-03-05T12:00:00Z',
                'itemtype' => 'NonInvtPart',
                'custitem_design_code' => 'DESIGN-X',
                'vendorname' => 'Supplier A'
            ],
            [
                'id' => 'child_001',
                'itemid' => 'SKU-CHILD-001',
                'createddate' => '2026-03-05T12:05:00Z',
                'itemtype' => 'Assembly',
                'custitem_web_store_design_item' => 'parent_001',
                'parent' => 'some_internal_parent',
                'custitem_design_code' => 'DESIGN-X-C1'
            ]
        ];

        $collection = NetSuiteConvert::products($rows);

        $this->assertCount(1, $collection);
        $product = $collection->first();
        $this->assertEquals('parent_001', $product->platformId);
        $this->assertEquals('SKU-PARENT-001', $product->sku);
        $this->assertEquals('Supplier A', $product->vendor->name);
        
        $this->assertCount(1, $product->variants);
        $variant = $product->variants->first();
        $this->assertEquals('child_001', $variant->platformId);
        $this->assertEquals('SKU-CHILD-001', $variant->sku);
    }

    public function testOrderProcessingConvertsDataCorrectly(): void
    {
        // NetSuite orders are rows from a single saved search, often repeating order headers for each line item
        $rows = [
            [
                'id' => 'order_123',
                'createddate' => '2026-03-05T12:00:00Z',
                'entity' => 'customer_abc',
                'customeremail' => 'cust@example.com',
                'foreigntotal' => '100.00',
                'promotioncodename' => 'PROMO1',
                'transactionlineid' => '1',
                'transactionlineforeignamount' => '-10.00',
                'transactionlineitemtype' => 'TaxItem'
            ],
            [
                'id' => 'order_123',
                'createddate' => '2026-03-05T12:00:00Z',
                'entity' => 'customer_abc',
                'customeremail' => 'cust@example.com',
                'foreigntotal' => '100.00',
                'promotioncodename' => 'PROMO1',
                'transactionlineid' => '2',
                'transactionlineforeignamount' => '50.00',
                'transactionlineitemtype' => 'NonInvtPart',
                'itemid' => 'prod_xyz',
                'itemsku' => 'SKU-XYZ'
            ],
            [
                'id' => 'order_123',
                'createddate' => '2026-03-05T12:00:00Z',
                'entity' => 'customer_abc',
                'customeremail' => 'cust@example.com',
                'foreigntotal' => '100.00',
                'promotioncodename' => 'PROMO1',
                'transactionlineid' => '3',
                'transactionlineforeignamount' => '-5.00',
                'transactionlineitemtype' => 'Discount'
            ]
        ];

        $collection = NetSuiteConvert::orders($rows);

        $this->assertCount(1, $collection);
        $order = $collection->first();
        $this->assertEquals('order_123', $order->platformId);
        $this->assertEquals('customer_abc', $order->customer->id);
        $this->assertEquals('cust@example.com', $order->customer->email);
        $this->assertContains('PROMO1', $order->discountCodes);
        
        // Assert sums
        // Total = 100
        // Tax = 10
        // Discount = -5
        // Ship = 0
        $this->assertEquals(10.00, $order->data['taxTotal']);
        $this->assertEquals(-5.00, $order->data['discountTotal']);
        
        // subtotalAfterDiscounts = total(100) - tax(10) - ship(0) = 90
        $this->assertEquals(90.00, $order->data['subtotalAfterDiscounts']);
        // subtotalBeforeDiscounts = subafter(90) + discount(-5) = 85
        $this->assertEquals(85.00, $order->data['subtotalBeforeDiscounts']);
    }

    public function testVendorsConvertsDataCorrectly(): void
    {
        $rows = [
            [
                'id' => 'vend_123',
                'name' => 'Mega Corp',
                'created' => '2026-03-05T12:00:00Z',
                'data' => ['some' => 'info']
            ]
        ];

        $collection = NetSuiteConvert::vendors($rows);

        $this->assertCount(1, $collection);
        $vendor = $collection->first();
        $this->assertEquals('vend_123', $vendor->platformId);
        $this->assertEquals('Mega Corp', $vendor->name);
    }
}
