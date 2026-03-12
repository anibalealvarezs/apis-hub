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
use Anibalealvarezs\FacebookGraphApi\Enums\AdAccountPermission;
use Anibalealvarezs\FacebookGraphApi\Enums\AdPermission;
use Anibalealvarezs\FacebookGraphApi\Enums\AdsetPermission;
use Anibalealvarezs\FacebookGraphApi\Enums\CampaignPermission;
use Anibalealvarezs\FacebookGraphApi\Enums\MetricSet;
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
        // $logger?->warning("Note: 'searchAppearance' not fetched from GSC API due to dimension restrictions; defaulting to 'WEB' in ChanneledMetricDimension");
        // $logger?->info("Page entity for site $siteUrl: " . ($pageEntity ? "ID={$pageEntity->getId()}, URL={$pageEntity->getUrl()}" : 'none'));
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
        ?LoggerInterface $logger = null,
        ?Account $accountEntity = null,
        ?string $channeledAccountPlatformId = null,
        Period $period = Period::Daily,
        MetricSet $metricSet = MetricSet::KEY,
    ): ArrayCollection {
        $startTime = microtime(true);
        $rowCount = count($rows);
        $collection = new ArrayCollection();
        $skippedRows = 0;

        $metricsList = explode(',', AdAccountPermission::DEFAULT->insightsFields($metricSet));
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
                $dateStart = $row['date_start'] ?? null;
                $metricDate = $dateStart ? Carbon::parse($dateStart)->toDateString() : Carbon::now()->toDateString();
                $metricConfigsGroupKey = KeyGenerator::generateMetricConfigKey(
                    channel: Channel::facebook_marketing->value,
                    name: $key,
                    period: $period->value,
                    metricDate: $metricDate,
                    account: $accountEntity,
                    channeledAccount:  $channeledAccountPlatformId,
                );
                $channeledMetric = new stdClass();
                $channeledMetric->channel = Channel::facebook_marketing->value;
                $channeledMetric->name = $key;
                $val = is_array($value) ? ($value[0]['value'] ?? ($value[0]['amount'] ?? ($value[0]['values'][0]['value'] ?? 0))) : $value;
                if (!is_numeric($val)) {
                    continue;
                }
                $channeledMetric->value = $val;
                $channeledMetric->period = $period->value;
                $channeledMetric->metricDate = $metricDate;
                $channeledMetric->platformId = $channeledAccountPlatformId;
                $channeledMetric->platformCreatedAt = $metricDate;
                $channeledMetric->dimensions = $dimensions;
                $channeledMetric->dimensionsHash = KeyGenerator::generateDimensionsHash($dimensions);
                $channeledMetric->metricConfigKey = $metricConfigsGroupKey;
                $channeledMetric->metadata = $metadata;
                $channeledMetric->data = $row;

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
        ?LoggerInterface $logger = null,
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
                $channeledMetric->value = $row['total_value']['value'] ?? 0;
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
        ?LoggerInterface $logger = null,
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
        ?LoggerInterface $logger = null,
        ?ChanneledAccount $channeledAccountEntity = null,
        ?Campaign $campaignEntity = null,
        ?ChanneledCampaign $channeledCampaignEntity = null,
        Period $period = Period::Daily,
        MetricSet $metricSet = MetricSet::KEY,
    ): ArrayCollection {
        $startTime = microtime(true);
        $rowCount = count($rows);
        $collection = new ArrayCollection();
        $skippedRows = 0;

        $metricsList = explode(',', CampaignPermission::DEFAULT->insightsFields($metricSet));
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
                $dateStart = $row['date_start'] ?? null;
                $metricDate = $dateStart ? Carbon::parse($dateStart)->toDateString() : Carbon::now()->toDateString();
                $metricConfigsGroupKey = KeyGenerator::generateMetricConfigKey(
                    channel: Channel::facebook_marketing->value,
                    name: $key,
                    period: $period->value,
                    metricDate: $metricDate,
                    channeledAccount:  $channeledAccountEntity->getPlatformId(),
                    campaign: $campaignEntity->getCampaignId(),
                    channeledCampaign: $channeledCampaignEntity->getPlatformId(),
                );
                $channeledMetric = new stdClass();
                $channeledMetric->channel = Channel::facebook_marketing->value;
                $channeledMetric->name = $key;
                $val = is_array($value) ? ($value[0]['value'] ?? ($value[0]['amount'] ?? ($value[0]['values'][0]['value'] ?? 0))) : $value;
                if (!is_numeric($val)) {
                    continue;
                }
                $channeledMetric->value = $val;
                $channeledMetric->period = $period->value;
                $channeledMetric->metricDate = $metricDate;
                $channeledMetric->platformId = $channeledCampaignEntity->getPlatformId();
                $channeledMetric->platformCreatedAt = $metricDate;
                $channeledMetric->dimensions = $dimensions;
                $channeledMetric->dimensionsHash = KeyGenerator::generateDimensionsHash($dimensions);
                $channeledMetric->metricConfigKey = $metricConfigsGroupKey;
                $channeledMetric->metadata = $metadata;
                $channeledMetric->data = $row;

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
        ?LoggerInterface $logger = null,
        ?ChanneledAccount $channeledAccountEntity = null,
        ?Campaign $campaignEntity = null,
        ?ChanneledCampaign $channeledCampaignEntity = null,
        ?ChanneledAdGroup $channeledAdGroupEntity = null,
        Period $period = Period::Daily,
        MetricSet $metricSet = MetricSet::KEY,
    ): ArrayCollection {
        $startTime = microtime(true);
        $rowCount = count($rows);
        $collection = new ArrayCollection();
        $skippedRows = 0;

        $metricsList = explode(',', AdsetPermission::DEFAULT->insightsFields($metricSet));
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
                $dateStart = $row['date_start'] ?? null;
                $metricDate = $dateStart ? Carbon::parse($dateStart)->toDateString() : Carbon::now()->toDateString();
                $metricConfigsGroupKey = KeyGenerator::generateMetricConfigKey(
                    channel: Channel::facebook_marketing->value,
                    name: $key,
                    period: $period->value,
                    metricDate: $metricDate,
                    channeledAccount:  $channeledAccountEntity->getPlatformId(),
                    campaign: $campaignEntity->getCampaignId(),
                    channeledCampaign: $channeledCampaignEntity->getPlatformId(),
                    channeledAdGroup: $channeledAdGroupEntity->getPlatformId(),
                );
                $channeledMetric = new stdClass();
                $channeledMetric->channel = Channel::facebook_marketing->value;
                $channeledMetric->name = $key;
                $val = is_array($value) ? ($value[0]['value'] ?? ($value[0]['amount'] ?? ($value[0]['values'][0]['value'] ?? 0))) : $value;
                if (!is_numeric($val)) {
                    continue;
                }
                $channeledMetric->value = $val;
                $channeledMetric->period = $period->value;
                $channeledMetric->metricDate = $metricDate;
                $channeledMetric->platformId = $channeledAdGroupEntity->getPlatformId();
                $channeledMetric->platformCreatedAt = $metricDate;
                $channeledMetric->dimensions = $dimensions;
                $channeledMetric->dimensionsHash = KeyGenerator::generateDimensionsHash($dimensions);
                $channeledMetric->metricConfigKey = $metricConfigsGroupKey;
                $channeledMetric->metadata = $metadata;
                $channeledMetric->data = $row;

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
        ?LoggerInterface $logger = null,
        ?ChanneledAccount $channeledAccountEntity = null,
        ?Campaign $campaignEntity = null,
        ?ChanneledCampaign $channeledCampaignEntity = null,
        ?ChanneledAdGroup $channeledAdGroupEntity = null,
        ?ChanneledAd $channeledAdEntity = null,
        Period $period = Period::Daily,
        MetricSet $metricSet = MetricSet::KEY,
    ): ArrayCollection {
        $startTime = microtime(true);
        $rowCount = count($rows);
        $collection = new ArrayCollection();
        $skippedRows = 0;

        $metricsList = explode(',', AdPermission::DEFAULT->insightsFields($metricSet));
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
                $dateStart = $row['date_start'] ?? null;
                $metricDate = $dateStart ? Carbon::parse($dateStart)->toDateString() : Carbon::now()->toDateString();
                $metricConfigsGroupKey = KeyGenerator::generateMetricConfigKey(
                    channel: Channel::facebook_marketing->value,
                    name: $key,
                    period: $period->value,
                    metricDate: $metricDate,
                    channeledAccount:  $channeledAccountEntity->getPlatformId(),
                    campaign: $campaignEntity->getCampaignId(),
                    channeledCampaign: $channeledCampaignEntity->getPlatformId(),
                    channeledAdGroup: $channeledAdGroupEntity->getPlatformId(),
                    channeledAd: $channeledAdEntity->getPlatformId(),
                );
                $channeledMetric = new stdClass();
                $channeledMetric->channel = Channel::facebook_marketing->value;
                $channeledMetric->name = $key;
                $val = is_array($value) ? ($value[0]['value'] ?? ($value[0]['amount'] ?? ($value[0]['values'][0]['value'] ?? 0))) : $value;
                if (!is_numeric($val)) {
                    continue;
                }
                $channeledMetric->value = $val;
                $channeledMetric->period = $period->value;
                $channeledMetric->metricDate = $metricDate;
                $channeledMetric->platformId = $channeledAdEntity->getPlatformId();
                $channeledMetric->platformCreatedAt = $metricDate;
                $channeledMetric->dimensions = $dimensions;
                $channeledMetric->dimensionsHash = KeyGenerator::generateDimensionsHash($dimensions);
                $channeledMetric->metricConfigKey = $metricConfigsGroupKey;
                $channeledMetric->metadata = $metadata;
                $channeledMetric->data = $row;

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
