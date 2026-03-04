<?php

namespace Tests\Unit\Classes\Requests;

use Classes\Requests\DiscountRequests;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use PHPUnit\Framework\TestCase;

class DiscountRequestsTest extends TestCase
{
    public function testSupportedChannels(): void
    {
        $channels = DiscountRequests::supportedChannels();
        $this->assertIsArray($channels);
        $this->assertContains(Channel::shopify, $channels);
        $this->assertContains(Channel::klaviyo, $channels);
        $this->assertContains(Channel::bigcommerce, $channels);
        $this->assertContains(Channel::netsuite, $channels);
        $this->assertContains(Channel::amazon, $channels);
    }

    public function testGetListFromShopify(): void
    {
        $response = DiscountRequests::getListFromShopify();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Discounts are retrieved along with Price Rules.', $response->getContent());
    }

    public function testGetListFromBigCommerce(): void
    {
        $response = DiscountRequests::getListFromBigCommerce();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('[]', $response->getContent());
    }

    public function testGetListFromNetsuite(): void
    {
        $response = DiscountRequests::getListFromNetsuite();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('[]', $response->getContent());
    }

    public function testGetListFromAmazon(): void
    {
        $response = DiscountRequests::getListFromAmazon();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('[]', $response->getContent());
    }

    public function testProcess(): void
    {
        $collection = new ArrayCollection([]);
        $response = DiscountRequests::process($collection);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Discounts processed', $response->getContent());
    }
}
