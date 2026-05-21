<?php

    namespace Tests\Unit\Core\Services;

    use Anibalealvarezs\ShopifyHubDriver\Drivers\ShopifyDriver;
    use Tests\Unit\BaseUnitTestCase;
    use Core\Services\SyncService;
    use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
    use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
    use Symfony\Component\HttpFoundation\Response;
    use DateTime;

    class SyncServiceTest extends BaseUnitTestCase
    {
        private $mockDriver;

        protected function setUp(): void
        {
            parent::setUp();
            DriverFactory::reset(); // Reset DriverFactory before each test
            $this->mockDriver = $this->createMock(SyncDriverInterface::class);
            $this->mockDriver->method('getChannel')->willReturn('shopify');
        }

        public function testExecuteCallsDriverSyncWithNormalizedDates()
        {
            // Set the instance for the test
            DriverFactory::setInstance('shopify', $this->mockDriver);

            // Expect sync to be called with DateTime objects even if we pass strings
            // The config parameter will be whatever SyncService generates internally, as we pass an empty config to execute.
            $this->mockDriver->expects($this->once())
                ->method('sync')
                ->with(
                    $this->callback(fn($dt) => $dt instanceof DateTime && $dt->format('Y-m-d') === '2023-01-01'),
                    $this->callback(fn($dt) => $dt instanceof DateTime && $dt->format('Y-m-d') === '2023-01-10'),
                    $this->anything() // Expect any config, as the real config merging happens internally
                )
                ->willReturn(new Response(json_encode(['status' => 'success'])));

            // Run the service with an empty config to ensure the mock driver is used
            $service = new SyncService();
            $response = $service->execute('shopify', '2023-01-01', '2023-01-10', []);

            // Verify response
            $this->assertEquals(200, $response->getStatusCode());
            $this->assertStringContainsString('success', $response->getContent());
        }

        /**
         * @throws \Throwable
         */
        public function testExecuteHandlesEmptyDatesWithDefaults()
        {
            // Set the instance for the test
            DriverFactory::setInstance('shopify', $this->mockDriver);

            // We expect default dates (3 days ago and now)
            // The config parameter will be whatever SyncService generates internally, as we pass an empty config to execute.
            $this->mockDriver->expects($this->once())
                ->method('sync')
                ->with(
                    $this->callback(fn($dt) => $dt instanceof DateTime),
                    $this->callback(fn($dt) => $dt instanceof DateTime),
                    $this->anything() // Expect any config, as the real config merging happens internally
                )
                ->willReturn(new Response(json_encode(['status' => 'success'])));

            // Run the service with an empty config to ensure the mock driver is used
            $service = new SyncService();
            $service->execute('shopify', null, null, []);
        }
    }