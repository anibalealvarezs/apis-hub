<?php

namespace Helpers;

use Exception;
use Psr\Log\LoggerInterface;

class GoogleSearchConsoleHelpers
{
    /**
     * Default values for dimensions when not provided.
     */
    public static array $defaultValues = [
        'query' => 'unknown',
        'country' => 'UNK',
        'page' => null,
        'device' => 'unknown'
    ];

    /**
     * All dimensions used in GSC data.
     * - 'date' and 'page' are always present
     * - 'query', 'country', 'device' are optional
     */
    public static array $allDimensions = ['date', 'query', 'country', 'page', 'device'];

    /**
     * Optional dimensions that can be present in GSC data.
     * Used for filtering and filling missing values.
     * - 'query', 'country', 'device' are optional
     */
    public static array $optionalDimensions = ['query', 'country', 'device'];

    /**
     * @param array $allRows
     * @param array $targetKeywords
     * @param array $targetCountries
     * @return array
     */
    public static function getFinalRecords(array $allRows, array $targetKeywords, array $targetCountries): array
    {
        $childrenSums = self::computeChildrenSum($allRows);

        /* $sums = [];
        foreach ($allRows as $i => $row) {
            $sums[] = "Record $i has children sum = " . (is_array($childrenSums[$i]) ? json_encode($childrenSums[$i]) : $childrenSums[$i]);
        } */

        $differences = self::calculateDifferences($allRows, $childrenSums);

        $allocatedDifferences = self::allocatePositiveDifferences(
            $differences,
            self::$allDimensions
        );

        $allocateFinalDifference = self::addGlobalRemainderSynthetic(
            $allocatedDifferences,
            self::$allDimensions
        );

        $negativeDifferencesProcessed = self::flagOrScaleNegativeDifferences(
            $allocateFinalDifference,
            true
        );

        $scaleAdjusted = self::adjustScaledPositions($negativeDifferencesProcessed);

        $finalRecords = array_values(array_filter($scaleAdjusted, function ($record) {
            // Keep if:
            // - full subset (all 5 dims)
            // - or synthetic record
            return (count($record['subset']) === 5) || (!empty($record['synthetic']));
        }));

        return self::fillWithNullsAndFilter($finalRecords, $targetKeywords, $targetCountries);
    }

    /**
     * Allocates positive differences by creating synthetic records for missing dimensions.
     *
     * @param array $records
     * @param array $dimensionNames
     * @param string $missingLabel
     * @return array
     */
    public static function allocatePositiveDifferences(
        array $records,
        array $dimensionNames,
        string $missingLabel = 'unknown'
    ): array {
        $extendedRecords = $records;

        foreach ($records as $record) {
            $impressionDiff = $record['impressions_difference'] ?? 0;
            $clicksDiff = $record['clicks_difference'] ?? 0;

            if ($impressionDiff > 0 || $clicksDiff > 0) {
                $newKeys = $record['keys'];
                $subset = $record['subset'];

                // Find the first missing dimension
                $missingDimension = null;
                foreach ($dimensionNames as $dim) {
                    if (!in_array($dim, $subset)) {
                        $missingDimension = $dim;
                        break;
                    }
                }

                if ($missingDimension !== null) {
                    $newKeys[] = $missingLabel;
                    $newSubset = [...$subset, $missingDimension];

                    // Create synthetic record
                    $syntheticRecord = [
                        'keys' => $newKeys,
                        'clicks' => $clicksDiff,
                        'impressions' => $impressionDiff,
                        'ctr' => ($impressionDiff > 0) ? $clicksDiff / $impressionDiff : 0,
                        'position' => null,
                        'subset' => $newSubset,
                        'impressions_difference' => 0,
                        'clicks_difference' => 0,
                        'synthetic' => true,
                    ];

                    $extendedRecords[] = $syntheticRecord;
                }
            }
        }

        return $extendedRecords;
    }

    /**
     * Adds a synthetic record to reconcile unmatched parent metrics.
     *
     * This method calculates the remaining impressions, clicks, and position
     * based on the total metrics and the fully attributed 5D records.
     *
     * @param array $records
     * @param array $dimensionNames
     * @param array $parentSubset
     * @return array
     */
    public static function addGlobalRemainderSynthetic(
        array $records,
        array $dimensionNames,
        array $parentSubset = ['date', 'page']
    ): array {
        $extendedRecords = $records;

        $allImpressions = 0;
        $allClicks = 0;
        $allPositionWeightedSum = 0;
        $allPositionCount = 0;

        $fiveDImpressions = 0;
        $fiveDClicks = 0;
        $fiveDPositionWeightedSum = 0;
        $fiveDPositionCount = 0;

        $partialImpressions = 0;
        $partialClicks = 0;
        $partialPositionWeightedSum = 0;
        $partialPositionCount = 0;

        // Step 1: Sum metrics by category
        foreach ($records as $rec) {
            $subset = $rec['subset'] ?? [];
            $impr = $rec['impressions'] ?? 0;
            $clicks = $rec['clicks'] ?? 0;
            $pos = $rec['position'] ?? null;
            $posWeighted = ($pos !== null) ? ($pos * $impr) : 0;

            // Parent subset totals ("All")
            if (array_values($subset) == $parentSubset) {
                $allImpressions += $impr;
                $allClicks += $clicks;
                if ($pos !== null) {
                    $allPositionWeightedSum += $posWeighted;
                    $allPositionCount += $impr;
                }
            }

            // Fully attributed 5D records (exclude synthetic)
            if (count($subset) === count($dimensionNames) && empty($rec['synthetic'])) {
                $fiveDImpressions += $impr;
                $fiveDClicks += $clicks;
                if ($pos !== null) {
                    $fiveDPositionWeightedSum += $posWeighted;
                    $fiveDPositionCount += $impr;
                }
            }

            // Partial synthetic records
            if (!empty($rec['synthetic'])) {
                $partialImpressions += $impr;
                $partialClicks += $clicks;
                if ($pos !== null) {
                    $partialPositionWeightedSum += $posWeighted;
                    $partialPositionCount += $impr;
                }
            }
        }

        // Step 2: Calculate remaining values
        $remainingImpressions = $allImpressions - $fiveDImpressions - $partialImpressions;
        $remainingClicks = $allClicks - $fiveDClicks - $partialClicks;

        // Weighted position average for remaining
        // Compute weighted position of "All" minus weighted positions already accounted for
        $allPositionAvg = ($allPositionCount > 0) ? ($allPositionWeightedSum / $allPositionCount) : null;

        // For position, approximate remaining weighted sum and count
        $remainingPositionWeightedSum = $allPositionWeightedSum - $fiveDPositionWeightedSum - $partialPositionWeightedSum;
        $remainingPositionCount = $allPositionCount - $fiveDPositionCount - $partialPositionCount;

        $remainingPosition = ($remainingPositionCount > 0) ? ($remainingPositionWeightedSum / $remainingPositionCount) : $allPositionAvg;

        // CTR = clicks / impressions (avoid division by zero)
        $remainingCtr = ($remainingImpressions > 0) ? ($remainingClicks / $remainingImpressions) : 0;

        if ($remainingImpressions > 0 || $remainingClicks > 0) {
            // Compose keys for parent subset, fill missing with $missingLabel
            $keys = [];
            foreach ($dimensionNames as $dim) {
                $missingLabel = match ($dim) {
                    'country' => 'UNK',
                    default => 'unknown',
                };
                if (in_array($dim, $parentSubset)) {
                    // Find record with this subset and take its key for that dim, or fallback to missingLabel
                    $foundKey = $missingLabel;
                    foreach ($records as $rec) {
                        if (($rec['subset'] ?? []) === $parentSubset) {
                            $index = array_search($dim, $parentSubset);
                            $foundKey = $rec['keys'][$index] ?? $missingLabel;
                            break;
                        }
                    }
                    $keys[] = $foundKey;
                } else {
                    $keys[] = $missingLabel;
                }
            }

            $syntheticRecord = [
                'keys' => $keys,
                'subset' => $dimensionNames,
                'impressions' => $remainingImpressions,
                'clicks' => $remainingClicks,
                'ctr' => $remainingCtr,
                'position' => $remainingPosition,
                'synthetic' => true,
                'note' => 'final synthetic to reconcile unmatched parent metrics',
                'impressions_difference' => 0,
                'clicks_difference' => 0,
            ];

            $extendedRecords[] = $syntheticRecord;
        }

        return $extendedRecords;
    }

    /**
     * Checks if the parent subset is a parent of the child subset.
     *
     * @param array $parentSubset
     * @param array $parentDims
     * @param array $childSubset
     * @param array $childDims
     * @return bool
     */
    public static function isParentOf(
        array $parentSubset,
        array $parentDims,
        array $childSubset,
        array $childDims
    ): bool {
        if (count($childSubset) <= count($parentSubset)) {
            return false;
        }
        $childSubsetIndex = array_flip($childSubset);
        $parentIndexInChild = [];

        foreach ($parentSubset as $dimName) {
            if (!isset($childSubsetIndex[$dimName])) {
                return false;
            }
            $parentIndexInChild[] = $childSubsetIndex[$dimName];
        }

        $prevIdx = -1;
        foreach ($parentIndexInChild as $i => $childIdx) {
            if ($childIdx <= $prevIdx) {
                return false;
            }
            $prevIdx = $childIdx;
            if ($parentDims[$i] !== $childDims[$childIdx]) {
                return false;
            }
        }
        return true;
    }

    /**
     * Adjusts positions of scaled records based on their children's impressions.
     *
     * @param array $records
     * @return array
     */
    public static function adjustScaledPositions(array $records): array
    {
        $n = count($records);

        // Helper: same as before


        for ($i = 0; $i < $n; $i++) {
            if (!($records[$i]['scaled'] ?? false)) {
                continue;
            }

            $parentSubset = $records[$i]['subset'];
            $parentDims = $records[$i]['keys'];

            $weightedSum = 0;
            $totalImpressions = 0;

            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) {
                    continue;
                }

                $childSubset = $records[$j]['subset'];
                $childDims = $records[$j]['keys'];

                if (self::isParentOf($parentSubset, $parentDims, $childSubset, $childDims)) {
                    $impressions = $records[$j]['impressions'] ?? 0;
                    $position = $records[$j]['position'] ?? null;

                    if ($impressions > 0 && $position !== null) {
                        $weightedSum += $impressions * $position;
                        $totalImpressions += $impressions;
                    }
                }
            }

            if ($totalImpressions > 0) {
                $records[$i]['original_position'] = $records[$i]['position'] ?? null;
                $records[$i]['position'] = round($weightedSum / $totalImpressions, 2);
            }
        }

        return $records;
    }

    /**
     * Flags or scales down records with negative differences in impressions or clicks.
     *
     * @param array $records
     * @param bool $scaleNegative Whether to scale down negative differences
     * @return array
     */
    public static function flagOrScaleNegativeDifferences(array $records, bool $scaleNegative = false): array
    {
        foreach ($records as &$record) {

            $impressionDiff = $record['impressions_difference'] ?? 0;
            $clicksDiff = $record['clicks_difference'] ?? 0;

            $childrenImpressions = $record['children_sum']['impressions'] ?? 0;
            $childrenClicks = $record['children_sum']['clicks'] ?? 0;

            $impressions = $record['impressions'] ?? 0;
            $clicks = $record['clicks'] ?? 0;

            // If either metric has a negative difference, treat the record
            if ($impressionDiff < 0 || $clicksDiff < 0) {
                if ($scaleNegative) {
                    $scaleFactorImpr = $childrenImpressions > 0 ? $impressions / $childrenImpressions : 0;
                    $scaleFactorClicks = $childrenClicks > 0 ? $clicks / $childrenClicks : 0;

                    $record['original_impressions'] = $impressions;
                    $record['original_clicks'] = $clicks;
                    $record['original_differences'] = [
                        'impressions' => $impressionDiff,
                        'clicks' => $clicksDiff
                    ];

                    $record['impressions'] = round($childrenImpressions * $scaleFactorImpr);
                    $record['clicks'] = round($childrenClicks * $scaleFactorClicks);
                    $record['scaled'] = true;
                    $record['note'] = 'scaled down to match parent metrics';

                    // Recalculate CTR
                    $record['ctr'] = $record['impressions'] > 0 ? $record['clicks'] / $record['impressions'] : 0;
                } else {
                    $record['flagged'] = true;
                    $record['note'] = 'exceeds parent; likely misattributed';
                }
            }
        }

        return $records;
    }

    /**
     * Calculates differences between own metrics and children's sums.
     *
     * @param array $records
     * @param array $childrenSums
     * @return array
     */
    public static function calculateDifferences(array $records, array $childrenSums): array
    {
        foreach ($records as $index => &$record) {
            $ownImpressions = $record['impressions'] ?? 0;
            $ownClicks = $record['clicks'] ?? 0;

            $childrenImpressions = $childrenSums[$index]['impressions'] ?? 0;
            $childrenClicks = $childrenSums[$index]['clicks'] ?? 0;

            $record['impressions_difference'] = $ownImpressions - $childrenImpressions;
            $record['clicks_difference'] = $ownClicks - $childrenClicks;
        }
        unset($record); // prevent accidental reference reuse
        return $records;
    }

    /**
     * Computes the sum of children's metrics for each parent record.
     *
     * @param array $records
     * @return array
     */
    public static function computeChildrenSum(array $records): array
    {
        $n = count($records);

        // Initialize sums array with zeros for each metric
        $childrenSums = array_fill(0, $n, ['impressions' => 0, 'clicks' => 0]);

        // Sum children metrics
        for ($i = 0; $i < $n; $i++) {
            $parentSubset = $records[$i]['subset'];
            $parentDims = $records[$i]['keys'];

            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) {
                    continue;
                }

                $childSubset = $records[$j]['subset'];
                $childDims = $records[$j]['keys'];

                if (self::isParentOf($parentSubset, $parentDims, $childSubset, $childDims)) {
                    $childrenSums[$i]['impressions'] += $records[$j]['impressions'] ?? 0;
                    $childrenSums[$i]['clicks'] += $records[$j]['clicks'] ?? 0;
                }
            }
        }

        return $childrenSums;
    }

    /**
     * Fills missing dimensions with nulls and filters records based on target keywords and countries.
     *
     * @param array $rows
     * @param array $targetKeywords
     * @param array $targetCountries
     * @return array
     */
    public static function fillWithNullsAndFilter(array $rows, array $targetKeywords, array $targetCountries): array
    {
        $newRows = [];
        foreach ($rows as $row) {
            list($date, $query, $country, $page, $device) = self::getDimensionsValues($row, array_flip($row['subset']), $targetKeywords, $targetCountries);
            $row['keys'] = [$date, $query, $country, $page, $device];
            $newRows[] = $row;
        }
        return $newRows;
    }

    /**
     * Processes rows without filling missing dimensions, but filters based on subset and target keywords/countries.
     *
     * @param array $rows
     * @param array $subset
     * @param array $targetKeywords
     * @param array $targetCountries
     * @return array
     */
    public static function dontFillButFilter(array &$rows, array $subset, array $targetKeywords, array $targetCountries): array
    {
        $allRows = [];
        foreach ($rows as $row) {
            list($date, $query, $country, $page, $device) = self::getDimensionsValues($row, array_flip($subset), $targetKeywords, $targetCountries);
            $row['keys'] = [$date, $query, $country, $page, $device];
            $allRows[] = $row;
        }
        return $allRows;
    }

    /**
     * Validates Google and GSC configurations.
     *
     * @param LoggerInterface $logger
     * @return array
     * @throws Exception
     */
    public static function validateGoogleConfig(LoggerInterface $logger): array
    {
        $config = Helpers::getChannelsConfig()['google'] ?? [];
        $scConfig = Helpers::getChannelsConfig()['google_search_console'] ?? null;
        if (!$scConfig) {
            $logger->error("Missing 'google_search_console' configuration in channels config");
            throw new Exception("Missing 'google_search_console' configuration in channels config");
        }
        if (!isset($scConfig['sites']) || !is_array($scConfig['sites'])) {
            if ($scConfig['cache_all'] ?? false) {
                $scConfig['sites'] = [];
            } else {
                $logger->error("Missing or invalid 'sites' configuration in 'google_search_console'");
                throw new Exception("Missing or invalid 'sites' configuration in 'google_search_console'");
            }
        }
        $logger->info("Loaded GSC config: sites=" . count($scConfig['sites']));
        return [
            'google' => $config,
            'google_search_console' => $scConfig,
        ];
    }

    /**
     * @param array $row
     * @param array $dimensionsIndex
     * @return string|null
     */
    protected static function getDate(array $row, array $dimensionsIndex): ?string
    {
        return isset($dimensionsIndex['date']) ? ($row['keys'][$dimensionsIndex['date']] ?? null) : null;
    }

    /**
     * @param array $row
     * @param array $dimensionsIndex
     * @param array $targetKeywords
     * @return string|null
     */
    protected static function getQueryTerm(array $row, array $dimensionsIndex, array $targetKeywords): ?string
    {
        if (!isset($dimensionsIndex['query']) || !isset($row['keys'][$dimensionsIndex['query']])) {
            return self::$defaultValues['query'];
        }
        $queryTerm = ($row['keys'][$dimensionsIndex['query']]);
        return empty($targetKeywords) || Helpers::str_contains_any($queryTerm, $targetKeywords) ? $queryTerm :
            ($queryTerm == self::$defaultValues['query'] ? self::$defaultValues['query'] : 'others');
    }

    /**
     * @param array $row
     * @param array $dimensionsIndex
     * @param array $targetCountries
     * @return string|null
     */
    protected static function getCountryCode(array $row, array $dimensionsIndex, array $targetCountries): ?string
    {
        if (!isset($dimensionsIndex['country']) || !isset($row['keys'][$dimensionsIndex['country']])) {
            return self::$defaultValues['country'];
        }
        return (empty($targetCountries) || in_array(strtolower($row['keys'][$dimensionsIndex['country']]), $targetCountries)) ?
            strtoupper($row['keys'][$dimensionsIndex['country']]) :
            ($row['keys'][$dimensionsIndex['country']] == self::$defaultValues['country'] ? self::$defaultValues['country'] : 'OTH');
    }

    /**
     * @param array $row
     * @param array $dimensionsIndex
     * @return string|null
     */
    protected static function getPage(array $row, array $dimensionsIndex): ?string
    {
        if (!isset($dimensionsIndex['page']) || !isset($row['keys'][$dimensionsIndex['page']])) {
            return self::$defaultValues['page'];
        }
        return ($row['keys'][$dimensionsIndex['page']]);
    }

    /**
     * @param array $row
     * @param array $dimensionsIndex
     * @return string|null
     */
    protected static function getDevice(array $row, array $dimensionsIndex): ?string
    {
        if (!isset($dimensionsIndex['device']) || !isset($row['keys'][$dimensionsIndex['device']])) {
            return self::$defaultValues['device'];
        }
        return strtolower($row['keys'][$dimensionsIndex['device']]);
    }

    /**
     * Extracts dimension values from a row based on the provided indices.
     *
     * @param array $row
     * @param array $dimensionsIndex
     * @param array $targetKeywords
     * @param array $targetCountries
     * @return array
     */
    protected static function getDimensionsValues(array $row, array $dimensionsIndex, array $targetKeywords, array $targetCountries): array
    {
        return [
            self::getDate($row, $dimensionsIndex),
            self::getQueryTerm($row, $dimensionsIndex, $targetKeywords),
            self::getCountryCode($row, $dimensionsIndex, $targetCountries),
            self::getPage($row, $dimensionsIndex),
            self::getDevice($row, $dimensionsIndex)
        ];
    }

    /**
     * Extracts metrics values from a row.
     *
     * @param mixed $row
     * @return array
     */
    public static function getMetricsValues(mixed $row): array
    {
        return [
            (int)($row['impressions'] ?? 0),
            (int)($row['clicks'] ?? 0),
            (float)($row['position'] ?? 0),
            (float)($row['ctr'] ?? 0),
        ];
    }

}
