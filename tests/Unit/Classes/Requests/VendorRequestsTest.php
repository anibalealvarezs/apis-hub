<?php

namespace Tests\Unit\Classes\Requests;

use Classes\Requests\VendorRequests;
use Doctrine\Common\Collections\ArrayCollection;
use Anibalealvarezs\ApiSkeleton\Enums\Channel;
use Tests\Unit\BaseUnitTestCase;

class VendorRequestsTest extends BaseUnitTestCase
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
        $response = VendorRequests::getList(Channel::shopify);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testGetListFromBigCommerce(): void
    {
        $response = VendorRequests::getList(Channel::bigcommerce);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testGetListFromNetsuite(): void
    {
        $response = VendorRequests::getList(Channel::netsuite);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testGetListFromAmazon(): void
    {
        $response = VendorRequests::getList(Channel::amazon);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testProcess(): void
    {
        $collection = new ArrayCollection([]);
        $response = VendorRequests::process($collection);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Vendors processed', $response->getContent());
    }
}
