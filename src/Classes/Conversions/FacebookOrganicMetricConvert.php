<?php

declare(strict_types=1);

namespace Classes\Conversions;

use Carbon\Carbon;
use Classes\KeyGenerator;
use Doctrine\Common\Collections\ArrayCollection;
use Entities\Analytics\Account;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Page;
use Entities\Analytics\Post;
use Enums\Channel;
use Enums\Period;
use Psr\Log\LoggerInterface;
use stdClass;

class FacebookOrganicMetricConvert
{
    /**
     * Converts Facebook Page API rows into a collection of metric objects.
     *
     * @param array $rows
     * @param string $pagePlatformId
     * @param string $postPlatformId
     * @param LoggerInterface|null $logger
     * @param Page|null $pageEntity
     * @param Post|null $postEntity
     * @param Period $period
     * @return ArrayCollection
     */
    public static function pageMetrics(
        array $rows,
        string $pagePlatformId = '',
        string $postPlatformId = '',
        ?LoggerInterface $logger = null,
        ?Page $pageEntity = null,
        ?Post $postEntity = null,
        Period $period = Period::Daily,
    ): ArrayCollection {
        $startTime = microtime(true);
        $rowCount = count($rows);
        $collection = new ArrayCollection();
        $skippedRows = 0;

        $platformId = $pagePlatformId ?: $postPlatformId;

        $logger?->info("Starting metrics conversion for platformId $platformId, rows=$rowCount");
        $elements = [];
        foreach ($rows as $index => $row) {
            $rowStart = microtime(true);
            $values = $row['values'] ?? [];
            if (!is_array($values)) {
                $logger?->warning("Row '{$row['name']}' values is not an array for metric " . ($row['name'] ?? 'unknown'));
                continue;
            }
            foreach ($values as $value) {
                $endTime = $value['end_time'] ?? null;
                $metricDate = $endTime ? Carbon::parse($endTime)->toDateString() : Carbon::now()->toDateString();
                $metricConfigsGroupKey = KeyGenerator::generateMetricConfigKey(
                    channel: Channel::facebook_organic->value,
                    name: $row['name'] ?? 'unknown',
                    period: $period->value,
                    metricDate: $metricDate,
                    page:  $pageEntity?->getUrl(),
                    post: $postEntity?->getPostId(),
                );
                $channeledMetric = new stdClass();
                $channeledMetric->channel = Channel::facebook_organic->value;
                $channeledMetric->name = $row['name'] ?? 'unknown';
                $channeledMetric->value = $value['value'] ?? 0;
                $channeledMetric->period = $period->value;
                $channeledMetric->metricDate = $metricDate;
                $channeledMetric->platformId = $platformId;
                $channeledMetric->platformCreatedAt = $metricDate;
                $channeledMetric->page = $pageEntity;
                $channeledMetric->post = $postEntity;
                $channeledMetric->dimensions = [];
                $channeledMetric->dimensionsHash = KeyGenerator::generateDimensionsHash([]);
                $channeledMetric->metricConfigKey = $metricConfigsGroupKey;
                $channeledMetric->metadata = $row;
                $channeledMetric->data = $row;

                if (!isset($elements[$metricConfigsGroupKey][$row['name']])) {
                    $elements[$metricConfigsGroupKey][$row['name']] = [];
                }
                $elements[$metricConfigsGroupKey][$row['name']][] = $channeledMetric;
            }
        }

        foreach ($elements as $element) {
            foreach ($element as $metricNameElement) {
                foreach ($metricNameElement as $metricElement) {
                    $collection->add($metricElement);
                }
            }
        }

        $totalTime = microtime(true) - $startTime;
        $memory = memory_get_usage() / 1024 / 1024;
        $logger?->info("Completed metrics conversion: $rowCount rows to " . $collection->count() . " metrics, skipped $skippedRows rows, took $totalTime seconds, memory: $memory MB");

        return $collection;
    }

    /**
     * Converts Instagram Account API rows into a collection of metric objects.
     *
     * @param array $rows
     * @param string $date
     * @param Page|null $pageEntity
     * @param Account|null $accountEntity
     * @param ChanneledAccount|null $channeledAccountEntity
     * @param LoggerInterface|null $logger
     * @param Period $period
     * @return ArrayCollection
     */
    public static function igAccountMetrics(
        array $rows,
        string $date,
        ?Page $pageEntity,
        ?Account $accountEntity,
        ?ChanneledAccount $channeledAccountEntity,
        ?LoggerInterface $logger = null,
        Period $period = Period::Daily,
    ): ArrayCollection {
        $startTime = microtime(true);
        $rowCount = count($rows);
        $collection = new ArrayCollection();
        $skippedRows = 0;

        $elements = [];
        foreach ($rows as $index => $row) {
            $rowStart = microtime(true);
            $dateCarbon = Carbon::parse($date);
            $metricDateString = $dateCarbon->toDateString();
            $metricConfigKey = KeyGenerator::generateMetricConfigKey(
                channel: Channel::facebook_organic->value,
                name: $row['name'] ?? 'unknown',
                period: $period->value,
                metricDate: $metricDateString,
                account: $accountEntity->getName(),
                channeledAccount:  $channeledAccountEntity->getPlatformId(),
                page:  $pageEntity?->getUrl(),
            );
            if (!isset($elements[$metricConfigKey])) {
                $elements[$metricConfigKey] = [];
            }
            if (!isset($elements[$metricConfigKey][$row['name']])) {
                $elements[$metricConfigKey][$row['name']] = [];
            }
            $channeledMetric = new stdClass();
            $channeledMetric->channel = Channel::facebook_organic->value;
            $channeledMetric->name = $row['name'] ?? 'unknown';
            $channeledMetric->period = $period->value;
            $channeledMetric->metricDate = $metricDateString;
            $channeledMetric->platformId = $channeledAccountEntity->getPlatformId();
            $channeledMetric->platformCreatedAt = $metricDateString;
            $channeledMetric->account = $accountEntity;
            $channeledMetric->channeledAccount = $channeledAccountEntity;
            $channeledMetric->page = $pageEntity;
            $channeledMetric->dimensions = [];
            $channeledMetric->metricConfigKey = $metricConfigKey;
            $channeledMetric->metadata = [];
            $channeledMetric->data = $row;
            if (isset($row['total_value']['breakdowns'])) {
                $breakdowns = $row['total_value']['breakdowns'][0]['dimension_keys'];
                if (isset($row['total_value']['breakdowns'][0]['results'])) {
                    foreach ($row['total_value']['breakdowns'][0]['results'] as $vector) {
                        $channeledMetric->value = is_array($vector['value']) ? ($vector['value'][0]['value'] ?? ($vector['value'][0]['amount'] ?? 0)) : $vector['value'];
                        $dimensions = [];
                        foreach ($breakdowns as $key => $breakdown) {
                            $dimensions[] = [
                                'dimensionKey' => $breakdown,
                                'dimensionValue' => $vector['dimension_values'][$key],
                            ];
                        }
                        $channeledMetric->dimensions = $dimensions;
                        $channeledMetric->dimensionsHash = KeyGenerator::generateDimensionsHash($dimensions);

                        if (!isset($elements[$metricConfigKey][$row['name']])) {
                            $elements[$metricConfigKey][$row['name']] = [];
                        }
                        $elements[$metricConfigKey][$row['name']][] = $channeledMetric;
                    }
                } else {
                    $skippedRows++;
                    $logger?->warning("Skipping row $index/$rowCount, platformId: {$channeledAccountEntity->getPlatformId()}(), no breakdowns found for metric " . $row['name']);
                }
            } else {
                $channeledMetric->value = $row['total_value']['value'] ?? 0;
                $channeledMetric->dimensionsHash = KeyGenerator::generateDimensionsHash([]);

                $elements[$metricConfigKey][$row['name']][] = $channeledMetric;
            }
        }

        foreach ($elements as $element) {
            foreach ($element as $metricNameElement) {
                foreach ($metricNameElement as $metricElement) {
                    $collection->add($metricElement);
                }
            }
        }

        $totalTime = microtime(true) - $startTime;
        $memory = memory_get_usage() / 1024 / 1024;
        $logger?->info("Completed metrics conversion: $rowCount rows to " . $collection->count() . " metrics, skipped $skippedRows rows, took $totalTime seconds, memory: $memory MB");

        return $collection;
    }

    /**
     * Converts Instagram Media API rows into a collection of metric objects.
     *
     * @param array $rows
     * @param Page|null $pageEntity
     * @param Post|null $postEntity
     * @param Account|null $accountEntity
     * @param ChanneledAccount|null $channeledAccountEntity
     * @param LoggerInterface|null $logger
     * @return ArrayCollection
     */
    public static function igMediaMetrics(
        array $rows,
        ?Page $pageEntity,
        ?Post $postEntity,
        ?Account $accountEntity,
        ?ChanneledAccount $channeledAccountEntity,
        ?LoggerInterface $logger = null,
    ): ArrayCollection {
        $startTime = microtime(true);
        $rowCount = count($rows);
        $collection = new ArrayCollection();
        $skippedRows = 0;

        $elements = [];
        foreach ($rows as $index => $row) {
            $rowStart = microtime(true);
            $today = Carbon::today()->toDateString();
            $metricConfigKey = KeyGenerator::generateMetricConfigKey(
                channel: Channel::facebook_organic->value,
                name: $row['name'] ?? 'unknown',
                period: Period::Lifetime->value,
                metricDate: $today,
                account: $accountEntity->getName(),
                channeledAccount:  $channeledAccountEntity->getPlatformId(),
                page:  $pageEntity->getUrl(),
                post: $postEntity->getPostId(),
            );
            if (!isset($elements[$metricConfigKey])) {
                $elements[$metricConfigKey] = [];
            }
            $channeledMetric = new stdClass();
            $channeledMetric->channel = Channel::facebook_organic->value;
            $channeledMetric->name = $row['name'] ?? 'unknown';
            $channeledMetric->period = Period::Lifetime->value;
            $channeledMetric->metricDate = $today;
            $channeledMetric->platformId = $postEntity->getPostId();
            $channeledMetric->platformCreatedAt = $today;
            $channeledMetric->account = $accountEntity;
            $channeledMetric->channeledAccount = $channeledAccountEntity;
            $channeledMetric->page = $pageEntity;
            $channeledMetric->post = $postEntity;
            $channeledMetric->dimensions = [];
            $channeledMetric->metricConfigKey = $metricConfigKey;
            $channeledMetric->metadata = [];
            $channeledMetric->data = $row;
            $channeledMetric->value = $row['values'][0]['value'] ?? 0;
            $channeledMetric->dimensionsHash = KeyGenerator::generateDimensionsHash([]);

            if (!isset($elements[$metricConfigKey][$row['name']])) {
                $elements[$metricConfigKey][$row['name']] = [];
            }
            $elements[$metricConfigKey][$row['name']][] = $channeledMetric;
        }

        foreach ($elements as $element) {
            foreach ($element as $metricNameElement) {
                foreach ($metricNameElement as $metricElement) {
                    $collection->add($metricElement);
                }
            }
        }

        $totalTime = microtime(true) - $startTime;
        $memory = memory_get_usage() / 1024 / 1024;
        $logger?->info("Completed metrics conversion: $rowCount rows to " . $collection->count() . " metrics, skipped $skippedRows rows, took $totalTime seconds, memory: $memory MB");

        return $collection;
    }
}
