<?php

namespace Tests\Unit\Classes\Conversions;

use Classes\Conversions\NetSuiteConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use PHPUnit\Framework\TestCase;

class NetSuiteConvertTest extends TestCase
{
    public function testCustomers(): void
    {
        $input = [
            [
                'entityid' => 'cust123',
                'email' => 'test@example.com',
                'datecreated' => '2026-03-03T21:00:00Z',
                'addresscity' => 'New York',
                'addresscountry' => 'US',
            ]
        ];

        $result = NetSuiteConvert::customers($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $customer = $result->first();
        $this->assertEquals('cust123', $customer->platformId);
        $this->assertEquals('test@example.com', $customer->email);
        $this->assertEquals(Channel::netsuite->value, $customer->channel);
        $this->assertEquals('2026-03-03 21:00:00', $customer->platformCreatedAt->format('Y-m-d H:i:s'));
        $this->assertIsArray($customer->data['addresses']);
        $this->assertCount(1, $customer->data['addresses']);
        $this->assertEquals('New York', $customer->data['addresses'][0]['addresscity']);
    }

    public function testDiscounts(): void
    {
        $input = [
            [
                'id' => 'disc123',
                'created_at' => '2026-03-03T21:00:00Z',
                'code' => 'DISC10'
            ]
        ];

        $result = NetSuiteConvert::discounts($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $discount = $result->first();
        $this->assertEquals('disc123', $discount->platformId);
        $this->assertEquals('DISC10', $discount->code);
    }

    public function testPriceRules(): void
    {
        $input = [
            [
                'id' => 'rule123',
                'created_at' => '2026-03-03T21:00:00Z'
            ]
        ];

        $result = NetSuiteConvert::priceRules($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $priceRule = $result->first();
        $this->assertEquals('rule123', $priceRule->platformId);
    }

    public function testProducts(): void
    {
        $input = [
            [
                'id' => 'item123',
                'itemtype' => 'NonInvtPart',
                'itemid' => 'SKU-001',
                'createddate' => '2026-03-03T21:00:00Z',
                'custitem_design_code' => 'DESIGN-1',
                'vendorname' => 'Vendor A',
                'commercecategoryid' => 'cat123',
            ],
            [
                'id' => 'item124',
                'itemtype' => 'Assembly',
                'parent' => 'item124',
                'custitem_web_store_design_item' => 'item123',
                'itemid' => 'VAR-001',
                'data' => [],
            ]
        ];

        $result = NetSuiteConvert::products($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $product = $result->first();
        $this->assertEquals('item123', $product->platformId);
        $this->assertEquals('SKU-001', $product->sku);
        $this->assertEquals('Vendor A', $product->vendor->name);
        $this->assertInstanceOf(ArrayCollection::class, $product->variants);
        $this->assertCount(1, $product->variants);
        $this->assertEquals('item124', $product->variants->first()->platformId);
        $this->assertEquals('VAR-001', $product->variants->first()->sku);
        $this->assertInstanceOf(ArrayCollection::class, $product->categories);
        $this->assertCount(1, $product->categories);
        $this->assertEquals('cat123', $product->categories->first()->platformId);
    }

    public function testProductCategories(): void
    {
        $input = [
            [
                'id' => 'cat123',
                'created' => '2026-03-03T21:00:00Z',
                'data' => []
            ]
        ];

        $result = NetSuiteConvert::productCategories($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $category = $result->first();
        $this->assertEquals('cat123', $category->platformId);
    }

    public function testVendors(): void
    {
        $input = [
            [
                'id' => 'vendor123',
                'name' => 'Vendor A',
                'created' => '2026-03-03T21:00:00Z',
                'data' => []
            ]
        ];

        $result = NetSuiteConvert::vendors($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $vendor = $result->first();
        $this->assertEquals('vendor123', $vendor->platformId);
        $this->assertEquals('Vendor A', $vendor->name);
    }
}
