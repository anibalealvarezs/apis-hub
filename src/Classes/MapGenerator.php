<?php

namespace Classes;

use DateTimeImmutable;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use RuntimeException;

class MapGenerator
{
    /**
     * Generates a map of dimension keys to their IDs based on the provided SQL query and parameters.
     * @param EntityManager $manager
     * @param string $sql
     * @param array $params
     * @return array
     * @throws Exception
     */
    public static function getDimensionMap(
        EntityManager $manager,
        string $sql,
        array $params
    ): array {
        // Update the metric map with the newly inserted dimensions
        $existingDimensions = $manager->getConnection()
            ->executeQuery($sql, $params)
            ->fetchAllAssociative();

        // Map dimensions to their IDs and create a map for quick access
        $dimensionMap = [];
        foreach ($existingDimensions as $dimension) {
            $dimensionKey = KeyGenerator::generateChanneledMetricDimensionKey(
                channeledMetric: $dimension['channeledMetric_id'],
                dimensionKey: $dimension['dimensionKey'],
                dimensionValue: $dimension['dimensionValue'],
            );
            $dimensionMap[$dimensionKey] = (int)$dimension['id'];
        }

        return $dimensionMap;
    }

    /**
     * Generates a map of metrics based on the provided SQL query and parameters.
     * @param EntityManager $manager
     * @param string $sql
     * @param array $params
     * @param array $campaignMap
     * @param array $channeledCampaignMap
     * @param array $channeledAdGroupMap
     * @param array $channeledAdMap
     * @param array $pageMap
     * @param array $queryMap
     * @param array $postMap
     * @param array $productMap
     * @param array $customerMap
     * @param array $orderMap
     * @param array $countryMap
     * @param array $deviceMap
     * @return array
     * @throws Exception
     */
    public static function getMetricMap(
        EntityManager $manager,
        string $sql,
        array $params,
        array $campaignMap = [],
        array $channeledCampaignMap = [],
        array $channeledAdGroupMap = [],
        array $channeledAdMap = [],
        array $pageMap = [],
        array $queryMap = [],
        array $postMap = [],
        array $productMap = [],
        array $customerMap = [],
        array $orderMap = [],
        array $countryMap = [],
        array $deviceMap = [],
    ): array {
        // Update the metric map with the newly inserted metrics
        $existingMetrics = $manager->getConnection()
            ->executeQuery($sql, $params)
            ->fetchAllAssociative();

        // Map metrics to their IDs and create a map for quick access
        $metricMap = [];
        foreach ($existingMetrics as $metric) {
            $metricKey = KeyGenerator::generateMetricKey(
                channel: $metric['channel'],
                name: $metric['name'],
                period: $metric['period'],
                metricDate: $metric['metricDate'],
                campaign: isset($metric['campaign_id']) ? $campaignMap[$metric['campaign_id']]->getCampaignId() : null,
                channeledCampaign: isset($metric['channeledCampaign_id']) ? $channeledCampaignMap[$metric['channeledCampaign_id']]->getPlatformId() : null,
                channeledAdGroup: isset($metric['channeledAdGroup_id']) ? $channeledAdGroupMap[$metric['channeledAdGroup_id']]->getPlatformId() : null,
                channeledAd: isset($metric['channeledAd_id']) ? $channeledAdMap[$metric['channeledAd_id']]->getPlatformId() : null,
                page: isset($metric['page_id']) ? $pageMap[$metric['page_id']]->getUrl() : null,
                query: isset($metric['query_id']) ? $queryMap[$metric['query_id']] : null,
                post: isset($metric['post_id']) ? $postMap[$metric['post_id']]->getPostId() : null,
                product: isset($metric['product_id']) ? $productMap[$metric['product_id']]->getProductId() : null,
                customer: isset($metric['customer_id']) ? $customerMap[$metric['customer_id']]->getEmail() : null,
                order: isset($metric['order_id']) ? $orderMap[$metric['order_id']]->getOrderId() : null,
                country: isset($metric['country_id']) ? $countryMap[$metric['country_id']]->getCode() : null,
                device: isset($metric['device_id']) ? $deviceMap[$metric['device_id']]->getType() : null,
            );
            $metricMap[$metricKey] = (int)$metric['id'];
        }

        return $metricMap;
    }

    /**
     * Generates a map of channeled metrics based on the provided SQL query and parameters.
     * @param EntityManager $manager
     * @param string $sql
     * @param array $params
     * @param array $metricMap
     * @return array
     * @throws Exception
     * @throws \Exception
     */
    public static function getChanneledMetricMap(
        EntityManager $manager,
        string $sql,
        array $params,
        array $metricMap,
    ): array {
        // Update the metric map with the newly inserted channeled metrics
        $existingChanneledMetrics = $manager->getConnection()
            ->executeQuery($sql, $params)
            ->fetchAllAssociative();

        // Map channeled metrics to their IDs and create a map for quick access
        $channeledMetricMap = [];
        foreach ($existingChanneledMetrics as $channeledMetric) {
            if (!isset($metricMap['mapReverse'][$channeledMetric['metric_id']])) {
                throw new RuntimeException("Channeled metric with ID {$channeledMetric['id']} references non-existent metric ID {$channeledMetric['metric_id']}");
            }
            $metricKey = KeyGenerator::generateChanneledMetricKey(
                channel: $channeledMetric['channel'],
                platformId: $channeledMetric['platformId'],
                metric: $channeledMetric['metric_id'],
                platformCreatedAt: (new DateTimeImmutable($channeledMetric['platformCreatedAt']))->format('Y-m-d'),
            );
            $channeledMetricMap[$metricKey] = [
                'id' => (int)$channeledMetric['id'],
                'data' => $channeledMetric['data'],
            ];
        }

        return $channeledMetricMap;
    }
}