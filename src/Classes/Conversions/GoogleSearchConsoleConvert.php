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
     * @return ArrayCollection
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    public static function metrics(
        array $rows,
        string $siteUrl,
        string $siteKey,
        array $targetKeywords = [],
        array $targetCountries = [],
        LoggerInterface $logger = null,
        ?Page $pageEntity = null,
        ?EntityManager $em = null
    ): ArrayCollection {
        $startTime = microtime(true);
        $rowCount = count($rows);
        $collection = new ArrayCollection();
        $skippedRows = 0;

        $logger?->info("Starting metrics conversion for site $siteUrl, rows=$rowCount");
        $logger?->warning("Note: 'searchAppearance' not fetched from GSC API due to dimension restrictions; defaulting to 'WEB' in ChanneledMetricDimension");
        $logger?->info("Page entity for site $siteUrl: " . ($pageEntity ? "ID={$pageEntity->getId()}, URL={$pageEntity->getUrl()}" : 'none'));
        if ($em && $pageEntity && $pageEntity->getId()) {
            $isManaged = $em->getUnitOfWork()->isInIdentityMap($pageEntity) || $em->getUnitOfWork()->isEntityScheduled($pageEntity);
            $logger?->info("EntityManager open: " . ($em->isOpen() ? 'yes' : 'no') . ", instance: " . spl_object_hash($em));
            $logger?->info("pageEntity managed: " . ($isManaged ? 'yes' : 'no'));
            if (!$isManaged && $em->isOpen()) {
                $pageEntity = $em->find(\Entities\Analytics\Page::class, $pageEntity->getId());
                if (!$pageEntity) {
                    $logger?->error("Failed to re-find pageEntity in metrics, ID: " . $pageEntity->getId());
                    $pageEntity = null;
                } else {
                    $logger?->info("Re-found pageEntity in metrics: " . $pageEntity->__toString());
                }
            }
        }

        $md5Cache = [];

        foreach ($rows as $index => $row) {
            $rowStart = microtime(true);

            if (!isset($row['keys']) || !is_array($row['keys'])) {
                $skippedRows++;
                $logger?->warning("Skipped row $index due to missing or invalid keys: " . json_encode($row));
                continue;
            }

            $date = $row['keys'][0] ?? null;
            $queryTerm = $row['keys'][1] ?? null;
            if (!$date || !$queryTerm) {
                $skippedRows++;
                if ($index % 1000 === 0) {
                    $logger?->warning("Skipped row $index due to missing date or query: " . json_encode($row));
                }
                continue;
            }

            $queryTerm = empty($targetKeywords) || Helpers::str_contains_any($queryTerm, $targetKeywords) ? $queryTerm : 'others';
            $countryCode = isset($row['keys'][3]) && (empty($targetCountries) || in_array(strtolower($row['keys'][3]), $targetCountries)) ? strtoupper($row['keys'][3]) : 'OTH';
            $page = $row['keys'][2] ?? 'unknown';
            $device = isset($row['keys'][4]) ? strtolower($row['keys'][4]) : 'other';
            $searchAppearance = 'WEB';

            $pageKey = $page ?: 'unknown';
            $md5Page = $md5Cache[$pageKey] ?? ($md5Cache[$pageKey] = md5($pageKey));

            $groupKey = md5(json_encode([
                'date' => $date,
                'query' => $queryTerm,
                'page' => $page,
                'country' => $countryCode,
                'device' => $device
            ], JSON_UNESCAPED_UNICODE));
            if (isset($aggregatedRows[$groupKey])) {
                $aggregatedRows[$groupKey]['clicks'] += $row['clicks'] ?? 0;
                $aggregatedRows[$groupKey]['impressions'] += $row['impressions'] ?? 0;
                $aggregatedRows[$groupKey]['position'] += ($row['position'] ?? 0) * ($row['impressions'] ?? 0);
                $aggregatedRows[$groupKey]['count']++;
                $logger?->info("Aggregated duplicate row $index for query=$queryTerm, page=$page");
                continue;
            }
            $impressions = $row['impressions'] ?? 0;
            $clicks = $row['clicks'] ?? 0;
            $position = $row['position'] ?? 0;

            $platformId = "gsc_{$siteKey}_{$groupKey}";
            foreach (['clicks', 'impressions', 'ctr', 'position'] as $metricName) {
                $value = match ($metricName) {
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'ctr' => $impressions > 0 ? $clicks / $impressions : 0,
                    'position' => $impressions > 0 ? $position : 0,
                };

                if ($value === 0 && $metricName === 'position') {
                    $logger?->info("Skipped $metricName metric for row $index, value=0");
                    continue;
                }

                $channeledMetric = new stdClass();
                $channeledMetric->channel = Channel::google_search_console->value;
                $channeledMetric->name = $metricName;
                $channeledMetric->value = $value;
                $channeledMetric->period = Period::Daily->value;
                $channeledMetric->metricDate = new DateTime($date);
                $channeledMetric->platformId = $platformId;
                $channeledMetric->platformCreatedAt = new DateTime($date);
                $channeledMetric->query = $queryTerm;
                $channeledMetric->countryCode = $countryCode;
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
}