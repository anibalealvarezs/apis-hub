<?php

namespace Classes\Conversions;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\TransactionRequiredException;
use Entities\Analytics\Page;
use Enums\Channel;
use Enums\Period;
use Exception;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;
use stdClass;

class GoogleSearchConsoleConvert
{
    /**
     * Converts GSC API rows into a collection of metric objects.
     *
     * @param array $rows
     * @param string $siteUrl
     * @param string $siteKey
     * @param array $targetKeywords
     * @param array $targetCountries
     * @param LoggerInterface|null $logger
     * @param Page|null $pageEntity
     * @param EntityManager|null $em
     * @param array $dimensions
     * @return ArrayCollection
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     * @throws Exception
     */
    public static function metrics(
        array $rows,
        string $siteUrl,
        string $siteKey,
        array $targetKeywords = [],
        array $targetCountries = [],
        LoggerInterface $logger = null,
        ?Page $pageEntity = null,
        ?EntityManager $em = null,
        array $dimensions = [],
    ): ArrayCollection {
        $startTime = microtime(true);
        $rowCount = count($rows);
        $collection = new ArrayCollection();
        $skippedRows = 0;

        $logger?->info("Starting metrics conversion for site $siteUrl, rows=$rowCount");
        // $logger?->warning("Note: 'searchAppearance' not fetched from GSC API due to dimension restrictions; defaulting to 'WEB' in ChanneledMetricDimension");
        // $logger?->info("Page entity for site $siteUrl: " . ($pageEntity ? "ID={$pageEntity->getId()}, URL={$pageEntity->getUrl()}" : 'none'));
        $pageEntity = $em->find(Page::class, $pageEntity->getId());
        $searchAppearance = 'WEB';

        foreach ($rows as $index => $row) {
            $rowStart = microtime(true);

            list($date, $query, $country, $page, $device) = self::getDimensionsValues($row, array_flip($dimensions), $targetKeywords, $targetCountries);
            list($impressions, $clicks, $position) = self::getMetricsValues($row);

            $dimensionValues = [];
            foreach ($dimensions as $dimension) {
                $dimensionValues[$dimension] = ${$dimension};
            }
            $groupKey = md5(json_encode($dimensionValues, JSON_UNESCAPED_UNICODE));

            if (isset($aggregatedRows[$groupKey])) {
                $aggregatedRows[$groupKey]['clicks'] += $clicks;
                $aggregatedRows[$groupKey]['impressions'] += $impressions;
                $aggregatedRows[$groupKey]['position'] += ($position) * ($impressions);
                $aggregatedRows[$groupKey]['count']++;
                $logger?->info("Aggregated duplicate row $index for query=$query, page=$page");
                continue;
            }

            $platformId = "gsc_{$siteKey}_$groupKey";
            foreach (['clicks', 'impressions', 'ctr', 'position'] as $metricName) {
                $value = match ($metricName) {
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'ctr' => $impressions > 0 ? $clicks / $impressions : 0,
                    'position' => $impressions > 0 ? $position : 0,
                };

                $channeledMetric = new stdClass();
                $channeledMetric->channel = Channel::google_search_console->value;
                $channeledMetric->name = $metricName;
                $channeledMetric->value = $value;
                $channeledMetric->period = Period::Daily->value;
                $channeledMetric->metricDate = new DateTime($date);
                $channeledMetric->platformId = $platformId;
                $channeledMetric->platformCreatedAt = new DateTime($date);
                $channeledMetric->query = $query;
                $channeledMetric->countryCode = $country;
                $channeledMetric->deviceType = $device;
                $channeledMetric->page = $pageEntity;
                $channeledMetric->dimensions = [
                    (object) ['dimensionKey' => 'page', 'dimensionValue' => $page],
                    (object) ['dimensionKey' => 'searchAppearance', 'dimensionValue' => $searchAppearance],
                ];
                $channeledMetric->data = [
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'position_weighted' => $position * $impressions,
                    'ctr' => $impressions > 0 ? $clicks / $impressions : 0 // Fixed: Use calculated ctr
                ];

                // $logger?->info("Created metric for row $index: name=$metricName, query=$queryTerm, page=" . ($pageEntity ? "ID={$pageEntity->getId()}, URL={$pageEntity->getUrl()}" : 'none') . ", dimension[page]=$page, data=" . json_encode($channeledMetric->data));

                $collection->add($channeledMetric);
            }

            if ($index % 1000 === 0) {
                $rowTime = microtime(true) - $rowStart;
                $memory = memory_get_usage() / 1024 / 1024;
                // $logger?->info("Converted row $index/$rowCount, created " . $collection->count() . " metrics, took $rowTime seconds, memory: $memory MB");
            }
        }

        $totalTime = microtime(true) - $startTime;
        $memory = memory_get_usage() / 1024 / 1024;
        $logger?->info("Completed metrics conversion: $rowCount rows to " . $collection->count() . " metrics, skipped $skippedRows rows, took $totalTime seconds, memory: $memory MB");

        return $collection;
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
        $queryTerm = isset($dimensionsIndex['query']) ? ($row['keys'][$dimensionsIndex['query']] ?? '') : '';
        return empty($targetKeywords) || Helpers::str_contains_any($queryTerm, $targetKeywords) ? $queryTerm : 'others';
    }

    /**
     * @param array $row
     * @param array $dimensionsIndex
     * @param array $targetCountries
     * @return string|null
     */
    protected static function getCountryCode(array $row, array $dimensionsIndex, array $targetCountries): ?string
    {
        return isset($dimensionsIndex['country']) &&
            isset($row['keys'][$dimensionsIndex['country']]) &&
            (empty($targetCountries) || in_array(strtolower($row['keys'][$dimensionsIndex['country']]), $targetCountries)) ?
                strtoupper($row['keys'][$dimensionsIndex['country']]) :
                'OTH';
    }

    /**
     * @param array $row
     * @param array $dimensionsIndex
     * @return string|null
     */
    protected static function getPage(array $row, array $dimensionsIndex): ?string
    {
        return isset($dimensionsIndex['page']) ? ($row['keys'][$dimensionsIndex['page']] ?? null) : null;
    }

    /**
     * @param array $row
     * @param array $dimensionsIndex
     * @return string|null
     */
    protected static function getDevice(array $row, array $dimensionsIndex): ?string
    {
        return isset($dimensionsIndex['device']) && isset($row['keys'][$dimensionsIndex['device']]) ?
                    strtolower($row['keys'][$dimensionsIndex['device']]) :
                    'other';
    }

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

    private static function getMetricsValues(mixed $row) : array
    {
        return [
            $row['impressions'] ?? 0,
            $row['clicks'] ?? 0,
            $row['position'] ?? 0,
        ];
    }
}