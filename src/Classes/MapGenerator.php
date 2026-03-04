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
            $postKey = $post['postId'];
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
            $campaignKey = $campaign['campaignId'];
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
            $channeledCampaignKey = $channeledCampaign['platformId'];
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
            $channeledAdGroupKey = $channeledAdGroup['platformId'];
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
            $channeledAdKey = $channeledAd['platformId'];
            $channeledAdMap[$channeledAdKey] = (int)$channeledAd['id'];
        }

        return $channeledAdMap;
    }

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
        array $accountMap = [],
        array $channeledAccountMap = [],
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
        $existingMetricConfigs = $manager->getConnection()
            ->executeQuery($sql, $params)
            ->fetchAllAssociative();

        // Map metrics to their IDs and create a map for quick access
        $metricConfigMap = [];
        foreach ($existingMetricConfigs as $metricConfig) {
            $metricConfigKey = KeyGenerator::generateMetricConfigKey(
                channel: $metricConfig['channel'],
                name: $metricConfig['name'],
                period: $metricConfig['period'],
                metricDate: $metricConfig['metricDate'],
                account: isset($metricConfig['account_id']) ? ($accountMap[$metricConfig['account_id']] instanceof Account ? $accountMap[$metricConfig['account_id']]->getName() : $accountMap[$metricConfig['account_id']]) : null,
                channeledAccount: isset($metricConfig['channeledAccount_id']) ? ($channeledAccountMap[$metricConfig['channeledAccount_id']] instanceof ChanneledAccount ? $channeledAccountMap[$metricConfig['channeledAccount_id']]->getPlatformId() : $channeledAccountMap[$metricConfig['channeledAccount_id']]) : null,
                campaign: isset($metricConfig['campaign_id']) ? ($campaignMap[$metricConfig['campaign_id']] instanceof Campaign ? $campaignMap[$metricConfig['campaign_id']]->getCampaignId() : $campaignMap[$metricConfig['campaign_id']]) : null,
                channeledCampaign: isset($metricConfig['channeledCampaign_id']) ? ($channeledCampaignMap[$metricConfig['channeledCampaign_id']] instanceof ChanneledCampaign ? $channeledCampaignMap[$metricConfig['channeledCampaign_id']]->getPlatformId() : $channeledCampaignMap[$metricConfig['channeledCampaign_id']]) : null,
                channeledAdGroup: isset($metricConfig['channeledAdGroup_id']) ? ($channeledAdGroupMap[$metricConfig['channeledAdGroup_id']] instanceof ChanneledAdGroup ? $channeledAdGroupMap[$metricConfig['channeledAdGroup_id']]->getPlatformId() : $channeledAdGroupMap[$metricConfig['channeledAdGroup_id']]) : null,
                channeledAd: isset($metricConfig['channeledAd_id']) ? ($channeledAdMap[$metricConfig['channeledAd_id']] instanceof ChanneledAd ? $channeledAdMap[$metricConfig['channeledAd_id']]->getPlatformId() : $channeledAdMap[$metricConfig['channeledAd_id']]) : null,
                page: isset($metricConfig['page_id']) ? ($pageMap[$metricConfig['page_id']] instanceof Page ? $pageMap[$metricConfig['page_id']]->getUrl() : $pageMap[$metricConfig['page_id']]) : null,
                query: isset($metricConfig['query_id']) ? ($queryMap[$metricConfig['query_id']] instanceof Query ? $queryMap[$metricConfig['query_id']]->getQuery() : $queryMap[$metricConfig['query_id']]) : null,
                post: isset($metricConfig['post_id']) ? ($postMap[$metricConfig['post_id']] instanceof Post ? $postMap[$metricConfig['post_id']]->getPostId() : $postMap[$metricConfig['post_id']]) : null,
                product: isset($metricConfig['product_id']) ? ($productMap[$metricConfig['product_id']] instanceof Product ? $productMap[$metricConfig['product_id']]->getProductId() : $productMap[$metricConfig['product_id']]) : null,
                customer: isset($metricConfig['customer_id']) ? ($customerMap[$metricConfig['customer_id']] instanceof Customer ? $customerMap[$metricConfig['customer_id']]->getEmail() : $customerMap[$metricConfig['customer_id']]) : null,
                order: isset($metricConfig['order_id']) ? ($orderMap[$metricConfig['order_id']] instanceof Order ? $orderMap[$metricConfig['order_id']]->getOrderId() : $orderMap[$metricConfig['order_id']]) : null,
                country: isset($metricConfig['country_id']) ? ($countryMap[$metricConfig['country_id']] instanceof Country ? $countryMap[$metricConfig['country_id']]->getCode() : $countryMap[$metricConfig['country_id']]) : null,
                device: isset($metricConfig['device_id']) ? ($deviceMap[$metricConfig['device_id']] instanceof Device ? $deviceMap[$metricConfig['device_id']]->getType() : $deviceMap[$metricConfig['device_id']]) : null,
            );
            $metricConfigMap[$metricConfigKey] = (int)$metricConfig['id'];
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
                dimensionsHash: $metric['dimensionsHash'],
                metricConfigKey: $metricConfigMap['mapReverse'][$metric['metricConfig_id']]
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
            $key = KeyGenerator::generateChanneledCustomerKey((string) $channeledCustomer['channel'], (string) $channeledCustomer['platformId']);
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
            $key = KeyGenerator::generateProductKey($product['productId']);
            $map['map'][$key] = ['id' => (int)$product['id'], 'productId' => $product['productId'], 'sku' => $product['sku']];
            $map['mapReverse'][$product['id']] = $key;
        }
        return $map;
    }

    public static function getChanneledProductMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = [];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateChanneledProductKey((string)$item['channel'], (string)$item['platformId']);
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
            $key = KeyGenerator::generateProductVariantKey($item['productVariantId']);
            $map['map'][$key] = ['id' => (int)$item['id'], 'productVariantId' => $item['productVariantId'], 'sku' => $item['sku']];
            $map['mapReverse'][$item['id']] = $key;
        }
        return $map;
    }

    public static function getChanneledProductVariantMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = [];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateChanneledProductVariantKey((string)$item['channel'], (string)$item['platformId']);
            $map[$key] = ['id' => (int)$item['id'], 'data' => $item['data']];
        }
        return $map;
    }

    public static function getProductCategoryMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = ['map' => [], 'mapReverse' => []];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateProductCategoryKey($item['productCategoryId']);
            $map['map'][$key] = ['id' => (int)$item['id'], 'productCategoryId' => $item['productCategoryId']];
            $map['mapReverse'][$item['id']] = $key;
        }
        return $map;
    }

    public static function getChanneledProductCategoryMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = [];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateChanneledProductCategoryKey((string)$item['channel'], (string)$item['platformId']);
            $map[$key] = ['id' => (int)$item['id'], 'data' => $item['data']];
        }
        return $map;
    }

    public static function getOrderMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = ['map' => [], 'mapReverse' => []];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateOrderKey((string)$item['orderId']);
            $map['map'][$key] = ['id' => (int)$item['id'], 'orderId' => $item['orderId']];
            $map['mapReverse'][$item['id']] = $key;
        }
        return $map;
    }

    public static function getChanneledOrderMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = [];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateChanneledOrderKey((string)$item['channel'], (string)$item['platformId']);
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
            $key = KeyGenerator::generatePriceRuleKey((string)$item['priceRuleId']);
            $map['map'][$key] = ['id' => (int)$item['id'], 'priceRuleId' => $item['priceRuleId']];
            $map['mapReverse'][$item['id']] = $key;
        }
        return $map;
    }

    public static function getChanneledPriceRuleMap(EntityManager $manager, string $sql, array $params): array
    {
        $existing = $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
        $map = [];
        foreach ($existing as $item) {
            $key = KeyGenerator::generateChanneledPriceRuleKey((string)$item['channel'], (string)$item['platformId']);
            $map[$key] = ['id' => (int)$item['id'], 'data' => $item['data']];
        }
        return $map;
    }
}
