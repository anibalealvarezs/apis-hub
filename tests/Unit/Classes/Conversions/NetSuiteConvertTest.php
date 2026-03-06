<?php

namespace Tests\Unit\Classes\Conversions;

use Classes\Conversions\NetSuiteConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use Tests\Unit\BaseUnitTestCase;

class NetSuiteConvertTest extends BaseUnitTestCase
{
    public function testCustomers(): void
    {
        $id = 'cust' . $this->faker->randomNumber();
        $email = $this->faker->safeEmail;
        $date = $this->faker->iso8601;
        $city = $this->faker->city;
        $country = $this->faker->countryCode;

        $input = [
            [
                'entityid' => $id,
                'email' => $email,
                'datecreated' => $date,
                'addresscity' => $city,
                'addresscountry' => $country,
            ]
        ];

        $result = NetSuiteConvert::customers($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $customer = $result->first();
        $this->assertEquals($id, $customer->platformId);
        $this->assertEquals($email, $customer->email);
        $this->assertEquals(Channel::netsuite->value, $customer->channel);
        $this->assertIsArray($customer->data['addresses']);
        $this->assertCount(1, $customer->data['addresses']);
        $this->assertEquals($city, $customer->data['addresses'][0]['addresscity']);
    }

    public function testDiscounts(): void
    {
        $id = 'disc' . $this->faker->randomNumber();
        $code = strtoupper($this->faker->word);

        $input = [
            [
                'id' => $id,
                'created_at' => $this->faker->iso8601,
                'code' => $code
            ]
        ];

        $result = NetSuiteConvert::discounts($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $discount = $result->first();
        $this->assertEquals($id, $discount->platformId);
        $this->assertEquals($code, $discount->code);
    }

    public function testPriceRules(): void
    {
        $id = 'rule' . $this->faker->randomNumber();
        $input = [
            [
                'id' => $id,
                'created_at' => $this->faker->iso8601
            ]
        ];

        $result = NetSuiteConvert::priceRules($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $priceRule = $result->first();
        $this->assertEquals($id, $priceRule->platformId);
    }

    public function testProducts(): void
    {
        $prodId = 'item' . $this->faker->randomNumber();
        $sku = 'SKU-' . $this->faker->word;
        $vendorName = $this->faker->company;
        $catId = 'cat' . $this->faker->randomNumber();
        $varId = 'item' . $this->faker->randomNumber();
        $varSku = 'VAR-' . $this->faker->word;

        $input = [
            [
                'id' => $prodId,
                'itemtype' => 'NonInvtPart',
                'itemid' => $sku,
                'createddate' => $this->faker->iso8601,
                'custitem_design_code' => 'DESIGN-' . $this->faker->randomNumber(),
                'vendorname' => $vendorName,
                'commercecategoryid' => $catId,
            ],
            [
                'id' => $varId,
                'itemtype' => 'Assembly',
                'parent' => $varId,
                'custitem_web_store_design_item' => $prodId,
                'itemid' => $varSku,
                'data' => [],
            ]
        ];

        $result = NetSuiteConvert::products($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $product = $result->first();
        $this->assertEquals($prodId, $product->platformId);
        $this->assertEquals($sku, $product->sku);
        $this->assertEquals($vendorName, $product->vendor->name);
        $this->assertInstanceOf(ArrayCollection::class, $product->variants);
        $this->assertCount(1, $product->variants);
        $this->assertEquals($varId, $product->variants->first()->platformId);
        $this->assertEquals($varSku, $product->variants->first()->sku);
        $this->assertInstanceOf(ArrayCollection::class, $product->categories);
        $this->assertCount(1, $product->categories);
        $this->assertEquals($catId, $product->categories->first()->platformId);
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
