<?php

namespace Tests\Unit\Core\Drivers;

use PHPUnit\Framework\TestCase;
use Core\Drivers\DriverFactory;
use Psr\Log\LoggerInterface;

class DriverFactoryTest extends TestCase
{
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
            ['google_search_console', \Channels\Google\SearchConsole\SearchConsoleDriver::class],
            ['google_analytics', \Channels\Google\Analytics\GoogleAnalyticsDriver::class],
            ['facebook_marketing', \Channels\Meta\Marketing\FacebookMarketingDriver::class],
            ['facebook_organic', \Channels\Meta\Organic\FacebookOrganicDriver::class],
            ['shopify', \Channels\Shopify\ShopifyDriver::class],
            ['klaviyo', \Channels\Klaviyo\KlaviyoDriver::class],
            ['netsuite', \Channels\NetSuite\NetSuiteDriver::class],
            ['amazon', \Channels\Amazon\AmazonDriver::class],
            ['bigcommerce', \Channels\BigCommerce\BigCommerceDriver::class],
            ['pinterest', \Channels\Pinterest\PinterestDriver::class],
            ['linkedin', \Channels\LinkedIn\LinkedInDriver::class],
            ['x', \Channels\X\XDriver::class],
            ['tiktok', \Channels\TikTok\TikTokDriver::class],
        ];
    }

    public function test_it_throws_exception_for_unknown_driver()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Driver not found for channel: unknown");

        DriverFactory::get('unknown');
    }
}
