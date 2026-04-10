<?php

namespace Tests\Unit\Classes\Requests;

use Classes\Requests\ProductVariantRequests;
use Doctrine\Common\Collections\ArrayCollection;
use Anibalealvarezs\ApiDriverCore\Enums\Channel;
use Tests\Unit\BaseUnitTestCase;

class ProductVariantRequestsTest extends BaseUnitTestCase
{

    public function testGetListFromShopify(): void
    {
        $response = ProductVariantRequests::getListFromShopify();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Product variants are retrieved along with Products.', $response->getContent());
    }

    public function testGetListFromBigCommerce(): void
    {
        $response = ProductVariantRequests::getListFromBigCommerce();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('[]', $response->getContent());
    }

    public function testGetListFromNetsuite(): void
    {
        $response = ProductVariantRequests::getListFromNetsuite();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('[]', $response->getContent());
    }

    public function testGetListFromAmazon(): void
    {
        $response = ProductVariantRequests::getListFromAmazon();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('[]', $response->getContent());
    }

    public function testProcess(): void
    {
        $collection = new ArrayCollection([]);
        $response = ProductVariantRequests::process($collection);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Variants processed', $response->getContent());
    }
}
