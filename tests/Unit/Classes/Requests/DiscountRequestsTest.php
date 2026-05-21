<?php

    namespace Tests\Unit\Classes\Requests;

    use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
    use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
    use Classes\Requests\DiscountRequests;
    use Doctrine\Common\Collections\ArrayCollection;
    use Exception;
    use Symfony\Component\HttpFoundation\Response;
    use Tests\Unit\BaseUnitTestCase;
    use Anibalealvarezs\ApiDriverCore\Interfaces\AuthProviderInterface;
    use Anibalealvarezs\ShopifyHubDriver\Auth\ShopifyAuthProvider;
    use Anibalealvarezs\NetSuiteHubDriver\Auth\NetSuiteAuthProvider;
    use Throwable;

    class DiscountRequestsTest extends BaseUnitTestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $mockDriver = $this->createMock(SyncDriverInterface::class);
            $mockDriver->method('sync')->willReturn(new Response('[]', 200));

            $authProvider = $this->createMock(AuthProviderInterface::class);
            $authProvider->method('getAccessToken')->willReturn('test-token');
            $authProvider->method('hasCredentials')->willReturn(true);

            $shopifyAuthProvider = $this->createMock(ShopifyAuthProvider::class);
            $shopifyAuthProvider->method('getAccessToken')->willReturn('test-shopify-token');
            $shopifyAuthProvider->method('getShopName')->willReturn('test-shop');
            $shopifyAuthProvider->method('getVersion')->willReturn('2024-04');
            $shopifyAuthProvider->method('hasCredentials')->willReturn(true);

            $netsuiteAuthProvider = $this->createMock(NetSuiteAuthProvider::class);
            $netsuiteAuthProvider->method('getCredentials')->willReturn([
                'consumer_id'     => 'test-consumer-id',
                'consumer_secret' => 'test-consumer-secret',
                'token_id'        => 'test-token-id',
                'token_secret'    => 'test-token-secret',
                'account_id'      => 'test-account-id',
                'host'            => 'test-host',
            ]);
            $netsuiteAuthProvider->method('hasCredentials')->willReturn(true);

            $mockDriver->method('getAuthProvider')->willReturnOnConsecutiveCalls(
                $shopifyAuthProvider,
                $authProvider,
                $netsuiteAuthProvider,
                $authProvider
            );

            DriverFactory::setInstance('shopify', $mockDriver);
            DriverFactory::setInstance('bigcommerce', $mockDriver);
            DriverFactory::setInstance('netsuite', $mockDriver);
            DriverFactory::setInstance('amazon', $mockDriver);
        }

        /**
         * @throws Exception|Throwable
         */
        public function testGetListFromShopify(): void
        {
            $this->markTestIncomplete('Shopify driver is not ready yet.');
            $channel = $this->getChannelEntity('shopify');
            $response = DiscountRequests::getList($channel);
            $this->assertEquals(200, $response->getStatusCode());
        }

        /**
         * @throws Exception|Throwable
         */
        public function testGetListFromBigCommerce(): void
        {
            $channel = $this->getChannelEntity('bigcommerce');
            $response = DiscountRequests::getList($channel);
            $this->assertEquals(200, $response->getStatusCode());
        }

        /**
         * @throws Exception|Throwable
         */
        public function testGetListFromNetsuite(): void
        {
            $this->markTestIncomplete('NetSuite driver is not ready yet.');
            $channel = $this->getChannelEntity('netsuite');
            $response = DiscountRequests::getList($channel);
            $this->assertEquals(200, $response->getStatusCode());
        }

        /**
         * @throws Exception|Throwable
         */
        public function testGetListFromAmazon(): void
        {
            $channel = $this->getChannelEntity('amazon');
            $response = DiscountRequests::getList($channel);
            $this->assertEquals(200, $response->getStatusCode());
        }

        public function testProcess(): void
        {
            $collection = new ArrayCollection([]);
            $response = DiscountRequests::process($collection);
            $this->assertEquals(200, $response->getStatusCode());
            $this->assertStringContainsString('Discounts processed', $response->getContent());
        }
    }