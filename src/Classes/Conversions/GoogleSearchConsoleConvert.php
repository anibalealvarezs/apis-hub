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
        // $logger?->info("Page entity for site $siteUrl: " . ($pageEntity ? "ID={$pageEntity->getId()}, URL={$pageEntity->getUrl()}" : 'none'));
        $pageEntity = $em->find(Page::class, $pageEntity->getId());
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
                        'query' => 'unknown',
                        'country' => Country::UNK->value,
                        'page' => null,
                        'device' => Device::UNKNOWN->value,
                    };
                }
                $dimensionValues[$dimension] = $row['keys'][$flippedDimensions[$dimension]];
            }
            $impressionsGroupKey = KeyGenerator::generateMetricKey(
                channel: Channel::google_search_console->value,
                name: 'impressions',
                period: Period::Daily->value,
                metricDate: Carbon::parse($dimensionValues['date'])->toDateString(),
                page:  $pageEntity->getUrl(),
                query: $dimensionValues['query'],
                country: $dimensionValues['country'],
                device: $dimensionValues['device'],
            );

            list($impressions, $clicks, $position, $ctr) = GoogleSearchConsoleHelpers::getMetricsValues($row);
            if (isset($aggregatedMetrics[$impressionsGroupKey])) {
                // Aggregate existing row
                $aggregatedMetrics[$impressionsGroupKey] = self::aggregateMetrics(
                    $aggregatedMetrics[$impressionsGroupKey],
                    [
                        'impressions' => $impressions,
                        'clicks' => $clicks,
                        'position' => $position,
                    ]
                );
                $aggregatedMetadata[$impressionsGroupKey][] = [
                    ...$aggregatedMetrics[$impressionsGroupKey],
                    'keys' => $row['keys'],
                    'subset' => $row['subset'],
                    'synthetic' => $row['synthetic'] ?? false,
                    'impressions_difference' => $row['impressions_difference'],
                    'clicks_difference' => $row['clicks_difference'],
                ];
            } else {
                $aggregatedMetrics[$impressionsGroupKey] = [
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'position' => $position,
                    "ctr" => $ctr,
                    'count' => 1,
                ];
                $aggregatedMetadata[$impressionsGroupKey] = [
                    ...$aggregatedMetrics[$impressionsGroupKey],
                    'keys' => $row['keys'],
                    'subset' => $row['subset'],
                    'synthetic' => $row['synthetic'] ?? false,
                    'impressions_difference' => $row['impressions_difference'],
                    'clicks_difference' => $row['clicks_difference'],
                ];
            }

            $platformId = "gsc_{$siteKey}_$impressionsGroupKey";
            foreach (['clicks', 'impressions', 'ctr', 'position'] as $metricName) {
                $channeledMetric = new stdClass();
                $channeledMetric->channel = Channel::google_search_console->value;
                $channeledMetric->name = $metricName;
                $channeledMetric->value = $aggregatedMetrics[$impressionsGroupKey][$metricName];
                $channeledMetric->period = Period::Daily->value;
                $channeledMetric->metricDate = Carbon::parse($row['keys'][$flippedDimensions['date']])->toDateString();
                $channeledMetric->platformId = $platformId;
                $channeledMetric->platformCreatedAt = Carbon::parse($row['keys'][$flippedDimensions['date']])->toDateTimeString();
                $channeledMetric->query = $row['keys'][$flippedDimensions['query']];
                $channeledMetric->countryCode = $row['keys'][$flippedDimensions['country']];
                $channeledMetric->deviceType = $row['keys'][$flippedDimensions['device']];
                $channeledMetric->page = $pageEntity;
                $channeledMetric->dimensions = [
                    (object) ['dimensionKey' => 'page', 'dimensionValue' => $row['keys'][$flippedDimensions['page']]],
                    (object) ['dimensionKey' => 'searchAppearance', 'dimensionValue' => $searchAppearance],
                ];
                $channeledMetric->metadata = $aggregatedMetadata[$impressionsGroupKey];
                $channeledMetric->data = [
                    'impressions' => $aggregatedMetrics[$impressionsGroupKey]['impressions'],
                    'clicks' => $aggregatedMetrics[$impressionsGroupKey]['clicks'],
                    'position_weighted' => $aggregatedMetrics[$impressionsGroupKey]['position'] * $aggregatedMetrics[$impressionsGroupKey]['impressions'],
                    'ctr' => $aggregatedMetrics[$impressionsGroupKey]['ctr'],
                ];

                $logger?->warning("Temp data for country: ".$row['keys'][$flippedDimensions['country']]." and device ".$row['keys'][$flippedDimensions['device']].": " . json_encode($aggregatedMetrics[$impressionsGroupKey]));

                $elements[$impressionsGroupKey][$metricName] = $channeledMetric;
            }

            if ($index % 1000 === 0) {
                $rowTime = microtime(true) - $rowStart;
                $memory = memory_get_usage() / 1024 / 1024;
                // $logger?->info("Converted row $index/$rowCount, created " . $collection->count() . " metrics, took $rowTime seconds, memory: $memory MB");
            }
        }

        foreach($elements as $element) {
            foreach($element as $metricNameElement) {
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
    public static function aggregateMetrics(array $data, array $new): array {
        // Sum additive metrics
        $totalImpressions = $data['impressions'] + $new['impressions'];
        $totalClicks = $data['clicks'] + $new['clicks'];

        // Recalculate CTR
        $data['ctr'] = $totalImpressions > 0
            ? $totalClicks / $totalImpressions
            : 0;

        // Recalculate weighted position
        $totalWeightedPosition = ($data['position'] * $data['impressions']) // Impressions include previous and new
            + ($new['position'] * $new['impressions']);
        $data['position'] = $totalImpressions > 0 ? $totalWeightedPosition / $totalImpressions : 0;

        // Update remaining fields
        $data['impressions'] = $totalImpressions;
        $data['clicks'] = $totalClicks;
        $data['count']++;

        return $data;
    }
}