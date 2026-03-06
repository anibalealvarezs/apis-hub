<?php

namespace Tests\Unit\Classes\Requests;

use Classes\Requests\VendorRequests;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use Tests\Unit\BaseUnitTestCase;

class VendorRequestsTest extends BaseUnitTestCase
{
    public function testSupportedChannels(): void
    {
        $channels = VendorRequests::supportedChannels();
        $this->assertIsArray($channels);
        $this->assertContains(Channel::shopify, $channels);
        $this->assertContains(Channel::bigcommerce, $channels);
        $this->assertContains(Channel::netsuite, $channels);
        $this->assertContains(Channel::amazon, $channels);
        $this->assertNotContains(Channel::klaviyo, $channels);
    }

    public function testGetListFromShopify(): void
    {
        $response = VendorRequests::getListFromShopify();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Vendors are not supported in Shopify', $response->getContent());
    }

    public function testGetListFromBigCommerce(): void
    {
        $response = VendorRequests::getListFromBigCommerce();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('[]', $response->getContent());
    }

    public function testGetListFromNetsuite(): void
    {
        $response = VendorRequests::getListFromNetsuite();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('[]', $response->getContent());
    }

    public function testGetListFromAmazon(): void
    {
        $response = VendorRequests::getListFromAmazon();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('[]', $response->getContent());
    }

    public function testProcess(): void
    {
        $collection = new ArrayCollection([]);
        $response = VendorRequests::process($collection);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Vendors processed', $response->getContent());
    }
}
