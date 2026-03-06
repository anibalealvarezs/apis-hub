<?php

namespace Classes\Conversions;

use Carbon\Carbon;
use Classes\KeyGenerator;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\TransactionRequiredException;
use Entities\Analytics\Page;
use Enums\Channel;
use Enums\Country;
use Enums\Device;
use Enums\Period;
use Helpers\GoogleSearchConsoleHelpers;
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
     * @param LoggerInterface|null $logger
     * @param Page|null $pageEntity
     * @param EntityManager|null $em
     * @return ArrayCollection
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    public static function metrics(
        array $rows,
        string $siteUrl,
        string $siteKey,
        LoggerInterface $logger = null,
        ?Page $pageEntity = null,
        ?EntityManager $em = null,
    ): ArrayCollection {
        $startTime = microtime(true);
        $rowCount = count($rows);
        $collection = new ArrayCollection();
        $skippedRows = 0;

        $logger?->info("Starting metrics conversion for site $siteUrl, rows=$rowCount");
        // $logger?->warning("Note: 'searchAppearance' not fetched from GSC API due to dimension restrictions; defaulting to 'WEB' in ChanneledMetricDimension");
        if ($pageEntity) {
            $pageEntity = $em->find(Page::class, $pageEntity->getId());
        }
        $searchAppearance = 'WEB';

        $aggregatedMetrics = [];
        $aggregatedMetadata = [];
        $elements = [];
        foreach ($rows as $index => $row) {
            $rowStart = microtime(true);

            $flippedDimensions = array_flip(GoogleSearchConsoleHelpers::$allDimensions);

            $dimensionValues = [];
            foreach (GoogleSearchConsoleHelpers::$allDimensions as $dimension) {
                if (!isset($row['keys'][$flippedDimensions[$dimension]])) {
                    $row['keys'][$flippedDimensions[$dimension]] = match($dimension) {
                        'date' => Carbon::now()->toDateString(),
                        'query' => 'unknown',
                        'country' => Country::UNK->value,
                        'page' => null,
                        'device' => Device::UNKNOWN->value,
                        default => null,
                    };
                }
                $dimensionValues[$dimension] = $row['keys'][$flippedDimensions[$dimension]];
            }
            $metricConfigKeys = [
                'impressions' => KeyGenerator::generateMetricConfigKey(
                    channel: Channel::google_search_console->value,
                    name: 'impressions',
                    period: Period::Daily->value,
                    metricDate: Carbon::parse($dimensionValues['date'])->toDateString(),
                    page:  $pageEntity?->getUrl() ?? $siteUrl,
                    query: $dimensionValues['query'],
                    country: $dimensionValues['country'],
                    device: $dimensionValues['device'],
                ),
                'clicks' => KeyGenerator::generateMetricConfigKey(
                    channel: Channel::google_search_console->value,
                    name: 'clicks',
                    period: Period::Daily->value,
                    metricDate: Carbon::parse($dimensionValues['date'])->toDateString(),
                    page:  $pageEntity?->getUrl() ?? $siteUrl,
                    query: $dimensionValues['query'],
                    country: $dimensionValues['country'],
                    device: $dimensionValues['device'],
                ),
                'position' => KeyGenerator::generateMetricConfigKey(
                    channel: Channel::google_search_console->value,
                    name: 'position',
                    period: Period::Daily->value,
                    metricDate: Carbon::parse($dimensionValues['date'])->toDateString(),
                    page:  $pageEntity?->getUrl() ?? $siteUrl,
                    query: $dimensionValues['query'],
                    country: $dimensionValues['country'],
                    device: $dimensionValues['device'],
                ),
                'ctr' => KeyGenerator::generateMetricConfigKey(
                    channel: Channel::google_search_console->value,
                    name: 'ctr',
                    period: Period::Daily->value,
                    metricDate: Carbon::parse($dimensionValues['date'])->toDateString(),
                    page:  $pageEntity?->getUrl() ?? $siteUrl,
                    query: $dimensionValues['query'],
                    country: $dimensionValues['country'],
                    device: $dimensionValues['device'],
                )
            ];

            list($impressions, $clicks, $position, $ctr) = GoogleSearchConsoleHelpers::getMetricsValues($row);
            // Aggregate metrics for calculations using the `impressions` metricConfigKey as the main key
            if (isset($aggregatedMetrics[$metricConfigKeys['impressions']])) {
                // Aggregate existing row
                $aggregatedMetrics[$metricConfigKeys['impressions']] = self::aggregateMetrics(
                    $aggregatedMetrics[$metricConfigKeys['impressions']],
                    [
                        'impressions' => $impressions,
                        'clicks' => $clicks,
                        'position' => $position,
                    ]
                );
                $aggregatedMetadata[$metricConfigKeys['impressions']][] = [
                    ...$aggregatedMetrics[$metricConfigKeys['impressions']],
                    'keys' => $row['keys'] ?? [],
                    'subset' => $row['subset'] ?? null,
                    'synthetic' => $row['synthetic'] ?? false,
                    'impressions_difference' => $row['impressions_difference'] ?? 0,
                    'clicks_difference' => $row['clicks_difference'] ?? 0,
                ];
            } else {
                $aggregatedMetrics[$metricConfigKeys['impressions']] = [
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'position' => $position,
                    "ctr" => $ctr,
                    'count' => 1,
                ];
                $aggregatedMetadata[$metricConfigKeys['impressions']] = [
                    ...$aggregatedMetrics[$metricConfigKeys['impressions']],
                    'keys' => $row['keys'] ?? [],
                    'subset' => $row['subset'] ?? null,
                    'synthetic' => $row['synthetic'] ?? false,
                    'impressions_difference' => $row['impressions_difference'] ?? 0,
                    'clicks_difference' => $row['clicks_difference'] ?? 0,
                ];
            }

            $platformId = "gsc_{$siteKey}_{$metricConfigKeys['impressions']}";
            foreach (['clicks', 'impressions', 'ctr', 'position'] as $metricName) {
                $channeledMetric = new stdClass();
                $channeledMetric->channel = Channel::google_search_console->value;
                $channeledMetric->name = $metricName;
                $channeledMetric->value = $aggregatedMetrics[$metricConfigKeys['impressions']][$metricName];
                $channeledMetric->period = Period::Daily->value;
                $channeledMetric->metricDate = Carbon::parse($row['keys'][$flippedDimensions['date']])->toDateString();
                $channeledMetric->platformId = $platformId;
                $channeledMetric->platformCreatedAt = Carbon::parse($row['keys'][$flippedDimensions['date']])->toDateTimeString();
                $channeledMetric->query = $row['keys'][$flippedDimensions['query']] ?? 'unknown';
                $channeledMetric->countryCode = $row['keys'][$flippedDimensions['country']] ?? Country::UNK->value;
                $channeledMetric->deviceType = $row['keys'][$flippedDimensions['device']] ?? Device::UNKNOWN->value;
                $channeledMetric->page = $pageEntity;
                $channeledMetric->dimensions = [
                    ['dimensionKey' => 'page', 'dimensionValue' => $row['keys'][$flippedDimensions['page']] ?? null],
                    ['dimensionKey' => 'searchAppearance', 'dimensionValue' => $searchAppearance],
                ];
                $channeledMetric->dimensionsHash = KeyGenerator::generateDimensionsHash([
                    ['dimensionKey' => 'page', 'dimensionValue' => $row['keys'][$flippedDimensions['page']] ?? null],
                    ['dimensionKey' => 'searchAppearance', 'dimensionValue' => $searchAppearance],
                ]);
                $channeledMetric->metricConfigKey = $metricConfigKeys[$metricName]; // Pass the actual metricConfigKey
                $channeledMetric->metadata = $aggregatedMetadata[$metricConfigKeys['impressions']];
                $channeledMetric->data = [
                    'impressions' => $aggregatedMetrics[$metricConfigKeys['impressions']]['impressions'],
                    'clicks' => $aggregatedMetrics[$metricConfigKeys['impressions']]['clicks'],
                    'position_weighted' => $aggregatedMetrics[$metricConfigKeys['impressions']]['position'] * $aggregatedMetrics[$metricConfigKeys['impressions']]['impressions'],
                    'ctr' => $aggregatedMetrics[$metricConfigKeys['impressions']]['ctr'],
                ];

                $logger?->warning("Temp data for country: ".$row['keys'][$flippedDimensions['country']]." and device ".$row['keys'][$flippedDimensions['device']].": " . json_encode($aggregatedMetrics[$metricConfigKeys['impressions']]));

                $elements[$metricConfigKeys['impressions']][$metricName] = $channeledMetric;
            }

            if ($index % 1000 === 0) {
                $rowTime = microtime(true) - $rowStart;
                $memory = memory_get_usage() / 1024 / 1024;
                // $logger?->info("Converted row $index/$rowCount, created " . $collection->count() . " metrics, took $rowTime seconds, memory: $memory MB");
            }
        }

        foreach ($elements as $element) {
            foreach ($element as $metricNameElement) {
                $collection->add($metricNameElement);
            }
        }

        $totalTime = microtime(true) - $startTime;
        $memory = memory_get_usage() / 1024 / 1024;
        $logger?->info("Completed metrics conversion: $rowCount rows to " . $collection->count() . " metrics, skipped $skippedRows rows, took $totalTime seconds, memory: $memory MB");

        return $collection;
    }

    /**
     * Aggregates metrics from two data arrays.
     *
     * @param array $data Existing data to aggregate into
     * @param array $new New data to aggregate
     * @return array Aggregated data
     */
    public static function aggregateMetrics(array $data, array $new): array
    {
        // Sum additive metrics
        $totalImpressions = (int)($data['impressions'] ?? 0) + (int)($new['impressions'] ?? 0);
        $totalClicks = (int)($data['clicks'] ?? 0) + (int)($new['clicks'] ?? 0);

        // Recalculate CTR
        $data['ctr'] = $totalImpressions > 0
            ? $totalClicks / $totalImpressions
            : 0;

        // Recalculate weighted position
        $totalWeightedPosition = ((float)($data['position'] ?? 0) * (int)($data['impressions'] ?? 0))
            + ((float)($new['position'] ?? 0) * (int)($new['impressions'] ?? 0));

        $data['position'] = $totalImpressions > 0 ? $totalWeightedPosition / $totalImpressions : 0;

        // Update remaining fields
        $data['impressions'] = $totalImpressions;
        $data['clicks'] = $totalClicks;
        $data['count'] = (int)($data['count'] ?? 0) + 1;

        return $data;
    }

}
