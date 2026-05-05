<?php

declare(strict_types=1);

namespace Services\Aggregation;

/**
 * AggregationTelemetryEventRecorder
 * 
 * Records aggregation execution events for fallback analysis.
 * Official Production Path: D:\laragon\www\apis-hub\storage\logs\aggregation-telemetry.jsonl
 * Configurable via AGGREGATION_TELEMETRY_PATH environment variable.
 */
final class AggregationTelemetryEventRecorder
{
    public function __construct(private readonly ?string $outputPath = null)
    {
    }

    public function isEnabled(): bool
    {
        $path = $this->resolveOutputPath();
        return is_string($path) && trim($path) !== '';
    }

    /**
     * @param array<string, mixed> $event
     */
    public function record(array $event): bool
    {
        $path = $this->resolveOutputPath();
        if (!is_string($path) || trim($path) === '') {
            return false;
        }

        $directory = dirname($path);
        if ($directory !== '' && !is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            return false;
        }

        $json = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $written = @file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX);

        return $written !== false;
    }

    private function resolveOutputPath(): ?string
    {
        if (is_string($this->outputPath) && trim($this->outputPath) !== '') {
            return $this->outputPath;
        }

        $path = getenv('AGGREGATION_TELEMETRY_PATH');
        if ($path === false || trim($path) === '') {
            $path = $_ENV['AGGREGATION_TELEMETRY_PATH'] ?? $_SERVER['AGGREGATION_TELEMETRY_PATH'] ?? null;
        }

        return is_string($path) && trim($path) !== '' ? $path : null;
    }
}

