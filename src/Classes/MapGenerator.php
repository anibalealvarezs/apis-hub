<?php

namespace Classes;

use DateTimeImmutable;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Entities\Analytics\Account;
use Entities\Analytics\Campaign;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Channeled\ChanneledAd;
use Entities\Analytics\Channeled\ChanneledAdGroup;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Entities\Analytics\Creative;
use Entities\Analytics\Country;
use Entities\Analytics\Customer;
use Entities\Analytics\Device;
use Entities\Analytics\Order;
use Entities\Analytics\Page;
use Entities\Analytics\Post;
use Entities\Analytics\Product;
use Entities\Analytics\Query;
use Helpers\Helpers;
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
    public static function getPostMap(
        EntityManager $manager,
        string $sql,
        array $params
    ): array {
        // Update the metric map with the newly inserted dimensions
        $existingPosts = $manager->getConnection()
            ->executeQuery($sql, $params)
            ->fetchAllAssociative();

        // Map dimensions to their IDs and create a map for quick access
        $postMap = [];
        foreach ($existingPosts as $post) {
            $postKey = $post['post_id'];
            $postMap[$postKey] = (int)$post['id'];
        }

        return $postMap;
    }

    /**
     * Generates a map of dimension keys to their IDs based on the provided SQL query and parameters.
     * @param EntityManager $manager
     * @param string $sql
     * @param array $params
     * @return array
     * @throws Exception
     */
    public static function getCampaignMap(
        EntityManager $manager,
        string $sql,
        array $params
    ): array {
        // Update the metric map with the newly inserted dimensions
        $existingCampaigns = $manager->getConnection()
            ->executeQuery($sql, $params)
            ->fetchAllAssociative();

        // Map dimensions to their IDs and create a map for quick access
        $campaignMap = [];
        foreach ($existingCampaigns as $campaign) {
            $campaignKey = $campaign['campaign_id'];
            $campaignMap[$campaignKey] = (int)$campaign['id'];
        }

        return $campaignMap;
    }

    /**
     * Generates a map of dimension keys to their IDs based on the provided SQL query and parameters.
     * @param EntityManager $manager
     * @param string $sql
     * @param array $params
     * @return array
     * @throws Exception
     */
    public static function getChanneledCampaignMap(
        EntityManager $manager,
        string $sql,
        array $params
    ): array {
        // Update the metric map with the newly inserted dimensions
        $existingChanneledCampaigns = $manager->getConnection()
            ->executeQuery($sql, $params)
            ->fetchAllAssociative();

        // Map dimensions to their IDs and create a map for quick access
        $channeledCampaignMap = [];
        foreach ($existingChanneledCampaigns as $channeledCampaign) {
            $channeledCampaignKey = $channeledCampaign['platform_id'];
            $channeledCampaignMap[$channeledCampaignKey] = (int)$channeledCampaign['id'];
        }

        return $channeledCampaignMap;
    }

    /**
     * Generates a map of dimension keys to their IDs based on the provided SQL query and parameters.
     * @param EntityManager $manager
     * @param string $sql
     * @param array $params
     * @return array
     * @throws Exception
     */
    public static function getChanneledAdGroupMap(
        EntityManager $manager,
        string $sql,
        array $params
    ): array {
        // Update the metric map with the newly inserted dimensions
        $existingChanneledAdGroups = $manager->getConnection()
            ->executeQuery($sql, $params)
            ->fetchAllAssociative();

        // Map dimensions to their IDs and create a map for quick access
        $channeledAdGroupMap = [];
        foreach ($existingChanneledAdGroups as $channeledAdGroup) {
            $channeledAdGroupKey = $channeledAdGroup['platform_id'];
            $channeledAdGroupMap[$channeledAdGroupKey] = (int)$channeledAdGroup['id'];
        }

        return $channeledAdGroupMap;
    }

    /**
     * Generates a map of dimension keys to their IDs based on the provided SQL query and parameters.
     * @param EntityManager $manager
     * @param string $sql
     * @param array $params
     * @return array
     * @throws Exception
     */
    public static function getChanneledAdMap(
        EntityManager $manager,
        string $sql,
        array $params
    ): array {
        // Update the metric map with the newly inserted dimensions
        $existingChanneledAds = $manager->getConnection()
            ->executeQuery($sql, $params)
            ->fetchAllAssociative();

        // Map dimensions to their IDs and create a map for quick access
        $channeledAdMap = [];
        foreach ($existingChanneledAds as $channeledAd) {
            $channeledAdKey = $channeledAd['platform_id'];
            $channeledAdMap[$channeledAdKey] = (int)$channeledAd['id'];
        }

        return $channeledAdMap;
    }

    /**
     * Generates a map of metrics based on the provided SQL query and parameters.
     * @param EntityManager $manager
     * @param string $sql
     * @param array $params
     * @param array $accountMap
     * @param array $channeledAccountMap
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
    public static function getMetricConfigMap(
        EntityManager $manager,
        string $sql,
        array $params,
    ): array {
        // Update the metric map with the newly inserted metrics
        $existingMetricConfigs = $manager->getConnection()
            ->executeQuery($sql, $params)
            ->fetchAllAssociative();

        // Map metrics to their IDs and create a map for quick access
        $metricConfigMap = [];
        foreach ($existingMetricConfigs as $metricConfig) {
            if (isset($metricConfig['configSignature'])) {
                $metricConfigMap[$metricConfig['configSignature']] = (int)$metricConfig['id'];
            }
        }

        return $metricConfigMap;
    }


    /**
     * Generates a map of metrics based on the provided SQL query and parameters.
     * @param EntityManager $manager
     * @param string $sql
     * @param array $params
     * @param array $metricConfigMap
     * @return array
     * @throws Exception
     */
    public static function getMetricMap(
        EntityManager $manager,
        string $sql,
        array $params,
        array $metricConfigMap,
    ): array {
        // Update the metric map with the newly inserted metrics
        $existingMetrics = $manager->getConnection()
            ->executeQuery($sql, $params)
            ->fetchAllAssociative();

        // Map metrics to their IDs and create a map for quick access
        $metricMap = [];
        foreach ($existingMetrics as $metric) {
            $metricKey = KeyGenerator::generateMetricKey(
                dimensionsHash: $metric['dimensions_hash'],
                metricConfigKey: $metricConfigMap['mapReverse'][$metric['metric_config_id']],
                metricDate: $metric['metric_date'],
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
                platformId: $channeledMetric['platform_id'],
                metric: $channeledMetric['metric_id'],
                platformCreatedAt: (new DateTimeImmutable($channeledMetric['platform_created_at']))->format('Y-m-d'),
            );
            $channeledMetricMap[$metricKey] = [
                'id' => (int)$channeledMetric['id'],
                'data' => $channeledMetric['data'],
            ];
        }

        return $channeledMetricMap;
    }

    /**
     * @param EntityManager $manager
     * @param string $sql
     * @param array $params
     * @return array
     * @throws Exception
     */
    public static function getCustomerMap(
        EntityManager $manager,
        string $sql,
        array $params
    ): array {
        $existingCustomers = $manager->getConnection()
            ->executeQuery($sql, $params)
            ->fetchAllAssociative();

        $customerMap = [];
        foreach ($existingCustomers as $customer) {
            $key = KeyGenerator::generateCustomerKey($customer['email']);
            $customerMap[$key] = [
                'id' => (int)$customer['id'],
                'email' => $customer['email'],
            ];
        }

        return $customerMap;
    }

    /**
     * @param EntityManager $manager
     * @param string $sql
     * @param array $params
     * @return array
     * @throws Exception
     */
    public static function getChanneledCustomerMap(
        EntityManager $manager,
        string $sql,
        array $params
    ): array {
        $existingChanneledCustomers = $manager->getConnection()
            ->executeQuery($sql, $params)
            ->fetchAllAssociative();

        $channeledCustomerMap = [];
        foreach ($existingChanneledCustomers as $channeledCustomer) {
            $key = KeyGenerator::generateChanneledCustomerKey((string) $channeledCustomer['channel'], (string) $channeledCustomer['platform_id']);
            $channeledCustomerMap[$key] = [
                'id' => (int)$channeledCustomer['id'],
                'data' => $channeledCustomer['data'],
            ];
        }

        return $channeledCustomerMap;
    }

    public static function getProductMap(EntityManager $manager, string $sql, array $params): array
    {
        $existingProducts = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = ['map' => [], 'mapReverse' => []];
        foreach ($existingProducts as $product) {
            $key = KeyGenerator::generateProductKey($product['product_id']);
            $map['map'][$key] = ['id' => (int)$product['id'], 'productId' => $product['product_id'], 'sku' => $product['sku']];
            $map['mapReverse'][$product['id']] = $key;
        }
        return $map;
    }

    public static function getChanneledProductMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = [];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateChanneledProductKey((string)$item['channel'], (string)$item['platform_id']);
            $map[$key] = ['id' => (int)$item['id'], 'data' => $item['data']];
        }
        return $map;
    }

    public static function getVendorMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = ['map' => [], 'mapReverse' => []];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateVendorKey($item['name']);
            $map['map'][$key] = ['id' => (int)$item['id'], 'name' => $item['name']];
            $map['mapReverse'][$item['id']] = $key;
        }
        return $map;
    }

    public static function getChanneledVendorMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = [];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateChanneledVendorKey((string)$item['channel'], (string)$item['name']);
            $map[$key] = ['id' => (int)$item['id'], 'data' => $item['data']];
        }
        return $map;
    }

    public static function getProductVariantMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = ['map' => [], 'mapReverse' => []];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateProductVariantKey($item['product_variant_id']);
            $map['map'][$key] = ['id' => (int)$item['id'], 'productVariantId' => $item['product_variant_id'], 'sku' => $item['sku']];
            $map['mapReverse'][$item['id']] = $key;
        }
        return $map;
    }

    public static function getChanneledProductVariantMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = [];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateChanneledProductVariantKey((string)$item['channel'], (string)$item['platform_id']);
            $map[$key] = ['id' => (int)$item['id'], 'data' => $item['data']];
        }
        return $map;
    }

    public static function getProductCategoryMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = ['map' => [], 'mapReverse' => []];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateProductCategoryKey($item['product_category_id']);
            $map['map'][$key] = ['id' => (int)$item['id'], 'productCategoryId' => $item['product_category_id']];
            $map['mapReverse'][$item['id']] = $key;
        }
        return $map;
    }

    public static function getChanneledProductCategoryMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = [];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateChanneledProductCategoryKey((string)$item['channel'], (string)$item['platform_id']);
            $map[$key] = ['id' => (int)$item['id'], 'data' => $item['data']];
        }
        return $map;
    }

    public static function getOrderMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = ['map' => [], 'mapReverse' => []];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateOrderKey((string)$item['order_id']);
            $map['map'][$key] = ['id' => (int)$item['id'], 'orderId' => $item['order_id']];
            $map['mapReverse'][$item['id']] = $key;
        }
        return $map;
    }

    public static function getChanneledOrderMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = [];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateChanneledOrderKey((string)$item['channel'], (string)$item['platform_id']);
            $map[$key] = ['id' => (int)$item['id'], 'data' => $item['data']];
        }
        return $map;
    }

    public static function getChanneledDiscountMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = [];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateChanneledDiscountKey((string)$item['channel'], (string)$item['code']);
            $map[$key] = ['id' => (int)$item['id'], 'data' => $item['data']];
        }
        return $map;
    }

    public static function getDiscountMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = ['map' => [], 'mapReverse' => []];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateDiscountKey((string)$item['code']);
            $map['map'][$key] = ['id' => (int)$item['id'], 'code' => $item['code']];
            $map['mapReverse'][$item['id']] = $key;
        }
        return $map;
    }

    public static function getPriceRuleMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = ['map' => [], 'mapReverse' => []];
        foreach ($existing as $item) {
            $key = KeyGenerator::generatePriceRuleKey((string)$item['price_rule_id']);
            $map['map'][$key] = ['id' => (int)$item['id'], 'priceRuleId' => $item['price_rule_id']];
            $map['mapReverse'][$item['id']] = $key;
        }
        return $map;
    }

    public static function getChanneledPriceRuleMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = [];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateChanneledPriceRuleKey((string)$item['channel'], (string)$item['platform_id']);
            $map[$key] = ['id' => (int)$item['id'], 'data' => $item['data']];
        }
        return $map;
    }

    public static function getCreativeMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = [];
        foreach ($existing as $item) {
            $key = $item['creative_id'];
            $map[$key] = (int)$item['id'];
        }
        return $map;
    }
}
