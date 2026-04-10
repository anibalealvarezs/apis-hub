<?php

namespace Tests\Unit\Core\Services;

use Tests\Unit\BaseUnitTestCase;
use Core\Services\SyncService;
use Core\Drivers\DriverFactory;
use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use DateTime;

class SyncServiceTest extends BaseUnitTestCase
{
    private $mockDriver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockDriver = $this->createMock(SyncDriverInterface::class);
        $this->mockDriver->method('getChannel')->willReturn('test_channel');
    }

    public function testExecuteCallsDriverSyncWithNormalizedDates()
    {
        // 1. Force the factory to use our mock
        DriverFactory::setInstance('test_channel', $this->mockDriver);

        // 2. Expect sync to be called with DateTime objects even if we pass strings
        $this->mockDriver->expects($this->once())
            ->method('sync')
            ->with(
                $this->callback(fn($dt) => $dt instanceof DateTime && $dt->format('Y-m-d') === '2023-01-01'),
                $this->callback(fn($dt) => $dt instanceof DateTime && $dt->format('Y-m-d') === '2023-01-10'),
                $this->callback(fn($config) => $config['foo'] === 'bar')
            )
            ->willReturn(new Response(json_encode(['status' => 'success'])));

        // 3. Run the service
        $service = new SyncService();
        $response = $service->execute('test_channel', '2023-01-01', '2023-01-10', ['foo' => 'bar']);

        // 4. Verify response
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('success', $response->getContent());
    }

    public function testExecuteHandlesEmptyDatesWithDefaults()
    {
        DriverFactory::setInstance('test_channel', $this->mockDriver);

        // We expect default dates (3 days ago and now)
        $this->mockDriver->expects($this->once())
            ->method('sync')
            ->with(
                $this->callback(fn($dt) => $dt instanceof DateTime),
                $this->callback(fn($dt) => $dt instanceof DateTime),
                $this->anything()
            )
            ->willReturn(new Response(json_encode(['status' => 'success'])));

        $service = new SyncService();
        $service->execute('test_channel');
    }
}
