<?php

namespace Tests\Unit\Classes\Requests;

use Classes\Requests\ProductVariantRequests;
use Doctrine\Common\Collections\ArrayCollection;
use Anibalealvarezs\ApiSkeleton\Enums\Channel;
use Tests\Unit\BaseUnitTestCase;

class ProductVariantRequestsTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $mockDriver = $this->createMock(\Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface::class);
        $mockDriver->method('sync')->willReturn(new \Symfony\Component\HttpFoundation\Response('[]', 200));
        \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::setInstance('shopify', $mockDriver);
        \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::setInstance('bigcommerce', $mockDriver);
        \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::setInstance('netsuite', $mockDriver);
        \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::setInstance('amazon', $mockDriver);
    }

    public function testGetListFromShopify(): void
    {
        $response = ProductVariantRequests::getList(Channel::shopify);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testGetListFromBigCommerce(): void
    {
        $response = ProductVariantRequests::getList(Channel::bigcommerce);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testGetListFromNetsuite(): void
    {
        $response = ProductVariantRequests::getList(Channel::netsuite);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testGetListFromAmazon(): void
    {
        $response = ProductVariantRequests::getList(Channel::amazon);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testProcess(): void
    {
        $collection = new ArrayCollection([]);
        $response = ProductVariantRequests::process($collection);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Variants processed', $response->getContent());
    }
}
