<?php

declare(strict_types=1);

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
use Enums\Channel;
use Enums\Period;
use Psr\Log\LoggerInterface;
use Anibalealvarezs\FacebookGraphApi\Enums\AdAccountPermission;
use Anibalealvarezs\FacebookGraphApi\Enums\AdPermission;
use Anibalealvarezs\FacebookGraphApi\Enums\AdsetPermission;
use Anibalealvarezs\FacebookGraphApi\Enums\CampaignPermission;
use Anibalealvarezs\FacebookGraphApi\Enums\MetricSet;
use stdClass;

class FacebookMarketingMetricConvert
{
    /**
     * Converts Facebook Ad Account API rows into a collection of metric objects.
     *
     * @param array $rows
     * @param LoggerInterface|null $logger
     * @param Account|null $accountEntity
     * @param string|null $channeledAccountPlatformId
     * @param Period $period
     * @param MetricSet $metricSet
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
        $elements = [];
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
     * Converts Facebook Campaign API rows into a collection of metric objects.
     *
     * @param array $rows
     * @param LoggerInterface|null $logger
     * @param ChanneledAccount|null $channeledAccountEntity
     * @param Campaign|null $campaignEntity
     * @param ChanneledCampaign|null $channeledCampaignEntity
     * @param Period $period
     * @param MetricSet $metricSet
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
        $elements = [];
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
     * Converts Facebook Adset API rows into a collection of metric objects.
     *
     * @param array $rows
     * @param LoggerInterface|null $logger
     * @param ChanneledAccount|null $channeledAccountEntity
     * @param Campaign|null $campaignEntity
     * @param ChanneledCampaign|null $channeledCampaignEntity
     * @param ChanneledAdGroup|null $channeledAdGroupEntity
     * @param Period $period
     * @param MetricSet $metricSet
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
        $elements = [];
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
     * Converts Facebook Ad API rows into a collection of metric objects.
     *
     * @param array $rows
     * @param LoggerInterface|null $logger
     * @param ChanneledAccount|null $channeledAccountEntity
     * @param Campaign|null $campaignEntity
     * @param ChanneledCampaign|null $channeledCampaignEntity
     * @param ChanneledAdGroup|null $channeledAdGroupEntity
     * @param ChanneledAd|null $channeledAdEntity
     * @param Period $period
     * @param MetricSet $metricSet
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
        $elements = [];
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
}
