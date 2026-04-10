<?php

namespace Tests\Unit\Classes\Requests;

use Classes\Requests\DiscountRequests;
use Doctrine\Common\Collections\ArrayCollection;
use Anibalealvarezs\ApiSkeleton\Enums\Channel;
use Tests\Unit\BaseUnitTestCase;

class DiscountRequestsTest extends BaseUnitTestCase
{

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
