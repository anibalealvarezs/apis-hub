<?php

namespace Tests\Unit\Controllers;

use Controllers\AnalyticsController;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class AnalyticsControllerTest extends TestCase
{
    private AnalyticsController $controller;

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManager::class);
        $this->controller = new AnalyticsController($em);
    }

    public function testConstantYSeriesInGrangerReturnsDebugResponseWithoutCrashing(): void
    {
        $payload = [
            'calculate_granger' => true,
            'zero_handling' => 'trim',
            'ast' => [
                'type' => 'operator',
                'operator' => '/',
                'left' => ['type' => 'metric', 'metric' => 'y_series'],
                'right' => ['type' => 'metric', 'metric' => 'x_series']
            ],
            'series_data' => [
                'y_series' => ['2026-01-01' => 0, '2026-01-02' => 0, '2026-01-03' => 0],
                'x_series' => ['2026-01-01' => 5, '2026-01-02' => 10, '2026-01-03' => 15]
            ]
        ];

        $request = new Request([], [], [], [], [], [], json_encode($payload));
        $response = $this->controller->computeKpi($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('_debug', $data['data']);
        $this->assertStringContainsString('dependent variable (Y) contains constant values', $data['data']['_debug']);
    }

    public function testConstantXSeriesInRegressionReturnsDebugResponseWithoutCrashing(): void
    {
        $payload = [
            'calculate_regression' => true,
            'zero_handling' => 'trim',
            'ast' => [
                'type' => 'operator',
                'operator' => '/',
                'left' => ['type' => 'metric', 'metric' => 'y_series'],
                'right' => ['type' => 'metric', 'metric' => 'x_series']
            ],
            'series_data' => [
                'y_series' => ['2026-01-01' => 5, '2026-01-02' => 10, '2026-01-03' => 15],
                'x_series' => ['2026-01-01' => 10, '2026-01-02' => 10, '2026-01-03' => 10]
            ]
        ];

        $request = new Request([], [], [], [], [], [], json_encode($payload));
        $response = $this->controller->computeKpi($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('_debug', $data['data']);
        $this->assertStringContainsString('independent variable (X) contains constant values', $data['data']['_debug']);
    }

    public function testInsufficientOverlappingPointsReturnsDebugResponse(): void
    {
        $payload = [
            'calculate_regression' => true,
            'zero_handling' => 'remove',
            'ast' => [
                'type' => 'operator',
                'operator' => '/',
                'left' => ['type' => 'metric', 'metric' => 'y_series'],
                'right' => ['type' => 'metric', 'metric' => 'x_series']
            ],
            'series_data' => [
                'y_series' => ['2026-01-01' => 5],
                'x_series' => ['2026-01-01' => 10]
            ]
        ];

        $request = new Request([], [], [], [], [], [], json_encode($payload));
        $response = $this->controller->computeKpi($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('_debug', $data['data']);
        $this->assertStringContainsString('Not enough overlapping non-zero data points', $data['data']['_debug']);
    }
}
