<?php

namespace Classes\Conversions;

use Carbon\Carbon;
use Classes\Overrides\KlaviyoApi\KlaviyoApi;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use Enums\Period;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Services\CacheService;
use stdClass;

class KlaviyoConvert
{
    public static function customers(array $customers): ArrayCollection
    {
        return new ArrayCollection(array_map(function($customer) {
            return (object) [
                'platformId' => $customer['id'],
                'platformCreatedAt' => Carbon::parse($customer['attributes']['created']),
                'channel' => Channel::klaviyo->value,
                'email' => $customer['attributes']['email'],
                'data' => $customer,
            ];
        }, $customers));
    }

    public static function products(array $products): ArrayCollection
    {
        return new ArrayCollection(array_map(function($product) {
            return (object) [
                'platformId' => $product['id'],
                'sku' => $product['sku'] ?? '',
                'platformCreatedAt' => isset($product['attributes']['created']) ? Carbon::parse($product['attributes']['created']) : null,
                'channel' => Channel::klaviyo->value,
                'data' => $product,
                'vendor' => null,
                'variants' => self::productVariants($product['included']),
            ];
        }, $products));
    }

    public static function productVariants(array $productVariants): ArrayCollection
    {
        return new ArrayCollection(array_map(function($productVariant) {
            return (object) [
                'platformId' => $productVariant['id'],
                'sku' => $productVariant['sku'] ?? '',
                'platformCreatedAt' => isset($productVariant['attributes']['created']) ? Carbon::parse($productVariant['attributes']['created']) : null,
                'channel' => Channel::klaviyo->value,
                'data' => $productVariant,
            ];
        }, $productVariants));
    }

    public static function productCategories(array $productCategories): ArrayCollection
    {
        return new ArrayCollection(array_map(function($productCategory) {
            return (object) [
                'platformId' => $productCategory['id'],
                'platformCreatedAt' => isset($productCategory['attributes']['created']) ? Carbon::parse($productCategory['attributes']['created']) : null,
                'channel' => Channel::klaviyo->value,
                'data' => $productCategory,
            ];
        }, $productCategories));
    }

    /**
     * Converts Klaviyo metric aggregates response to ArrayCollection for processing.
     *
     * @param array $aggregates
     * @param string $metricId
     * @return ArrayCollection
     * @throws GuzzleException
     */
    public static function metricAggregates(array $aggregates, string $metricId): ArrayCollection
    {
        $cacheService = CacheService::getInstance(Helpers::getRedisClient());
        $cacheKey = 'klaviyo_metric_names_' . md5($metricId);
        $metricName = $cacheService->get(
            key: $cacheKey,
            callback: function() use ($metricId) {
                $klaviyoClient = new KlaviyoApi(
                    apiKey: Helpers::getChannelsConfig()['klaviyo']['klaviyo_api_key']
                );
                $response = $klaviyoClient->getMetricData($metricId);
                return $response['data']['attributes']['name'] ?? 'Unknown Metric';
            },
            ttl: Helpers::getChannelsConfig()['klaviyo']['metrics_cache_ttl'] ?? 86400
        );

        $collection = new ArrayCollection();
        $dates = $aggregates['dates'] ?? [];
        $dataPoints = $aggregates['data'] ?? [];

        foreach ($dates as $index => $date) {
            if (!isset($dataPoints[$index])) {
                continue;
            }

            $dataPoint = $dataPoints[$index];
            $metricDate = Carbon::parse($date)->toDateTime();
            $channeledMetric = new stdClass();
            $channeledMetric->platformId = $metricId;
            $channeledMetric->channel = Channel::klaviyo->value;
            $channeledMetric->name = $metricName;
            $channeledMetric->value = $dataPoint['measurements']['count'] ?? 0;
            $channeledMetric->period = Period::Daily; // Assumes Interval::day
            $channeledMetric->metricDate = $metricDate;
            $channeledMetric->data = $dataPoint['dimensions'] ?? [];
            $channeledMetric->metadata = [
                'metricId' => $metricId,
                'dimensions' => $dataPoint['dimensions'] ?? [],
            ];
            $channeledMetric->platformCreatedAt = $metricDate;

            $collection->add($channeledMetric);
        }

        return $collection;
    }
}