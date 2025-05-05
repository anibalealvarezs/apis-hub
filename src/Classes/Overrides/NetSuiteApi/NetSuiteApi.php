<?php

namespace Classes\Overrides\NetSuiteApi;

use GuzzleHttp\Exception\GuzzleException;

class NetSuiteApi extends \Anibalealvarezs\NetSuiteApi\NetSuiteApi
{
    /**
     * @param string $query
     * @param int $limit
     * @param callable|null $callback
     * @return void
     * @throws GuzzleException
     */
    public function getSuiteQLQueryAllAndProcess(
        string $query,
        int $limit = 1000,
        callable $callback = null,
    ): void
    {
        $offset = 0;
        do {
            $response = $this->getSuiteQLQuery($query, $offset, $limit);
            if ($callback) {
                $callback($response["items"]);
            }
            $offset += $limit;
        } while ($response['hasMore']);
    }
}