<?php

namespace Classes\Conversions;

use Carbon\Carbon;
use Classes\KeyGenerator;
use Doctrine\Common\Collections\ArrayCollection;
use Entities\Analytics\Account;
use Entities\Analytics\Campaign;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Channeled\ChanneledAd;
use Entities\Analytics\Channeled\ChanneledAdGroup;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Entities\Analytics\Page;
use Entities\Analytics\Post;
use Enums\Channel;
use Enums\Period;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;
use stdClass;

class FacebookGraphConvert
{
    /**
     * Converts GSC API rows into a collection of metric objects.
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
        LoggerInterface $logger = null,
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
        // $logger?->warning("Note: 'searchAppearance' not fetched from GSC API due to dimension restrictions; defaulting to 'WEB' in ChanneledMetricDimension");
        // $logger?->info("Page entity for site $siteUrl: " . ($pageEntity ? "ID={$pageEntity->getId()}, URL={$pageEntity->getUrl()}" : 'none'));
        $elements = [];
        foreach ($rows as $index => $row) {
            $rowStart = microtime(true);
            foreach ($row['values'] as $value) {
                $metricConfigsGroupKey = KeyGenerator::generateMetricConfigKey(
                    channel: Channel::facebook->value,
                    name: $row['name'],
                    period: $period->value,
                    metricDate: isset($value['end_time']) ? Carbon::parse($value['end_time'])->toDateString() : Carbon::now()->toDateString(),
                    page:  $pageEntity?->getUrl(),
                    post: $postEntity?->getPostId(),
                );
                $channeledMetric = new stdClass();
                $channeledMetric->channel = Channel::facebook->value;
                $channeledMetric->name = $row['name'];
                $channeledMetric->value = $value['value'];
                $channeledMetric->period = $period->value;
                $channeledMetric->metricDate = isset($value['end_time']) ? Carbon::parse($value['end_time'])->toDateString() : Carbon::now()->toDateString();
                $channeledMetric->platformId = $platformId;
                $channeledMetric->platformCreatedAt = isset($value['end_time']) ? Carbon::parse($value['end_time'])->toDateString() : Carbon::now()->toDateString();
                $channeledMetric->page = $pageEntity;
                $channeledMetric->post = $postEntity;
                $channeledMetric->dimensions = [];
                $channeledMetric->dimensionsHash = KeyGenerator::generateDimensionsHash([]);
                $channeledMetric->metricConfigKey = $metricConfigsGroupKey;
                $channeledMetric->metadata = $row;
                $channeledMetric->data = [];

                if (!isset($elements[$metricConfigsGroupKey][$row['name']])) {
                    $elements[$metricConfigsGroupKey][$row['name']] = [];
                }
                $elements[$metricConfigsGroupKey][$row['name']][] = $channeledMetric;

                if ($index % 1000 === 0) {
                    $rowTime = microtime(true) - $rowStart;
                    $memory = memory_get_usage() / 1024 / 1024;
                    // $logger?->info("Converted row $index/$rowCount, created " . $collection->count() . " metrics, took $rowTime seconds, memory: $memory MB");
                }
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
     * Converts GSC API rows into a collection of metric objects.
     *
     * @param array $rows
     * @param LoggerInterface|null $logger
     * @param Account|null $accountEntity
     * @param string|null $channeledAccountPlatformId
     * @param Period $period
     * @return ArrayCollection
     */
    public static function adAccountMetrics(
        array $rows,
        LoggerInterface $logger = null,
        Account $accountEntity = null,
        string $channeledAccountPlatformId = null,
        Period $period = Period::Daily,
    ): ArrayCollection {
        $startTime = microtime(true);
        $rowCount = count($rows);
        $collection = new ArrayCollection();
        $skippedRows = 0;

        $metricsList = ['impressions', 'clicks', 'cpc', 'ctr', 'cpm', 'spend', 'reach', 'frequency', 'unique_clicks', 'unique_ctr', 'cost_per_unique_click', 'cost_per_inline_link_click', 'cost_per_unique_outbound_click'];
        $breakdowns = ['age', 'gender'];
        $metadataFields = ['actions', 'cost_per_action_type'];

        $logger?->info("Starting metrics conversion for platformId $channeledAccountPlatformId, rows=$rowCount");
        // $logger?->warning("Note: 'searchAppearance' not fetched from GSC API due to dimension restrictions; defaulting to 'WEB' in ChanneledMetricDimension");
        // $logger?->info("Page entity for site $siteUrl: " . ($pageEntity ? "ID={$pageEntity->getId()}, URL={$pageEntity->getUrl()}" : 'none'));
        $elements = [];
        // Helpers::dumpDebugJson($rows);
        foreach ($rows as $index => $row) {
            $rowStart = microtime(true);
            $dimensions = [];
            foreach ($breakdowns as $breakdown) {
                if (!isset($row[$breakdown])) {
                    $logger?->warning("Skipping breakdown $breakdown for row $index of Ad Account metrics due to missing value");
                    continue;
                }
                $dimensions[] = [
                    'dimensionKey' => $breakdown,
                    'dimensionValue' => $row[$breakdown],
                ];
            }
            $metadata = array_filter($row, function ($key) use ($metadataFields) {
                return in_array($key, $metadataFields);
            }, ARRAY_FILTER_USE_KEY);
            foreach ($row as $key => $value) {
                if (!in_array($key, $metricsList)) {
                    continue;
                }
                $metricConfigsGroupKey = KeyGenerator::generateMetricConfigKey(
                    channel: Channel::facebook->value,
                    name: $key,
                    period: $period->value,
                    metricDate: Carbon::parse($row['date_start'])->toDateString(),
                    account: $accountEntity,
                    channeledAccount:  $channeledAccountPlatformId,
                );
                $channeledMetric = new stdClass();
                $channeledMetric->channel = Channel::facebook->value;
                $channeledMetric->name = $key;
                $channeledMetric->value = ($key == 'cost_per_unique_outbound_click') && is_array($value) ? $value[0]['value'] : $value;
                $channeledMetric->period = $period->value;
                $channeledMetric->metricDate = Carbon::parse($row['date_start'])->toDateString();
                $channeledMetric->platformId = $channeledAccountPlatformId;
                $channeledMetric->platformCreatedAt = Carbon::parse($row['date_start'])->toDateString();
                $channeledMetric->dimensions = $dimensions;
                $channeledMetric->dimensionsHash = KeyGenerator::generateDimensionsHash($dimensions);
                $channeledMetric->metricConfigKey = $metricConfigsGroupKey;
                $channeledMetric->metadata = $metadata;
                $channeledMetric->data = [];

                if (!isset($elements[$metricConfigsGroupKey][$key])) {
                    $elements[$metricConfigsGroupKey][$key] = [];
                }
                $elements[$metricConfigsGroupKey][$key][] = $channeledMetric;

                if ($index % 1000 === 0) {
                    $rowTime = microtime(true) - $rowStart;
                    $memory = memory_get_usage() / 1024 / 1024;
                    // $logger?->info("Converted row $index/$rowCount, created " . $collection->count() . " metrics, took $rowTime seconds, memory: $memory MB");
                }
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
     * Converts GSC API rows into a collection of metric objects.
     *
     * @param array $rows
     * @param string $date
     * @param LoggerInterface|null $logger
     * @param Page|null $pageEntity
     * @param Account|null $accountEntity
     * @param ChanneledAccount|null $channeledAccountEntity
     * @param Period $period
     * @return ArrayCollection
     */
    public static function igAccountMetrics(
        array $rows,
        string $date,
        ?Page $pageEntity,
        ?Account $accountEntity,
        ?ChanneledAccount $channeledAccountEntity,
        LoggerInterface $logger = null,
        Period $period = Period::Daily,
    ): ArrayCollection {
        $startTime = microtime(true);
        $rowCount = count($rows);
        $collection = new ArrayCollection();
        $skippedRows = 0;

        // $logger?->warning("Note: 'searchAppearance' not fetched from GSC API due to dimension restrictions; defaulting to 'WEB' in ChanneledMetricDimension");
        // $logger?->info("Page entity for site $siteUrl: " . ($pageEntity ? "ID={$pageEntity->getId()}, URL={$pageEntity->getUrl()}" : 'none'));
        $elements = [];
        foreach ($rows as $index => $row) {
            $rowStart = microtime(true);
            $metricConfigKey = KeyGenerator::generateMetricConfigKey(
                channel: Channel::facebook->value,
                name: $row['name'],
                period: $period->value,
                metricDate: Carbon::parse($date)->toDateString(),
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
            $channeledMetric->channel = Channel::facebook->value;
            $channeledMetric->name = $row['name'];
            $channeledMetric->period = $period->value;
            $channeledMetric->metricDate = Carbon::parse($date)->toDateString();
            $channeledMetric->platformId = $channeledAccountEntity->getPlatformId();
            $channeledMetric->platformCreatedAt = Carbon::parse($date)->toDateString();
            $channeledMetric->account = $accountEntity;
            $channeledMetric->channeledAccount = $channeledAccountEntity;
            $channeledMetric->page = $pageEntity;
            $channeledMetric->dimensions = [];
            $channeledMetric->metricConfigKey = $metricConfigKey;
            $channeledMetric->metadata = [];
            $channeledMetric->data = [];
            if (isset($row['total_value']['breakdowns'])) {
                $breakdowns = $row['total_value']['breakdowns'][0]['dimension_keys'];
                if (isset($row['total_value']['breakdowns'][0]['results'])) {
                    foreach ($row['total_value']['breakdowns'][0]['results'] as $vector) {
                        $channeledMetric->value = $vector['value'];
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

                        if ($index % 1000 === 0) {
                            $rowTime = microtime(true) - $rowStart;
                            $memory = memory_get_usage() / 1024 / 1024;
                            // $logger?->info("Converted row $index/$rowCount, created " . $collection->count() . " metrics, took $rowTime seconds, memory: $memory MB");
                        }
                    }
                } else {
                    $skippedRows++;
                    $logger?->warning("Skipping row $index/$rowCount, platformId: {$channeledAccountEntity->getPlatformId()}(), no breakdowns found for metric " . $row['name']);
                }
            } else {
                $channeledMetric->value = $row['total_value']['value'];
                $channeledMetric->dimensionsHash = KeyGenerator::generateDimensionsHash([]);

                $elements[$metricConfigKey][$row['name']][] = $channeledMetric;

                if ($index % 1000 === 0) {
                    $rowTime = microtime(true) - $rowStart;
                    $memory = memory_get_usage() / 1024 / 1024;
                    // $logger?->info("Converted row $index/$rowCount, created " . $collection->count() . " metrics, took $rowTime seconds, memory: $memory MB");
                }
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
     * Converts GSC API rows into a collection of metric objects.
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
        LoggerInterface $logger = null,
    ): ArrayCollection {
        $startTime = microtime(true);
        $rowCount = count($rows);
        $collection = new ArrayCollection();
        $skippedRows = 0;

        // $logger?->warning("Note: 'searchAppearance' not fetched from GSC API due to dimension restrictions; defaulting to 'WEB' in ChanneledMetricDimension");
        // $logger?->info("Page entity for site $siteUrl: " . ($pageEntity ? "ID={$pageEntity->getId()}, URL={$pageEntity->getUrl()}" : 'none'));
        $elements = [];
        foreach ($rows as $index => $row) {
            $rowStart = microtime(true);
            $metricConfigKey = KeyGenerator::generateMetricConfigKey(
                channel: Channel::facebook->value,
                name: $row['name'],
                period: Period::Lifetime->value,
                metricDate: Carbon::today()->toDateString(),
                account: $accountEntity->getName(),
                channeledAccount:  $channeledAccountEntity->getPlatformId(),
                page:  $pageEntity->getUrl(),
                post: $postEntity->getPostId(),
            );
            if (!isset($elements[$metricConfigKey])) {
                $elements[$metricConfigKey] = [];
            }
            $channeledMetric = new stdClass();
            $channeledMetric->channel = Channel::facebook->value;
            $channeledMetric->name = $row['name'];
            $channeledMetric->period = Period::Lifetime->value;
            $channeledMetric->metricDate = Carbon::today()->toDateString();
            $channeledMetric->platformId = $channeledAccountEntity->getPlatformId();
            $channeledMetric->platformCreatedAt = Carbon::today()->toDateString();
            $channeledMetric->account = $accountEntity;
            $channeledMetric->channeledAccount = $channeledAccountEntity;
            $channeledMetric->page = $pageEntity;
            $channeledMetric->post = $postEntity;
            $channeledMetric->dimensions = [];
            $channeledMetric->metricConfigKey = $metricConfigKey;
            $channeledMetric->metadata = [];
            $channeledMetric->data = [];
            $channeledMetric->value = $row['values'][0]['value'];
            $channeledMetric->dimensionsHash = KeyGenerator::generateDimensionsHash([]);

            if (!isset($elements[$metricConfigKey][$row['name']])) {
                $elements[$metricConfigKey][$row['name']] = [];
            }
            $elements[$metricConfigKey][$row['name']][] = $channeledMetric;

            if ($index % 1000 === 0) {
                $rowTime = microtime(true) - $rowStart;
                $memory = memory_get_usage() / 1024 / 1024;
                // $logger?->info("Converted row $index/$rowCount, created " . $collection->count() . " metrics, took $rowTime seconds, memory: $memory MB");
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
     * Converts GSC API rows into a collection of metric objects.
     *
     * @param array $rows
     * @param LoggerInterface|null $logger
     * @param ChanneledAccount|null $channeledAccountEntity
     * @param Campaign|null $campaignEntity
     * @param ChanneledCampaign|null $channeledCampaignEntity
     * @param Period $period
     * @return ArrayCollection
     */
    public static function campaignMetrics(
        array $rows,
        LoggerInterface $logger = null,
        ChanneledAccount $channeledAccountEntity = null,
        Campaign $campaignEntity = null,
        ChanneledCampaign $channeledCampaignEntity = null,
        Period $period = Period::Daily,
    ): ArrayCollection {
        $startTime = microtime(true);
        $rowCount = count($rows);
        $collection = new ArrayCollection();
        $skippedRows = 0;

        $metricsList = ['impressions', 'clicks', 'cpc', 'ctr', 'cpm', 'spend', 'reach', 'frequency'];
        $breakdowns = ['age', 'gender'];
        $metadataFields = ['actions', 'cost_per_action_type'];

        $logger?->info("Starting metrics conversion for platformId {$channeledCampaignEntity->getPlatformId()}, rows=$rowCount");
        // $logger?->warning("Note: 'searchAppearance' not fetched from GSC API due to dimension restrictions; defaulting to 'WEB' in ChanneledMetricDimension");
        // $logger?->info("Page entity for site $siteUrl: " . ($pageEntity ? "ID={$pageEntity->getId()}, URL={$pageEntity->getUrl()}" : 'none'));
        $elements = [];
        // Helpers::dumpDebugJson($rows);
        foreach ($rows as $index => $row) {
            $rowStart = microtime(true);
            $dimensions = [];
            foreach ($breakdowns as $breakdown) {
                if (!isset($row[$breakdown])) {
                    $logger?->warning("Skipping breakdown $breakdown for row $index of Campaign metrics due to missing value");
                    continue;
                }
                $dimensions[] = [
                    'dimensionKey' => $breakdown,
                    'dimensionValue' => $row[$breakdown],
                ];
            }
            $metadata = array_filter($row, function ($key) use ($metadataFields) {
                return in_array($key, $metadataFields);
            }, ARRAY_FILTER_USE_KEY);
            foreach ($row as $key => $value) {
                if (!in_array($key, $metricsList)) {
                    continue;
                }
                $metricConfigsGroupKey = KeyGenerator::generateMetricConfigKey(
                    channel: Channel::facebook->value,
                    name: $key,
                    period: $period->value,
                    metricDate: Carbon::parse($row['date_start'])->toDateString(),
                    channeledAccount:  $channeledAccountEntity->getPlatformId(),
                    campaign: $campaignEntity->getCampaignId(),
                    channeledCampaign: $channeledCampaignEntity->getPlatformId(),
                );
                $channeledMetric = new stdClass();
                $channeledMetric->channel = Channel::facebook->value;
                $channeledMetric->name = $key;
                $channeledMetric->value = ($key == 'cost_per_unique_outbound_click') && is_array($value) ? $value[0]['value'] : $value;
                $channeledMetric->period = $period->value;
                $channeledMetric->metricDate = Carbon::parse($row['date_start'])->toDateString();
                $channeledMetric->platformId = $channeledCampaignEntity->getPlatformId();
                $channeledMetric->platformCreatedAt = Carbon::parse($row['date_start'])->toDateString();
                $channeledMetric->dimensions = $dimensions;
                $channeledMetric->dimensionsHash = KeyGenerator::generateDimensionsHash($dimensions);
                $channeledMetric->metricConfigKey = $metricConfigsGroupKey;
                $channeledMetric->metadata = $metadata;
                $channeledMetric->data = [];

                if (!isset($elements[$metricConfigsGroupKey][$key])) {
                    $elements[$metricConfigsGroupKey][$key] = [];
                }
                $elements[$metricConfigsGroupKey][$key][] = $channeledMetric;

                if ($index % 1000 === 0) {
                    $rowTime = microtime(true) - $rowStart;
                    $memory = memory_get_usage() / 1024 / 1024;
                    // $logger?->info("Converted row $index/$rowCount, created " . $collection->count() . " metrics, took $rowTime seconds, memory: $memory MB");
                }
            }
        }

        // Helpers::dumpDebugJson($elements);

        foreach ($elements as $element) {
            foreach ($element as $metricNameElement) {
                foreach ($metricNameElement as $metricElement) {
                    $collection->add($metricElement);
                }
            }
        }

        // Helpers::dumpDebugJson($collection->toArray()[0]);

        $totalTime = microtime(true) - $startTime;
        $memory = memory_get_usage() / 1024 / 1024;
        $logger?->info("Completed metrics conversion: $rowCount rows to " . $collection->count() . " metrics, skipped $skippedRows rows, took $totalTime seconds, memory: $memory MB");

        return $collection;
    }

    /**
     * Converts GSC API rows into a collection of metric objects.
     *
     * @param array $rows
     * @param LoggerInterface|null $logger
     * @param ChanneledAccount|null $channeledAccountEntity
     * @param Campaign|null $campaignEntity
     * @param ChanneledCampaign|null $channeledCampaignEntity
     * @param ChanneledAdGroup|null $channeledAdGroupEntity
     * @param Period $period
     * @return ArrayCollection
     */
    public static function adsetMetrics(
        array $rows,
        LoggerInterface $logger = null,
        ChanneledAccount $channeledAccountEntity = null,
        Campaign $campaignEntity = null,
        ChanneledCampaign $channeledCampaignEntity = null,
        ChanneledAdGroup $channeledAdGroupEntity = null,
        Period $period = Period::Daily,
    ): ArrayCollection {
        $startTime = microtime(true);
        $rowCount = count($rows);
        $collection = new ArrayCollection();
        $skippedRows = 0;

        $metricsList = ['impressions', 'clicks', 'cpc', 'ctr', 'cpm', 'spend', 'reach', 'frequency'];
        $breakdowns = ['age', 'gender'];
        $metadataFields = ['actions', 'cost_per_action_type'];

        $logger?->info("Starting metrics conversion for platformId {$channeledAdGroupEntity->getPlatformId()}, rows=$rowCount");
        // $logger?->warning("Note: 'searchAppearance' not fetched from GSC API due to dimension restrictions; defaulting to 'WEB' in ChanneledMetricDimension");
        // $logger?->info("Page entity for site $siteUrl: " . ($pageEntity ? "ID={$pageEntity->getId()}, URL={$pageEntity->getUrl()}" : 'none'));
        $elements = [];
        // Helpers::dumpDebugJson($rows);
        foreach ($rows as $index => $row) {
            $rowStart = microtime(true);
            $dimensions = [];
            foreach ($breakdowns as $breakdown) {
                if (!isset($row[$breakdown])) {
                    $logger?->warning("Skipping breakdown $breakdown for row $index of Adset metrics due to missing value");
                    continue;
                }
                $dimensions[] = [
                    'dimensionKey' => $breakdown,
                    'dimensionValue' => $row[$breakdown],
                ];
            }
            $metadata = array_filter($row, function ($key) use ($metadataFields) {
                return in_array($key, $metadataFields);
            }, ARRAY_FILTER_USE_KEY);
            foreach ($row as $key => $value) {
                if (!in_array($key, $metricsList)) {
                    continue;
                }
                $metricConfigsGroupKey = KeyGenerator::generateMetricConfigKey(
                    channel: Channel::facebook->value,
                    name: $key,
                    period: $period->value,
                    metricDate: Carbon::parse($row['date_start'])->toDateString(),
                    channeledAccount:  $channeledAccountEntity->getPlatformId(),
                    campaign: $campaignEntity->getCampaignId(),
                    channeledCampaign: $channeledCampaignEntity->getPlatformId(),
                    channeledAdGroup: $channeledAdGroupEntity->getPlatformId(),
                );
                $channeledMetric = new stdClass();
                $channeledMetric->channel = Channel::facebook->value;
                $channeledMetric->name = $key;
                $channeledMetric->value = ($key == 'cost_per_unique_outbound_click') && is_array($value) ? $value[0]['value'] : $value;
                $channeledMetric->period = $period->value;
                $channeledMetric->metricDate = Carbon::parse($row['date_start'])->toDateString();
                $channeledMetric->platformId = $channeledCampaignEntity->getPlatformId();
                $channeledMetric->platformCreatedAt = Carbon::parse($row['date_start'])->toDateString();
                $channeledMetric->dimensions = $dimensions;
                $channeledMetric->dimensionsHash = KeyGenerator::generateDimensionsHash($dimensions);
                $channeledMetric->metricConfigKey = $metricConfigsGroupKey;
                $channeledMetric->metadata = $metadata;
                $channeledMetric->data = [];

                if (!isset($elements[$metricConfigsGroupKey][$key])) {
                    $elements[$metricConfigsGroupKey][$key] = [];
                }
                $elements[$metricConfigsGroupKey][$key][] = $channeledMetric;

                if ($index % 1000 === 0) {
                    $rowTime = microtime(true) - $rowStart;
                    $memory = memory_get_usage() / 1024 / 1024;
                    // $logger?->info("Converted row $index/$rowCount, created " . $collection->count() . " metrics, took $rowTime seconds, memory: $memory MB");
                }
            }
        }

        // Helpers::dumpDebugJson($elements);

        foreach ($elements as $element) {
            foreach ($element as $metricNameElement) {
                foreach ($metricNameElement as $metricElement) {
                    $collection->add($metricElement);
                }
            }
        }

        // Helpers::dumpDebugJson($collection->toArray()[0]);

        $totalTime = microtime(true) - $startTime;
        $memory = memory_get_usage() / 1024 / 1024;
        $logger?->info("Completed metrics conversion: $rowCount rows to " . $collection->count() . " metrics, skipped $skippedRows rows, took $totalTime seconds, memory: $memory MB");

        return $collection;
    }

    /**
     * Converts GSC API rows into a collection of metric objects.
     *
     * @param array $rows
     * @param LoggerInterface|null $logger
     * @param ChanneledAccount|null $channeledAccountEntity
     * @param Campaign|null $campaignEntity
     * @param ChanneledCampaign|null $channeledCampaignEntity
     * @param ChanneledAdGroup|null $channeledAdGroupEntity
     * @param ChanneledAd|null $channeledAdEntity
     * @param Period $period
     * @return ArrayCollection
     */
    public static function adMetrics(
        array $rows,
        LoggerInterface $logger = null,
        ChanneledAccount $channeledAccountEntity = null,
        Campaign $campaignEntity = null,
        ChanneledCampaign $channeledCampaignEntity = null,
        ChanneledAdGroup $channeledAdGroupEntity = null,
        ChanneledAd $channeledAdEntity = null,
        Period $period = Period::Daily,
    ): ArrayCollection {
        $startTime = microtime(true);
        $rowCount = count($rows);
        $collection = new ArrayCollection();
        $skippedRows = 0;

        $metricsList = ['impressions', 'clicks', 'cpc', 'ctr', 'cpm', 'spend', 'reach', 'frequency'];
        $breakdowns = ['age', 'gender'];
        $metadataFields = ['actions', 'cost_per_action_type'];

        $logger?->info("Starting metrics conversion for platformId {$channeledAdEntity->getPlatformId()}, rows=$rowCount");
        // $logger?->warning("Note: 'searchAppearance' not fetched from GSC API due to dimension restrictions; defaulting to 'WEB' in ChanneledMetricDimension");
        // $logger?->info("Page entity for site $siteUrl: " . ($pageEntity ? "ID={$pageEntity->getId()}, URL={$pageEntity->getUrl()}" : 'none'));
        $elements = [];
        // Helpers::dumpDebugJson($rows);
        foreach ($rows as $index => $row) {
            $rowStart = microtime(true);
            $dimensions = [];
            foreach ($breakdowns as $breakdown) {
                if (!isset($row[$breakdown])) {
                    $logger?->warning("Skipping breakdown $breakdown for row $index of Adset metrics due to missing value");
                    continue;
                }
                $dimensions[] = [
                    'dimensionKey' => $breakdown,
                    'dimensionValue' => $row[$breakdown],
                ];
            }
            $metadata = array_filter($row, function ($key) use ($metadataFields) {
                return in_array($key, $metadataFields);
            }, ARRAY_FILTER_USE_KEY);
            foreach ($row as $key => $value) {
                if (!in_array($key, $metricsList)) {
                    continue;
                }
                $metricConfigsGroupKey = KeyGenerator::generateMetricConfigKey(
                    channel: Channel::facebook->value,
                    name: $key,
                    period: $period->value,
                    metricDate: Carbon::parse($row['date_start'])->toDateString(),
                    channeledAccount:  $channeledAccountEntity->getPlatformId(),
                    campaign: $campaignEntity->getCampaignId(),
                    channeledCampaign: $channeledCampaignEntity->getPlatformId(),
                    channeledAdGroup: $channeledAdGroupEntity->getPlatformId(),
                    channeledAd: $channeledAdEntity->getPlatformId(),
                );
                $channeledMetric = new stdClass();
                $channeledMetric->channel = Channel::facebook->value;
                $channeledMetric->name = $key;
                $channeledMetric->value = ($key == 'cost_per_unique_outbound_click') && is_array($value) ? $value[0]['value'] : $value;
                $channeledMetric->period = $period->value;
                $channeledMetric->metricDate = Carbon::parse($row['date_start'])->toDateString();
                $channeledMetric->platformId = $channeledCampaignEntity->getPlatformId();
                $channeledMetric->platformCreatedAt = Carbon::parse($row['date_start'])->toDateString();
                $channeledMetric->dimensions = $dimensions;
                $channeledMetric->dimensionsHash = KeyGenerator::generateDimensionsHash($dimensions);
                $channeledMetric->metricConfigKey = $metricConfigsGroupKey;
                $channeledMetric->metadata = $metadata;
                $channeledMetric->data = [];

                if (!isset($elements[$metricConfigsGroupKey][$key])) {
                    $elements[$metricConfigsGroupKey][$key] = [];
                }
                $elements[$metricConfigsGroupKey][$key][] = $channeledMetric;

                if ($index % 1000 === 0) {
                    $rowTime = microtime(true) - $rowStart;
                    $memory = memory_get_usage() / 1024 / 1024;
                    // $logger?->info("Converted row $index/$rowCount, created " . $collection->count() . " metrics, took $rowTime seconds, memory: $memory MB");
                }
            }
        }

        // Helpers::dumpDebugJson($elements);

        foreach ($elements as $element) {
            foreach ($element as $metricNameElement) {
                foreach ($metricNameElement as $metricElement) {
                    $collection->add($metricElement);
                }
            }
        }

        // Helpers::dumpDebugJson($collection->toArray()[0]);

        $totalTime = microtime(true) - $startTime;
        $memory = memory_get_usage() / 1024 / 1024;
        $logger?->info("Completed metrics conversion: $rowCount rows to " . $collection->count() . " metrics, skipped $skippedRows rows, took $totalTime seconds, memory: $memory MB");

        return $collection;
    }
}
