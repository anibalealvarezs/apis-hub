<?php

namespace Classes\Services;

use Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Exception;

class GoogleSearchConsoleClient
{
    private SearchConsoleApi $api;
    private string $siteUrl;
    private LoggerInterface $logger;

    public function __construct(SearchConsoleApi $api, string $siteUrl, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->logger->info("Initializing GoogleSearchConsoleClient for site: $siteUrl");
        $this->api = $api;
        $this->siteUrl = $siteUrl;
    }

    private function generateDimensionCombinations(): array
    {
        $dimensions = ['date', 'query', 'page', 'country', 'device'];
        $combinations = [];
        for ($i = 1; $i < (1 << count($dimensions)); $i++) {
            $combo = [];
            for ($j = 0; $j < count($dimensions); $j++) {
                if ($i & (1 << $j)) {
                    $combo[] = $dimensions[$j];
                }
            }
            $combinations[] = $combo;
        }
        usort($combinations, fn($a, $b) => count($b) <=> count($a));
        $this->logger->info("Generated " . count($combinations) . " dimension combinations");
        return $combinations;
    }

    public function fetchAllCombinations(string $date, int $rowLimit, array $dimensionFilterGroups = []): array
    {
        $this->logger->info("Fetching GSC data for site {$this->siteUrl}, date=$date, rowLimit=$rowLimit, filters=" . json_encode($dimensionFilterGroups));
        $combinations = $this->generateDimensionCombinations();
        $allRows = [];
        $totalRows = 0;

        foreach ($combinations as $index => $dims) {
            $this->logger->debug("Querying combination " . ($index + 1) . "/31: " . implode(',', $dims));
            try {
                $rows = $this->api->getAllSearchQueryResults(
                    siteUrl: $this->siteUrl,
                    startDate: $date,
                    endDate: $date,
                    rowLimit: $rowLimit,
                    dimensions: $dims,
                    dimensionFilterGroups: $dimensionFilterGroups
                );
                $rowCount = count($rows);
                $totalRows += $rowCount;
                $normalizedRows = $this->normalizeRows($rows, $dims);
                $allRows = array_merge($allRows, $normalizedRows);
                $this->logger->info("Fetched $rowCount rows for dimensions: " . implode(',', $dims) . ", normalized to " . count($normalizedRows));
                if ($rowCount > 0) {
                    $this->logger->debug("Sample raw row: " . json_encode($rows[0], JSON_PRETTY_PRINT));
                    if (!empty($normalizedRows)) {
                        $this->logger->debug("Sample normalized row: " . json_encode($normalizedRows[0], JSON_PRETTY_PRINT));
                    }
                } else {
                    $this->logger->warning("No rows returned for dimensions: " . implode(',', $dims));
                }
            } catch (Exception|GuzzleException $e) {
                $this->logger->error("GSC API error for dimensions " . implode(',', $dims) . ": " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
            }
        }

        $this->logger->info("Completed fetching for date=$date, total rows=$totalRows, merged rows=" . count($allRows));
        return $allRows;
    }

    private function normalizeRows(array $rows, array $dims): array
    {
        $normalized = [];
        $dimIndices = ['date' => 0, 'query' => 1, 'page' => 2, 'country' => 3, 'device' => 4];

        // Handle case where rows might be nested
        $flatRows = [];
        if (isset($rows[0]['keys']) && is_array($rows[0])) {
            $flatRows = $rows;
        } else {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $flatRows = array_merge($flatRows, $row);
                }
            }
        }

        $this->logger->info("Normalizing " . count($flatRows) . " rows for dimensions: " . implode(',', $dims));

        foreach ($flatRows as $index => $row) {
            if (!isset($row['keys']) || !is_array($row['keys'])) {
                $this->logger->warning("Invalid row $index for dimensions " . implode(',', $dims) . ": missing or invalid keys, row=" . json_encode($row));
                continue;
            }
            if (count($row['keys']) !== count($dims)) {
                $this->logger->warning("Invalid row $index for dimensions " . implode(',', $dims) . ": key count mismatch, expected=" . count($dims) . ", got=" . count($row['keys']) . ", row=" . json_encode($row));
                continue;
            }
            if (!isset($row['impressions']) || !is_numeric($row['impressions'])) {
                $this->logger->warning("Invalid row $index for dimensions " . implode(',', $dims) . ": missing or invalid impressions, row=" . json_encode($row));
                continue;
            }
            $keys = array_fill(0, 5, null);
            foreach ($dims as $i => $dim) {
                $keys[$dimIndices[$dim]] = $row['keys'][$i] ?? null;
            }
            $normalizedRow = [
                'keys' => $keys,
                'clicks' => isset($row['clicks']) && is_numeric($row['clicks']) ? $row['clicks'] : 0,
                'impressions' => $row['impressions'],
                'ctr' => isset($row['ctr']) && is_numeric($row['ctr']) ? $row['ctr'] : 0,
                'position' => isset($row['position']) && is_numeric($row['position']) ? $row['position'] : 0,
                'dimensions' => $dims
            ];
            $normalized[] = $normalizedRow;
            $this->logger->debug("Normalized row $index: " . json_encode($normalizedRow));
        }

        $this->logger->info("Normalized " . count($flatRows) . " rows for dimensions: " . implode(',', $dims) . ", valid: " . count($normalized));
        return $normalized;
    }
}