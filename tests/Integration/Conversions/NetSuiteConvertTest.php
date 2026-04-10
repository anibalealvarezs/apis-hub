<?php

namespace Tests\Integration\Conversions;

use Anibalealvarezs\NetSuiteHubDriver\Conversions\NetSuiteConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Anibalealvarezs\ApiSkeleton\Enums\Channel;
use Tests\Integration\BaseIntegrationTestCase;
use Carbon\Carbon;

class NetSuiteConvertTest extends BaseIntegrationTestCase
{
    public function testCustomersConvertsDataCorrectly(): void
    {
        $platformId = 'cust_' . $this->faker->numerify('###');
        $email = $this->faker->safeEmail;
        $createdAt = $this->faker->iso8601;
        $addr1 = $this->faker->streetAddress;
        $city1 = $this->faker->city;
        $addr2 = $this->faker->streetAddress;
        $city2 = $this->faker->city;

        $rows = [
            [
                'entityid' => $platformId,
                'email' => $email,
                'datecreated' => $createdAt,
                'addressaddr1' => $addr1,
                'addresscity' => $city1
            ],
            [
                'entityid' => $platformId,
                'email' => $email,
                'datecreated' => $createdAt,
                'addressaddr1' => $addr2,
                'addresscity' => $city2
            ]
        ];

        $collection = NetSuiteConvert::customers($rows);

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertCount(1, $collection);

        $customer = $collection->first();
        $this->assertEquals($platformId, $customer->platformId);
        $this->assertEquals($email, $customer->email);
        $this->assertCount(2, $customer->data['addresses']);
        $this->assertEquals($addr1, $customer->data['addresses'][0]['addressaddr1']);
        $this->assertEquals($addr2, $customer->data['addresses'][1]['addressaddr1']);
    }

    public function testDiscountsConvertsDataCorrectly(): void
    {
        $platformId = 'disc_' . $this->faker->uuid;
        $code = strtoupper($this->faker->bothify('SAVE##'));
        $rows = [
            [
                'id' => $platformId,
                'created_at' => $this->faker->iso8601,
                'code' => $code
            ]
        ];

        $collection = NetSuiteConvert::discounts($rows);

        $this->assertCount(1, $collection);
        $discount = $collection->first();
        $this->assertEquals($platformId, $discount->platformId);
        $this->assertEquals($code, $discount->code);
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
        $parentId = 'parent_' . $this->faker->uuid;
        $parentSku = 'SKU-P-' . $this->faker->bothify('??-###');
        $childId = 'child_' . $this->faker->uuid;
        $childSku = 'SKU-C-' . $this->faker->bothify('??-###');
        $vendor = $this->faker->company;

        $rows = [
            [
                'id' => $parentId,
                'itemid' => $parentSku,
                'createddate' => $this->faker->iso8601,
                'itemtype' => 'NonInvtPart',
                'custitem_design_code' => $this->faker->word,
                'vendorname' => $vendor
            ],
            [
                'id' => $childId,
                'itemid' => $childSku,
                'createddate' => $this->faker->iso8601,
                'itemtype' => 'Assembly',
                'custitem_web_store_design_item' => $parentId,
                'parent' => $this->faker->uuid,
                'custitem_design_code' => $this->faker->word
            ]
        ];

        $collection = NetSuiteConvert::products($rows);

        $this->assertCount(1, $collection);
        $product = $collection->first();
        $this->assertEquals($parentId, $product->platformId);
        $this->assertEquals($parentSku, $product->sku);
        $this->assertEquals($vendor, $product->vendor->name);
        
        $this->assertCount(1, $product->variants);
        $variant = $product->variants->first();
        $this->assertEquals($childId, $variant->platformId);
        $this->assertEquals($childSku, $variant->sku);
    }

    public function testOrderProcessingConvertsDataCorrectly(): void
    {
        $platformId = 'order_' . $this->faker->numerify('#####');
        $email = $this->faker->safeEmail;
        $customerId = 'cust_' . $this->faker->word;
        $promo = strtoupper($this->faker->word);
        $created = $this->faker->iso8601;
        $total = $this->faker->randomFloat(2, 100, 1000);
        $tax = $this->faker->randomFloat(2, 5, 50);
        $discount = $this->faker->randomFloat(2, 5, 20);

        // NetSuite orders are rows from a single saved search, often repeating order headers for each line item
        $rows = [
            [
                'id' => $platformId,
                'createddate' => $created,
                'entity' => $customerId,
                'customeremail' => $email,
                'foreigntotal' => (string) $total,
                'promotioncodename' => $promo,
                'transactionlineid' => '1',
                'transactionlineforeignamount' => (string) (-$tax),
                'transactionlineitemtype' => 'TaxItem'
            ],
            [
                'id' => $platformId,
                'createddate' => $created,
                'entity' => $customerId,
                'customeremail' => $email,
                'foreigntotal' => (string) $total,
                'promotioncodename' => $promo,
                'transactionlineid' => '2',
                'transactionlineforeignamount' => (string) ($total / 2),
                'transactionlineitemtype' => 'NonInvtPart',
                'itemid' => $this->faker->uuid,
                'itemsku' => 'SKU-' . $this->faker->word
            ],
            [
                'id' => $platformId,
                'createddate' => $created,
                'entity' => $customerId,
                'customeremail' => $email,
                'foreigntotal' => (string) $total,
                'promotioncodename' => $promo,
                'transactionlineid' => '3',
                'transactionlineforeignamount' => (string) (-$discount),
                'transactionlineitemtype' => 'Discount'
            ]
        ];

        $collection = NetSuiteConvert::orders($rows);

        $this->assertCount(1, $collection);
        $order = $collection->first();
        $this->assertEquals($platformId, $order->platformId);
        $this->assertEquals($customerId, $order->customer->id);
        $this->assertEquals($email, $order->customer->email);
        $this->assertContains($promo, $order->discountCodes);
        
        // Assert sums
        $this->assertEquals($tax, $order->data['taxTotal']);
        $this->assertEquals(-$discount, $order->data['discountTotal']);
        
        $subAfter = $total - $tax; 
        $this->assertEquals($subAfter, $order->data['subtotalAfterDiscounts']);
        $this->assertEquals($subAfter - $discount, $order->data['subtotalBeforeDiscounts']);
    }

    public function testVendorsConvertsDataCorrectly(): void
    {
        $platformId = 'vend_' . $this->faker->uuid;
        $name = $this->faker->company;
        $rows = [
            [
                'id' => $platformId,
                'name' => $name,
                'created' => $this->faker->iso8601,
                'data' => ['some' => 'info']
            ]
        ];

        $collection = NetSuiteConvert::vendors($rows);

        $this->assertCount(1, $collection);
        $vendor = $collection->first();
        $this->assertEquals($platformId, $vendor->platformId);
        $this->assertEquals($name, $vendor->name);
    }

    public function testRobustness(): void
    {
        // 1. Customer with missing entityid (should be skipped)
        $rows = [['email' => 'test@example.com']];
        $result = NetSuiteConvert::customers($rows);
        $this->assertCount(0, $result);

        // 2. Order without id (should be skipped)
        $rows = [['entity' => 'cust123']];
        $result = NetSuiteConvert::orders($rows);
        $this->assertCount(0, $result);

        // 3. Product with missing itemid (SKU)
        $rows = [['id' => 'p1', 'itemtype' => 'NonInvtPart']];
        $result = NetSuiteConvert::products($rows);
        $this->assertEquals('', $result->first()->sku);
    }
}
