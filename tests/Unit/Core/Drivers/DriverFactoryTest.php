<?php

namespace Tests\Unit\Core\Drivers;

use PHPUnit\Framework\TestCase;
use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
use Psr\Log\LoggerInterface;

class DriverFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DriverFactory::reset();
    }

    /**
     * @dataProvider driverProvider
     */
    public function test_it_can_create_drivers(string $channel, string $expectedClass)
    {
        $logger = $this->createMock(LoggerInterface::class);
        $driver = DriverFactory::get($channel, $logger);

        $this->assertInstanceOf($expectedClass, $driver);
        $this->assertEquals($channel, $driver->getChannel());
    }

    public function driverProvider(): array
    {
        return [
            ['google_search_console', \Anibalealvarezs\GoogleHubDriver\Drivers\SearchConsoleDriver::class],
            ['google_analytics', \Anibalealvarezs\GoogleHubDriver\Drivers\GoogleAnalyticsDriver::class],
            ['facebook_marketing', \Anibalealvarezs\MetaHubDriver\Drivers\FacebookMarketingDriver::class],
            ['facebook_organic', \Anibalealvarezs\MetaHubDriver\Drivers\FacebookOrganicDriver::class],
            ['shopify', \Anibalealvarezs\ShopifyHubDriver\Drivers\ShopifyDriver::class],
            ['klaviyo', \Anibalealvarezs\KlaviyoHubDriver\Drivers\KlaviyoDriver::class],
            ['netsuite', \Anibalealvarezs\NetSuiteHubDriver\Drivers\NetSuiteDriver::class],
            ['amazon', \Anibalealvarezs\AmazonHubDriver\Drivers\AmazonDriver::class],
            ['bigcommerce', \Anibalealvarezs\BigCommerceHubDriver\Drivers\BigCommerceDriver::class],
            ['pinterest', \Anibalealvarezs\PinterestHubDriver\Drivers\PinterestDriver::class],
            ['linkedin', \Anibalealvarezs\LinkedInHubDriver\Drivers\LinkedInDriver::class],
            ['x', \Anibalealvarezs\XHubDriver\Drivers\XDriver::class],
            ['tiktok', \Anibalealvarezs\TikTokHubDriver\Drivers\TikTokDriver::class],
        ];
    }

    public function test_it_throws_exception_for_unknown_driver()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Driver not found for channel: unknown");

        DriverFactory::get('unknown');
    }
}
