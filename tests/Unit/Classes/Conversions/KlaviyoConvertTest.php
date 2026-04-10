<?php

namespace Tests\Unit\Classes\Conversions;

use Anibalealvarezs\KlaviyoApi\Conversions\KlaviyoConvert;
use Doctrine\Common\Collections\ArrayCollection;
use Anibalealvarezs\ApiDriverCore\Enums\Channel;
use Anibalealvarezs\ApiDriverCore\Enums\Period;
use Tests\Unit\BaseUnitTestCase;

class KlaviyoConvertTest extends BaseUnitTestCase
{
    public function testCustomers(): void
    {
        $id = $this->faker->uuid;
        $email = $this->faker->safeEmail;
        $createdAt = $this->faker->iso8601;

        $input = [
            [
                'id' => $id,
                'attributes' => [
                    'created' => $createdAt,
                    'email' => $email
                ]
            ]
        ];

        $result = KlaviyoConvert::customers($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $customer = $result->first();
        $this->assertEquals($id, $customer->platformId);
        $this->assertEquals($email, $customer->email);
        $this->assertEquals(Channel::klaviyo->value, $customer->channel);
        $this->assertEquals($input[0], $customer->data);
    }

    public function testProducts(): void
    {
        $prodId = 'prod_' . $this->faker->uuid;
        $prodSku = 'SKU-' . $this->faker->bothify('??-###');
        $prodCreated = $this->faker->iso8601;
        $varId = 'var_' . $this->faker->uuid;
        $varSku = 'VAR-' . $this->faker->bothify('??-###');
        $varCreated = $this->faker->iso8601;

        $input = [
            [
                'id' => $prodId,
                'sku' => $prodSku,
                'attributes' => [
                    'created' => $prodCreated
                ],
                'included' => [
                    [
                        'id' => $varId,
                        'sku' => $varSku,
                        'attributes' => [
                            'created' => $varCreated
                        ]
                    ]
                ]
            ]
        ];

        $result = KlaviyoConvert::products($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $product = $result->first();
        $this->assertEquals($prodId, $product->platformId);
        $this->assertEquals($prodSku, $product->sku);
        $this->assertEquals(Channel::klaviyo->value, $product->channel);
        $this->assertEquals($input[0], $product->data);
        $this->assertNull($product->vendor);
        $this->assertInstanceOf(ArrayCollection::class, $product->variants);
        $this->assertCount(1, $product->variants);
        $this->assertEquals($varId, $product->variants->first()->platformId);
    }

    public function testProductVariants(): void
    {
        $id = 'var_' . $this->faker->uuid;
        $sku = 'VAR-' . $this->faker->bothify('??-###');
        $createdAt = $this->faker->iso8601;

        $input = [
            [
                'id' => $id,
                'sku' => $sku,
                'attributes' => [
                    'created' => $createdAt
                ]
            ]
        ];

        $result = KlaviyoConvert::productVariants($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $variant = $result->first();
        $this->assertEquals($id, $variant->platformId);
        $this->assertEquals($sku, $variant->sku);
        $this->assertEquals(Channel::klaviyo->value, $variant->channel);
        $this->assertEquals($input[0], $variant->data);
    }

    public function testProductCategories(): void
    {
        $id = 'cat_' . $this->faker->uuid;
        $createdAt = $this->faker->iso8601;

        $input = [
            [
                'id' => $id,
                'attributes' => [
                    'created' => $createdAt
                ]
            ]
        ];

        $result = KlaviyoConvert::productCategories($input);

        $this->assertInstanceOf(ArrayCollection::class, $result);
        $this->assertCount(1, $result);
        $category = $result->first();
        $this->assertEquals($id, $category->platformId);
        $this->assertEquals(Channel::klaviyo->value, $category->channel);
        $this->assertEquals($input[0], $category->data);
    }

    public function testRobustness(): void
    {
        // 1. Customer without email or created date
        $rows = [
            [
                'id' => 'cust_123',
                'attributes' => [
                    // email and created are missing
                ]
            ]
        ];
        $result = KlaviyoConvert::customers($rows);
        $customer = $result->first();
        $this->assertEquals('', $customer->email);
        $this->assertInstanceOf(\DateTime::class, $customer->platformCreatedAt->toDateTime());

        // 2. Product without 'included' variants or 'created' attribute
        $rows = [
            [
                'id' => 'prod_456',
                'attributes' => [
                    // created is missing
                ]
                // included is missing
            ]
        ];
        $result = KlaviyoConvert::products($rows);
        $product = $result->first();
        $this->assertCount(0, $product->variants);
        $this->assertNull($product->platformCreatedAt);
    }
}
