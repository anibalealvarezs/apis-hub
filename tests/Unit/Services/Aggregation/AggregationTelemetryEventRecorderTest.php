<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Aggregation;

use Services\Aggregation\AggregationTelemetryEventRecorder;
use Tests\Unit\BaseUnitTestCase;

final class AggregationTelemetryEventRecorderTest extends BaseUnitTestCase
{
    private string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aggregation-telemetry-recorder-' . bin2hex(random_bytes(6)) . '.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->outputPath);
        parent::tearDown();
    }

    public function testRecordsJsonlEventToConfiguredPath(): void
    {
        $recorder = new AggregationTelemetryEventRecorder($this->outputPath);

        $written = $recorder->record([
            'event_version' => 1,
            'execution_path' => 'optimized',
            'planner_diagnostics' => ['profile_channel' => 'google_search_console'],
        ]);

        $this->assertTrue($written);
        $this->assertFileExists($this->outputPath);
        $contents = file($this->outputPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($contents);
        $this->assertCount(1, $contents);
        $decoded = json_decode((string)$contents[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('optimized', $decoded['execution_path']);
        $this->assertSame('google_search_console', $decoded['planner_diagnostics']['profile_channel']);
    }
}

