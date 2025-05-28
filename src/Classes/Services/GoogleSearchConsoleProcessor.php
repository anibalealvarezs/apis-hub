<?php

namespace Classes\Services;

use Psr\Log\LoggerInterface;

class GoogleSearchConsoleProcessor
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function disaggregateResults(array $results): array
    {
        $this->logger->info("Starting disaggregation of " . count($results) . " result sets");
        // Temporary: Bypass subtraction to preserve raw rows
        $uniqueMetrics = [];
        foreach ($results as $result) {
            $dims = $result['dimensions'];
            $this->logger->info("Processing combination: " . implode(',', $dims) . ", rows=" . count($result['rows']));
            foreach ($result['rows'] as $row) {
                $uniqueMetrics[] = $row;
                $keyParts = array_map(fn($k) => $k ?? 'null', $row['keys']);
                $metricKey = implode(':', $keyParts);
                $this->logger->info("Preserved row: key=$metricKey, impressions={$row['impressions']}, clicks={$row['clicks']}, dimensions=" . implode(',', $dims));
            }
        }
        $this->logger->info("Bypassed disaggregation: " . count($uniqueMetrics) . " rows preserved");
        return $uniqueMetrics;
    }
}