<?php

namespace Classes\Overrides\GoogleApi\SearchConsoleApi;

use Anibalealvarezs\GoogleApi\Services\SearchConsole\Classes\DimensionFilterGroup;
use Anibalealvarezs\GoogleApi\Services\SearchConsole\Enums\AggregationType;
use Anibalealvarezs\GoogleApi\Services\SearchConsole\Enums\DataState;
use GuzzleHttp\Exception\GuzzleException;

class SearchConsoleApi extends \Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi
{
    /**
     * Fetches all search query results from Google Search Console and processes them with a callback.
     *
     * @param string $siteUrl
     * @param string $startDate
     * @param string $endDate
     * @param int $rowLimit
     * @param int $startRow
     * @param DataState $dataState
     * @param array|null $dimensions
     * @param string|null $type
     * @param DimensionFilterGroup[]|null $dimensionFilterGroups
     * @param AggregationType $aggregationType
     * @param callable|null $callback
     * @return void
     * @throws GuzzleException
     */
    public function getAllSearchQueryResultsAndProcess(
        string $siteUrl,
        string $startDate,
        string $endDate,
        int $rowLimit = 25000,
        int $startRow = 0,
        DataState $dataState = DataState::ALL,
        ?array $dimensions = null,
        string $type = null,
        ?array $dimensionFilterGroups = null,
        AggregationType $aggregationType = AggregationType::AUTO,
        callable $callback = null
    ): void {
        $params = [
            'siteUrl' => $siteUrl,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'rowLimit' => $rowLimit,
            'startRow' => $startRow,
            'dataState' => $dataState,
            'dimensions' => $dimensions,
            'type' => $type,
            'dimensionFilterGroups' => $dimensionFilterGroups,
            'aggregationType' => $aggregationType,
        ];

        do {
            $response = $this->getSearchQueryResults(...$params);
            if (!empty($response['rows']) && $callback) {
                $callback($response['rows']);
            }
            $params['startRow'] += $params['rowLimit'];
        } while (!empty($response['rows']) && count($response['rows']) == $params['rowLimit']);
    }
}
