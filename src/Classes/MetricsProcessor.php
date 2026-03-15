<?php

namespace Classes;

use Carbon\Carbon;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Entities\Analytics\Channeled\DimensionSet;
use Entities\Analytics\Channeled\DimensionKey;
use Entities\Analytics\Channeled\DimensionValue;

class MetricsProcessor
{
    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processQueries(ArrayCollection $metrics, EntityManager $manager): array
    {
        // Extract queries from metrics
        $queries = array_map(function ($metric) {
            return $metric->query;
        }, $metrics->toArray());

        // Remove duplicates
        $uniqueQueries = array_unique($queries);

        // Batch select queries from list
        $selectParams = array_values($uniqueQueries);
        $selectPlaceholders = implode(', ', array_fill(0, count($selectParams), '?'));

        $sql = "SELECT id, query
                FROM queries
                WHERE query IN ($selectPlaceholders)";
        try {
            $existingQueries = $manager->getConnection()
                ->executeQuery($sql, $selectParams)
                ->fetchAllAssociative();
        } catch (Exception $e) {
            throw new RuntimeException("Failed to fetch existing queries: " . $e->getMessage(), 0, $e);
        }

        // Map queries to their IDs and create a map for quick access
        $map = [];
        foreach ($existingQueries as $query) {
            $map[$query['query']] = $query['id'];
        }

        // Get the list of queries that need to be inserted
        $queriesToInsert = array_diff($uniqueQueries, array_keys($map));

        // INSERT IGNORE: atomic upsert to handle race conditions
        if (!empty($queriesToInsert)) {
            $insertPlaceholders = implode(', ', array_fill(0, count($queriesToInsert), '(?)'));
            try {
                $manager->getConnection()->executeStatement(
                    "INSERT INTO queries (query) VALUES $insertPlaceholders
                     ON DUPLICATE KEY UPDATE query=query",
                    array_values($queriesToInsert)
                );
            } catch (Exception $e) {
                throw new RuntimeException("Failed to insert queries: " . $e->getMessage(), 0, $e);
            }
        }

        // Always re-fetch ALL unique queries to ensure map is complete
        $selectParams = array_values($uniqueQueries);
        $selectPlaceholders = implode(', ', array_fill(0, count($selectParams), '?'));

        $sql = "SELECT id, query
                FROM queries
                WHERE query IN ($selectPlaceholders)";
        try {
            $finalQueries = $manager->getConnection()
                ->executeQuery($sql, $selectParams)
                ->fetchAllAssociative();
        } catch (Exception $e) {
            throw new RuntimeException("Failed to fetch existing queries: " . $e->getMessage(), 0, $e);
        }
        foreach ($finalQueries as $query) {
            $map[$query['query']] = $query['id'];
        }

        return [
            'map' => $map,
            'mapReverse' => array_flip($map),
        ];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processAccounts(ArrayCollection $metrics, EntityManager $manager): array
    {
        return [
            'map' => [],
            'mapReverse' => [],
        ];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processChanneledAccounts(ArrayCollection $metrics, EntityManager $manager): array
    {
        return [
            'map' => [],
            'mapReverse' => [],
        ];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processCampaigns(ArrayCollection $metrics, EntityManager $manager): array
    {
        return [
            'map' => [],
            'mapReverse' => [],
        ];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processChanneledCampaigns(ArrayCollection $metrics, EntityManager $manager): array
    {
        return [
            'map' => [],
            'mapReverse' => [],
        ];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processChanneledAdGroups(ArrayCollection $metrics, EntityManager $manager): array
    {
        return [
            'map' => [],
            'mapReverse' => [],
        ];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processChanneledAds(ArrayCollection $metrics, EntityManager $manager): array
    {
        return [
            'map' => [],
            'mapReverse' => [],
        ];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processCreatives(ArrayCollection $metrics, EntityManager $manager): array
    {
        return [
            'map' => [],
            'mapReverse' => [],
        ];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processPosts(ArrayCollection $metrics, EntityManager $manager): array
    {
        return [
            'map' => [],
            'mapReverse' => [],
        ];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processProducts(ArrayCollection $metrics, EntityManager $manager): array
    {
        return [
            'map' => [],
            'mapReverse' => [],
        ];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processCustomers(ArrayCollection $metrics, EntityManager $manager): array
    {
        return [
            'map' => [],
            'mapReverse' => [],
        ];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processOrders(ArrayCollection $metrics, EntityManager $manager): array
    {
        return [
            'map' => [],
            'mapReverse' => [],
        ];
    }

    /**
     * Processes metrics and returns a map of metric IDs.
     */
    public static function processMetricConfigs(
        ArrayCollection $metrics,
        EntityManager $manager,
        bool $processQueries = false,
        bool $processAccounts = false,
        bool $processChanneledAccounts = false,
        bool $processCampaigns = false,
        bool $processChanneledCampaigns = false,
        bool $processChanneledAdGroups = false,
        bool $processChanneledAds = false,
        bool $processPosts = false,
        bool $processProducts = false,
        bool $processCustomers = false,
        bool $processOrders = false,
        ?array $countryMap = null,
        ?array $deviceMap = null,
        ?array $pageMap = null,
        ?array $postMap = null,
        ?array $accountMap = null,
        ?array $channeledAccountMap = null,
        ?array $campaignMap = null,
        ?array $channeledCampaignMap = null,
        ?array $channeledAdGroupMap = null,
        ?array $channeledAdMap = null,
        ?array $creativeMap = null,
    ): array {

        // Initialize null maps
        $queryMap = null;
        $productMap = null;
        $customerMap = null;
        $orderMap = null;

        // Map queries
        if ($processQueries) {
            $queryMap = self::processQueries($metrics, $manager);
        }

        // Map accounts
        if ($processAccounts) {
            $accountMap = self::processAccounts($metrics, $manager);
        }

        // Map channeled accounts
        if ($processChanneledAccounts) {
            $channeledAccountMap = self::processChanneledAccounts($metrics, $manager);
        }

        // Map campaigns
        if ($processCampaigns) {
            $campaignMap = self::processCampaigns($metrics, $manager);
        }

        // Map channeled campaigns
        if ($processChanneledCampaigns) {
            $channeledCampaignMap = self::processChanneledCampaigns($metrics, $manager);
        }

        // Map channeled campaigns
        if ($processChanneledAdGroups) {
            $channeledAdGroupMap = self::processChanneledAdGroups($metrics, $manager);
        }

        // Map channeled ads
        if ($processChanneledAds) {
            $channeledAdMap = self::processChanneledAds($metrics, $manager);
        }

        // Map posts
        if ($processPosts) {
            $postMap = self::processPosts($metrics, $manager);
        }

        // Map products
        if ($processProducts) {
            $productMap = self::processProducts($metrics, $manager);
        }

        // Map customers
        if ($processCustomers) {
            $customerMap = self::processCustomers($metrics, $manager);
        }

        // Map orders
        if ($processOrders) {
            $orderMap = self::processOrders($metrics, $manager);
        }

        // Extract metrics from metrics
        $uniqueMetricConfigs = [];
        foreach ($metrics->toArray() as $metric) {
            $metricConfigKey = KeyGenerator::generateMetricConfigKey(
                channel: $metric->channel,
                name: $metric->name,
                period: $metric->period,
                metricDate: $metric->metricDate instanceof DateTime ? $metric->metricDate->format('Y-m-d') : $metric->metricDate,
                account: isset($metric->account) ? $metric->account->getName() : null,
                channeledAccount: isset($metric->channeledAccount) ? (string) $metric->channeledAccount->getPlatformId() : null,
                campaign: isset($metric->campaign) ? (string) $metric->campaign->getCampaignId() : null,
                channeledCampaign: isset($metric->channeledCampaign) ? (string) $metric->channeledCampaign->getPlatformId() : null,
                channeledAdGroup: isset($metric->channeledAdGroup) ? $metric->channeledAdGroup->getPlatformId() : null,
                channeledAd: isset($metric->channeledAd) ? $metric->channeledAd->getPlatformId() : null,
                page: isset($metric->page) ? $metric->page->getUrl() : null,
                query: $metric->query ?? null,
                post: isset($metric->post) ? $metric->post->getPostId() : null,
                product: isset($metric->product) ? $metric->product->getProductId() : null,
                customer: isset($metric->customer) ? $metric->customer->getEmail() : null,
                order: isset($metric->order) ? $metric->order->getOrderId() : null,
                country: $metric->countryCode ?? null,
                device: $metric->deviceType ?? null,
                creative: isset($metric->creative) ? $metric->creative->getCreativeId() : null,
            );

            $uniqueMetricConfigs[$metricConfigKey] = [
                'channel' => $metric->channel,
                'name' => $metric->name,
                'period' => $metric->period,
                'metricDate' => $metric->metricDate instanceof DateTime ? $metric->metricDate->format('Y-m-d') : $metric->metricDate,
                'account_id' => isset($metric->account) ? $metric->account->getId() : null,
                'channeledAccount_id' => isset($metric->channeledAccount) ? $channeledAccountMap['map'][$metric->channeledAccount->getPlatformId()] ?? null : null,
                'campaign_id' => isset($metric->campaign) ? $campaignMap['map'][$metric->campaign->getCampaignId()] ?? null : null,
                'channeledCampaign_id' => isset($metric->channeledCampaign) ? $channeledCampaignMap['map'][$metric->channeledCampaign->getPlatformId()] ?? null : null,
                'channeledAdGroup_id' => isset($metric->channeledAdGroup) ? $channeledAdGroupMap['map'][$metric->channeledAdGroup->getPlatformId()] ?? null : null,
                'channeledAd_id' => isset($metric->channeledAd) ? $channeledAdMap['map'][$metric->channeledAd->getPlatformId()] ?? null : null,
                'query_id' => isset($metric->query) ? $queryMap['map'][$metric->query] : null,
                'page_id' => isset($metric->page) ? $metric->page->getId() : null,
                'post_id' => isset($metric->post) ? $postMap['map'][$metric->post->getPostId()] ?? null : null,
                'product_id' => isset($metric->product) ? $productMap['map'][$metric->product->getProductId()] ?? null : null,
                'customer_id' => isset($metric->customer) ? $customerMap['map'][$metric->customer->getEmail()] ?? null : null,
                'order_id' => isset($metric->order) ? $orderMap['map'][$metric->order->getOrderId()] ?? null : null,
                'country_id' => isset($metric->countryCode) ? $countryMap['map'][$metric->countryCode]->getId() : null,
                'device_id' => isset($metric->deviceType) ? $deviceMap['map'][$metric->deviceType]->getId() : null,
                'creative_id' => isset($metric->creative) ? $creativeMap['map'][$metric->creative->getCreativeId()] ?? null : null,
                'value' => $metric->value,
                'metadata' => $metric->metadata,
                'key' => $metricConfigKey,
            ];
        }

        // Batch select metrics from list by signature
        $conditions = [];
        $selectParams = [];

        foreach ($uniqueMetricConfigs as $mc) {
            $conditions[] = 'configSignature = ?';
            $selectParams[] = $mc['key'];
        }

        $sql = "SELECT id, configSignature
                FROM metric_configs
                WHERE " . (empty($conditions) ? '1=0' : implode(' OR ', $conditions));

        // Map metric configs to their IDs
        $metricConfigMap = MapGenerator::getMetricConfigMap(
            manager: $manager,
            sql: $sql,
            params: $selectParams,
        );

        // Get the list of metrics that need to be inserted
        $metricConfigsToInsert = [];
        foreach ($uniqueMetricConfigs as $key => $metricConfig) {
            if (!isset($metricConfigMap[$key])) {
                $metricConfigsToInsert[] = [
                    'channel' => $metricConfig['channel'],
                    'name' => $metricConfig['name'],
                    'period' => $metricConfig['period'],
                    'metricDate' => $metricConfig['metricDate'],
                    'account_id' => $metricConfig['account_id'] ?? null,
                    'channeledAccount_id' => $metricConfig['channeledAccount_id'] ?? null,
                    'campaign_id' => $metricConfig['campaign_id'] ?? null,
                    'channeledCampaign_id' => $metricConfig['channeledCampaign_id'] ?? null,
                    'channeledAdGroup_id' => $metricConfig['channeledAdGroup_id'] ?? null,
                    'channeledAd_id' => $metricConfig['channeledAd_id'] ?? null,
                    'query_id' => $metricConfig['query_id'] ?? null,
                    'page_id' => $metricConfig['page_id'] ?? null,
                    'post_id' => $metricConfig['post_id'] ?? null,
                    'product_id' => $metricConfig['product_id'] ?? null,
                    'customer_id' => $metricConfig['customer_id'] ?? null,
                    'order_id' => $metricConfig['order_id'] ?? null,
                    'country_id' => $metricConfig['country_id'] ?? null,
                    'device_id' => $metricConfig['device_id'] ?? null,
                    'key' => $key,
                ];
            }
        }

        // INSERT IGNORE: atomic upsert.
        if (!empty($uniqueMetricConfigs)) {
            $insertParams = [];
            foreach ($uniqueMetricConfigs as $metricConfig) {
                $insertParams[] = $metricConfig['channel'];
                $insertParams[] = $metricConfig['name'];
                $insertParams[] = $metricConfig['period'];
                $insertParams[] = $metricConfig['metricDate'];
                $insertParams[] = $metricConfig['account_id'] ?? null;
                $insertParams[] = $metricConfig['channeledAccount_id'] ?? null;
                $insertParams[] = $metricConfig['campaign_id'] ?? null;
                $insertParams[] = $metricConfig['channeledCampaign_id'] ?? null;
                $insertParams[] = $metricConfig['channeledAdGroup_id'] ?? null;
                $insertParams[] = $metricConfig['channeledAd_id'] ?? null;
                $insertParams[] = $metricConfig['creative_id'] ?? null;
                $insertParams[] = $metricConfig['query_id'] ?? null;
                $insertParams[] = $metricConfig['page_id'] ?? null;
                $insertParams[] = $metricConfig['post_id'] ?? null;
                $insertParams[] = $metricConfig['product_id'] ?? null;
                $insertParams[] = $metricConfig['customer_id'] ?? null;
                $insertParams[] = $metricConfig['order_id'] ?? null;
                $insertParams[] = $metricConfig['country_id'] ?? null;
                $insertParams[] = $metricConfig['device_id'] ?? null;
                $insertParams[] = $metricConfig['key']; // configSignature
            }
            $manager->getConnection()->executeStatement(
                'INSERT IGNORE INTO metric_configs (channel, name, period, metricDate, account_id, channeledAccount_id, campaign_id, channeledCampaign_id, channeledAdGroup_id,
                            channeledAd_id, creative_id, query_id, page_id, post_id, product_id, customer_id, order_id, country_id, device_id, configSignature)
                     VALUES ' . implode(', ', array_fill(0, count($uniqueMetricConfigs), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')),
                $insertParams
            );
        }

        // Re-fetch all metric_configs
        $reFetchParams = [];
        $conditions = [];
        foreach ($uniqueMetricConfigs as $row) {
            $conditions[] = 'configSignature = ?';
            $reFetchParams[] = $row['key'];
        }

        $reFetchSql = "SELECT id, configSignature FROM metric_configs WHERE " . (empty($conditions) ? '1=0' : implode(' OR ', $conditions));
        $allMetricConfigs = $manager->getConnection()->executeQuery($reFetchSql, $reFetchParams)->fetchAllAssociative();
        foreach ($allMetricConfigs as $metricConfig) {
            $metricConfigMap[$metricConfig['configSignature']] = (int)$metricConfig['id'];
        }

        return [
            'map' => $metricConfigMap,
            'mapReverse' => array_flip($metricConfigMap),
        ];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @param array $metricConfigMap
     * @return array
     */
    public static function processMetrics(
        ArrayCollection $metrics,
        EntityManager $manager,
        array $metricConfigMap,
    ): array {
        $uniqueMetrics = [];
        foreach ($metrics->toArray() as $metric) {
            $dimensions = array_map(function ($dimension) {
                return [ 'dimensionKey' => $dimension['dimensionKey'], 'dimensionValue' => $dimension['dimensionValue'] ];
            }, $metric->dimensions);
            $dimensionsHash = KeyGenerator::generateDimensionsHash($dimensions);
            $metricKey = KeyGenerator::generateMetricKey(
                dimensionsHash: $dimensionsHash,
                metricConfigKey: $metric->metricConfigKey,
            );

            KeyGenerator::sortDimensions($dimensions);
            $uniqueMetrics[$metricKey] = [
                'value' => $metric->value,
                'metadata' => $metric->metadata,
                'dimensionsHash' => $dimensionsHash,
                'metricConfig_id' => $metricConfigMap['map'][$metric->metricConfigKey],
            ];
        }

        // Select existing
        $conditions = [];
        $selectParams = [];
        $fields = ['dimensionsHash', 'metricConfig_id'];

        foreach ($uniqueMetrics as $m) {
            $subConditions = [];
            foreach ($fields as $field) {
                if (array_key_exists($field, $m) && $m[$field] === null) {
                    $subConditions[] = "$field IS NULL";
                } else {
                    $subConditions[] = "$field = ?";
                    $selectParams[] = $m[$field];
                }
            }
            $conditions[] = '(' . implode(' AND ', $subConditions) . ')';
        }

        $sql = "SELECT id, " . implode(', ', $fields) . "
                FROM metrics
                WHERE " . (empty($conditions) ? '1=0' : implode(' OR ', $conditions));

        $metricMap = MapGenerator::getMetricMap(
            manager: $manager,
            sql: $sql,
            params: $selectParams,
            metricConfigMap: $metricConfigMap
        );

        $metricsToInsert = [];
        $metricsToUpdate = [];
        foreach ($uniqueMetrics as $key => $metric) {
            if (!isset($metricMap[$key])) {
                $metricsToInsert[] = [
                    'value' => $metric['value'],
                    'metadata' => json_encode($metric['metadata'] ?? []),
                    'dimensionsHash' => $metric['dimensionsHash'],
                    'metricConfig_id' => $metric['metricConfig_id'],
                    'key' => $key,
                ];
            } else {
                $metricsToUpdate[] = [
                    'id' => $metricMap[$key],
                    'value' => $metric['value']
                ];
            }
        }

        if (!empty($metricsToInsert)) {
            $insertParams = [];
            foreach ($metricsToInsert as $row) {
                $insertParams[] = $row['value'];
                $insertParams[] = $row['metadata'];
                $insertParams[] = $row['dimensionsHash'];
                $insertParams[] = $row['metricConfig_id'];
            }
            $manager->getConnection()->executeStatement(
                'INSERT IGNORE INTO metrics (value, metadata, dimensionsHash, metricConfig_id)
                     VALUES ' . implode(', ', array_fill(0, count($metricsToInsert), '(?, ?, ?, ?)')),
                $insertParams
            );

            $reFetchParams = [];
            $reFetchConditions = [];
            foreach ($metricsToInsert as $row) {
                $reFetchConditions[] = '(dimensionsHash = ? AND metricConfig_id = ?)';
                $reFetchParams[] = $row['dimensionsHash'];
                $reFetchParams[] = $row['metricConfig_id'];
            }
            $reFetchSql = 'SELECT id, dimensionsHash, metricConfig_id FROM metrics WHERE ' . implode(' OR ', $reFetchConditions);
            $newMetrics = $manager->getConnection()->executeQuery($reFetchSql, $reFetchParams)->fetchAllAssociative();
            foreach ($newMetrics as $metric) {
                $metricKey = KeyGenerator::generateMetricKey(
                    dimensionsHash: $metric['dimensionsHash'],
                    metricConfigKey: $metricConfigMap['mapReverse'][$metric['metricConfig_id']],
                );
                $metricMap[$metricKey] = (int)$metric['id'];
            }
        }

        if (!empty($metricsToUpdate)) {
            foreach ($metricsToUpdate as $update) {
                $manager->getConnection()->executeStatement(
                    "UPDATE metrics SET value = GREATEST(COALESCE(value, 0), ?) WHERE id = ?",
                    [$update['value'], $update['id']]
                );
            }
        }

        return [
            'map' => $metricMap,
            'mapReverse' => array_flip($metricMap),
        ];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @param array $metricMap
     * @param LoggerInterface $logger
     * @return array
     */
    public static function processChanneledMetrics(
        ArrayCollection $metrics,
        EntityManager $manager,
        array $metricMap,
        LoggerInterface $logger,
    ): array {
        $uniqueChanneledMetrics = [];
        foreach ($metrics->toArray() as $metric) {
            $metricKey = KeyGenerator::generateMetricKey(
                dimensionsHash: $metric->dimensionsHash,
                metricConfigKey: $metric->metricConfigKey,
            );

            if (!isset($metricMap['map'][$metricKey])) continue;
            if (empty($metric->platformId)) continue;

            $channeledMetricKey = KeyGenerator::generateChanneledMetricKey(
                channel: $metric->channel,
                platformId: $metric->platformId,
                metric: $metricMap['map'][$metricKey],
                platformCreatedAt: Carbon::parse($metric->platformCreatedAt)->format('Y-m-d'),
            );

            $uniqueChanneledMetrics[$channeledMetricKey] = [
                'channel' => $metric->channel,
                'platformId' => $metric->platformId,
                'metric_id' => $metricMap['map'][$metricKey],
                'platformCreatedAt' => Carbon::parse($metric->platformCreatedAt)->format('Y-m-d'),
                'data' => $metric->data ?? [],
                'metricKey' => $metricKey,
            ];
        }

        $conditions = [];
        $selectParams = [];
        foreach ($uniqueChanneledMetrics as $m) {
            $conditions[] = '(channel = ? AND platformId = ? AND metric_id = ? AND platformCreatedAt = ?)';
            $selectParams[] = $m['channel'];
            $selectParams[] = $m['platformId'];
            $selectParams[] = $m['metric_id'];
            $selectParams[] = $m['platformCreatedAt'];
        }

        $sql = "SELECT id, channel, platformId, metric_id, platformCreatedAt, data
            FROM channeled_metrics
            WHERE " . (empty($conditions) ? '1=0' : implode(' OR ', $conditions));

        $channeledMetricMap = MapGenerator::getChanneledMetricMap($manager, $sql, $selectParams, $metricMap);

        $dimManager = new \Classes\DimensionManager($manager);
        $channeledMetricsToInsert = [];
        $channeledMetricsToUpdate = [];
        foreach ($uniqueChanneledMetrics as $key => $channeledMetric) {
            if (!isset($channeledMetricMap[$key]) && !isset($channeledMetricsToInsert[$key])) {
                $originalMetric = null;
                foreach ($metrics as $m) {
                    $mKey = KeyGenerator::generateMetricKey(dimensionsHash: $m->dimensionsHash, metricConfigKey: $m->metricConfigKey);
                    if ($mKey === $channeledMetric['metricKey']) {
                        $originalMetric = $m;
                        break;
                    }
                }

                $dimensionSetId = null;
                if ($originalMetric && isset($originalMetric->dimensions) && !empty($originalMetric->dimensions)) {
                    $dimensionSetId = $dimManager->resolveDimensionSet((array)$originalMetric->dimensions)->getId();
                }

                $channeledMetricsToInsert[$key] = [
                    'channel' => $channeledMetric['channel'],
                    'platformId' => $channeledMetric['platformId'],
                    'metric_id' => $channeledMetric['metric_id'],
                    'platformCreatedAt' => $channeledMetric['platformCreatedAt'],
                    'data' => json_encode($channeledMetric['data']),
                    'dimension_set_id' => $dimensionSetId
                ];
            } elseif (isset($channeledMetricsToInsert[$key])) {
                $data = json_decode($channeledMetricsToInsert[$key]['data'], true);
                $data = array_merge($data, $channeledMetric['data']);
                $channeledMetricsToInsert[$key]['data'] = json_encode($data);
            } else {
                $data = json_decode($channeledMetricMap[$key]['data'], true);
                $data = array_merge($data, $channeledMetric['data']);
                $channeledMetricsToUpdate[$key] = [
                    'id' => $channeledMetricMap[$key]['id'],
                    'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        if (!empty($channeledMetricsToInsert)) {
            $placeholders = implode(', ', array_fill(0, count($channeledMetricsToInsert), '(?, ?, ?, ?, ?, ?)'));
            $params = [];
            foreach ($channeledMetricsToInsert as $row) {
                $params[] = $row['channel']; $params[] = $row['platformId']; $params[] = $row['metric_id'];
                $params[] = $row['platformCreatedAt']; $params[] = $row['data']; $params[] = $row['dimension_set_id'];
            }
            $manager->getConnection()->executeStatement(
                "INSERT IGNORE INTO channeled_metrics (channel, platformId, metric_id, platformCreatedAt, data, dimension_set_id) VALUES $placeholders",
                $params
            );
        }

        if (!empty($channeledMetricsToUpdate)) {
            foreach ($channeledMetricsToUpdate as $update) {
                $manager->getConnection()->executeStatement(
                    "UPDATE channeled_metrics SET data = ? WHERE id = ?",
                    [$update['data'], $update['id']]
                );
            }
        }

        $channeledMetricMap = MapGenerator::getChanneledMetricMap($manager, $sql, $selectParams, $metricMap);
        $flipped = [];
        foreach ($channeledMetricMap as $k => $v) {
            if (isset($v['id'])) $flipped[(string)$v['id']] = ['id' => $k, 'data' => $v['data']];
        }

        return ['map' => $channeledMetricMap, 'mapReverse' => $flipped];
    }

}
