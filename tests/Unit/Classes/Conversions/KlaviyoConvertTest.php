<?php

namespace Tests\Unit\Classes\Conversions;

use Classes\Conversions\KlaviyoConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use Enums\Period;
use PHPUnit\Framework\TestCase;

class KlaviyoConvertTest extends TestCase
{
    public function testCustomers(): void
    {
        $input = [
            [
                'id' => '123',
                'attributes' => [
                    'created' => '2026-03-03T21:00:00Z',
                    'email' => 'test@example.com'
                ]
            ]
        ];

        $result = KlaviyoConvert::customers($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $customer = $result->first();
        $this->assertEquals('123', $customer->platformId);
        $this->assertEquals('test@example.com', $customer->email);
        $this->assertEquals(Channel::klaviyo->value, $customer->channel);
        $this->assertEquals('2026-03-03 21:00:00', $customer->platformCreatedAt->format('Y-m-d H:i:s'));
        $this->assertEquals($input[0], $customer->data);
    }

    public function testProducts(): void
    {
        $input = [
            [
                'id' => 'prod_123',
                'sku' => 'SKU-001',
                'attributes' => [
                    'created' => '2026-03-03T21:00:00Z'
                ],
                'included' => [
                    [
                        'id' => 'var_123',
                        'sku' => 'VAR-001',
                        'attributes' => [
                            'created' => '2026-03-03T21:00:00Z'
                        ]
                    ]
                ]
            ]
        ];

        $result = KlaviyoConvert::products($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $product = $result->first();
        $this->assertEquals('prod_123', $product->platformId);
        $this->assertEquals('SKU-001', $product->sku);
        $this->assertEquals(Channel::klaviyo->value, $product->channel);
        $this->assertEquals('2026-03-03 21:00:00', $product->platformCreatedAt->format('Y-m-d H:i:s'));
        $this->assertEquals($input[0], $product->data);
        $this->assertNull($product->vendor);
        $this->assertInstanceOf(ArrayCollection::class, $product->variants);
        $this->assertCount(1, $product->variants);
        $this->assertEquals('var_123', $product->variants->first()->platformId);
    }

    public function testProductVariants(): void
    {
        $input = [
            [
                'id' => 'var_123',
                'sku' => 'VAR-001',
                'attributes' => [
                    'created' => '2026-03-03T21:00:00Z'
                ]
            ]
        ];

        $result = KlaviyoConvert::productVariants($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $variant = $result->first();
        $this->assertEquals('var_123', $variant->platformId);
        $this->assertEquals('VAR-001', $variant->sku);
        $this->assertEquals(Channel::klaviyo->value, $variant->channel);
        $this->assertEquals('2026-03-03 21:00:00', $variant->platformCreatedAt->format('Y-m-d H:i:s'));
        $this->assertEquals($input[0], $variant->data);
    }

    public function testProductCategories(): void
    {
        $input = [
            [
                'id' => 'cat_123',
                'attributes' => [
                    'created' => '2026-03-03T21:00:00Z'
                ]
            ]
        ];

        $result = KlaviyoConvert::productCategories($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $category = $result->first();
        $this->assertEquals('cat_123', $category->platformId);
        $this->assertEquals(Channel::klaviyo->value, $category->channel);
        $this->assertEquals('2026-03-03 21:00:00', $category->platformCreatedAt->format('Y-m-d H:i:s'));
        $this->assertEquals($input[0], $category->data);
    }
}
