<?php

namespace Classes\Conversions;

use Carbon\Carbon;
use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use Enums\Period;
use stdClass;

class KlaviyoConvert
{
    public static function customers(array $customers): ArrayCollection
    {
        return new ArrayCollection(array_map(function ($customer) {
            return (object) [
                'platformId' => $customer['id'] ?? null,
                'platformCreatedAt' => isset($customer['attributes']['created']) ? Carbon::parse($customer['attributes']['created']) : Carbon::now(),
                'channel' => Channel::klaviyo->value,
                'email' => $customer['attributes']['email'] ?? '',
                'data' => $customer,
            ];
        }, $customers));
    }

    public static function products(array $products): ArrayCollection
    {
        return new ArrayCollection(array_map(function ($product) {
            return (object) [
                'platformId' => $product['id'] ?? null,
                'sku' => $product['sku'] ?? '',
                'platformCreatedAt' => isset($product['attributes']['created']) ? Carbon::parse($product['attributes']['created']) : null,
                'channel' => Channel::klaviyo->value,
                'data' => $product,
                'vendor' => null,
                'variants' => self::productVariants($product['included'] ?? []),
            ];
        }, $products));
    }

    public static function productVariants(array $productVariants): ArrayCollection
    {
        return new ArrayCollection(array_map(function ($productVariant) {
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
        return new ArrayCollection(array_map(function ($productCategory) {
            return (object) [
                'platformId' => $productCategory['id'] ?? null,
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
     * @param array $metricNamesMap Mapping of metricId to metricName.
     * @return ArrayCollection
     */
    public static function metricAggregates(array $aggregates, string $metricId, array $metricNamesMap = []): ArrayCollection
    {
        $metricName = $metricNamesMap[$metricId] ?? 'Unknown Metric';

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
