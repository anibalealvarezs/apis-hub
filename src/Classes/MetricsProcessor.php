<?php

namespace Classes;

use Carbon\Carbon;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

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

        // Bulk Insert queries that are not in the database with a single statement, letting SQL handle the auto-increment
        if (!empty($queriesToInsert)) {
            $insertPlaceholders = implode(', ', array_fill(0, count($queriesToInsert), '(?)'));
            try {
                $manager->getConnection()->executeStatement(
                    "INSERT INTO queries (query) VALUES $insertPlaceholders",
                    array_values($queriesToInsert)
                );
            } catch (Exception $e) {
                throw new RuntimeException("Failed to insert queries: " . $e->getMessage(), 0, $e);
            }
        }

        $allQueries = array_merge(array_keys($map), array_values($queriesToInsert));
        $selectParams = array_values($allQueries);
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
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @param array $countryMap
     * @param array $deviceMap
     * @param array $pageMap
     * @return array
     * @throws Exception
     */
    public static function processMetrics(
        ArrayCollection $metrics,
        EntityManager $manager,
        array $countryMap,
        array $deviceMap,
        array $pageMap,
    ): array {

        // Map queries
        $queryMap = self::processQueries(
            $metrics,
            $manager,
        );

        // Map accounts
        $accountMap = self::processAccounts(
            $metrics,
            $manager,
        );

        // Map channeled accounts
        $channeledAccountMap = self::processChanneledAccounts(
            $metrics,
            $manager,
        );

        // Map campaigns
        $campaignMap = self::processCampaigns(
            $metrics,
            $manager,
        );

        // Map channeled campaigns
        $channeledCampaignMap = self::processChanneledCampaigns(
            $metrics,
            $manager,
        );

        // Map channeled campaigns
        $channeledAdGroupMap = self::processChanneledAdGroups(
            $metrics,
            $manager,
        );

        // Map channeled ads
        $channeledAdMap = self::processChanneledAds(
            $metrics,
            $manager,
        );

        // Map posts
        $postMap = self::processPosts(
            $metrics,
            $manager,
        );

        // Map products
        $productMap = self::processProducts(
            $metrics,
            $manager,
        );

        // Map customers
        $customerMap = self::processCustomers(
            $metrics,
            $manager,
        );

        // Map orders
        $orderMap = self::processOrders(
            $metrics,
            $manager,
        );

        // Extract metrics from metrics
        $uniqueMetrics = [];
        foreach ($metrics->toArray() as $metric) {
            $metricKey = KeyGenerator::generateMetricKey(
                channel: $metric->channel,
                name: $metric->name,
                period: $metric->period,
                metricDate: $metric->metricDate instanceof DateTime ? $metric->metricDate->format('Y-m-d') : $metric->metricDate,
                account: isset($metric->account) ? $metric->account->getAccountId() : null,
                channeledAccount: isset($metric->channeledAccount) ? $metric->channeledAccount->getPlatformId() : null,
                campaign: isset($metric->campaign) ? $metric->campaign->getCampaignId() : null,
                channeledCampaign: isset($metric->channeledCampaign) ? $metric->channeledCampaign->getPlatformId() : null,
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
            );

            $uniqueMetrics[$metricKey] = [
                'channel' => $metric->channel,
                'name' => $metric->name,
                'period' => $metric->period,
                'metricDate' => $metric->metricDate instanceof DateTime ? $metric->metricDate->format('Y-m-d') : $metric->metricDate,
                'account_id' => isset($metric->account) ? $accountMap['map'][$metric->account->getAccountId()] ?? null : null,
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
                'value' => $metric->value,
                'metadata' => $metric->metadata,
                'key' => $metricKey,
            ];
        }

        // Batch select metrics from list
        $conditions = [];
        $selectParams = [];

        $fields = [
            'channel', 'name', 'period', 'metricDate', 'account_id', 'channeledAccount_id', 'campaign_id', 'channeledCampaign_id', 'channeledAdGroup_id',
            'channeledAd_id', 'query_id', 'page_id', 'post_id', 'product_id', 'customer_id', 'order_id', 'country_id', 'device_id'
        ];

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

        // Map metrics to their IDs
        $metricMap = MapGenerator::getMetricMap(
            manager: $manager,
            sql: $sql,
            params: $selectParams,
            accountMap: $accountMap['mapReverse'],
            channeledAccountMap: $channeledAccountMap['mapReverse'],
            campaignMap: $campaignMap['mapReverse'],
            channeledCampaignMap: $channeledCampaignMap['mapReverse'],
            channeledAdGroupMap: $channeledAdGroupMap['mapReverse'],
            channeledAdMap: $channeledAdMap['mapReverse'],
            pageMap: $pageMap['mapReverse'],
            queryMap: $queryMap['mapReverse'],
            postMap: $postMap['mapReverse'],
            productMap: $productMap['mapReverse'],
            customerMap: $customerMap['mapReverse'],
            orderMap: $orderMap['mapReverse'],
            countryMap: $countryMap['mapReverse'],
            deviceMap: $deviceMap['mapReverse']
        );

        // Get the list of metrics that need to be inserted
        $metricsToInsert = [];
        foreach ($uniqueMetrics as $key => $metric) {
            if (!isset($metricMap[$key])) {
                $metricsToInsert[] = [
                    'channel' => $metric['channel'],
                    'name' => $metric['name'],
                    'period' => $metric['period'],
                    'metricDate' => $metric['metricDate'],
                    'account_id' => $metric['account_id'] ?? null,
                    'channeledAccount_id' => $metric['channeledAccount_id'] ?? null,
                    'campaign_id' => $metric['campaign_id'] ?? null,
                    'channeledCampaign_id' => $metric['channeledCampaign_id'] ?? null,
                    'channeledAdGroup_id' => $metric['channeledAdGroup_id'] ?? null,
                    'channeledAd_id' => $metric['channeledAd_id'] ?? null,
                    'query_id' => $metric['query_id'] ?? null,
                    'page_id' => $metric['page_id'] ?? null,
                    'post_id' => $metric['post_id'] ?? null,
                    'product_id' => $metric['product_id'] ?? null,
                    'customer_id' => $metric['customer_id'] ?? null,
                    'order_id' => $metric['order_id'] ?? null,
                    'country_id' => $metric['country_id'] ?? null,
                    'device_id' => $metric['device_id'] ?? null,
                    'value' => $metric['value'],
                    'metadata' => json_encode($metric['metadata'] ?? []),
                    'key' => $key,
                ];
            }
        }

        // Bulk Insert metrics
        if (!empty($metricsToInsert)) {
            $insertParams = [];
            foreach ($metricsToInsert as $row) {
                $insertParams[] = $row['channel'];
                $insertParams[] = $row['name'];
                $insertParams[] = $row['period'];
                $insertParams[] = $row['metricDate'];
                $insertParams[] = $row['account_id'] ?? null;
                $insertParams[] = $row['channeledAccount_id'] ?? null;
                $insertParams[] = $row['campaign_id'] ?? null;
                $insertParams[] = $row['channeledCampaign_id'] ?? null;
                $insertParams[] = $row['channeledAdGroup_id'] ?? null;
                $insertParams[] = $row['channeledAd_id'] ?? null;
                $insertParams[] = $row['query_id'] ?? null;
                $insertParams[] = $row['page_id'] ?? null;
                $insertParams[] = $row['post_id'] ?? null;
                $insertParams[] = $row['product_id'] ?? null;
                $insertParams[] = $row['customer_id'] ?? null;
                $insertParams[] = $row['order_id'] ?? null;
                $insertParams[] = $row['country_id'] ?? null;
                $insertParams[] = $row['device_id'] ?? null;
                $insertParams[] = $row['value'];
                $insertParams[] = $row['metadata'];
            }
            // $logger->info("Inserting " . count($metricsToInsert) . " new metrics");
            $manager->getConnection()->executeStatement(
                'INSERT INTO metrics (channel, name, period, metricDate, account_id, channeledAccount_id, campaign_id, channeledCampaign_id, channeledAdGroup_id,
                            channeledAd_id, query_id, page_id, post_id, product_id, customer_id, order_id, country_id, device_id, value, metadata)
                     VALUES ' . implode(', ', array_fill(0, count($metricsToInsert), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')),
                $insertParams
            );

            // Re-fetch inserted metrics to get correct IDs
            $reFetchParams = [];
            $conditions = [];

            $fields = [
                'channel', 'name', 'period', 'metricDate', 'account_id', 'channeledAccount_id',
                'campaign_id', 'channeledCampaign_id', 'channeledAdGroup_id', 'channeledAd_id',
                'query_id', 'page_id', 'post_id', 'product_id',
                'customer_id', 'order_id', 'country_id', 'device_id'
            ];

            foreach ($metricsToInsert as $row) {
                $subConditions = [];
                foreach ($fields as $field) {
                    if (!array_key_exists($field, $row) || $row[$field] === null) {
                        $subConditions[] = "$field IS NULL";
                    } else {
                        $subConditions[] = "$field = ?";
                        $reFetchParams[] = $row[$field];
                    }
                }
                $conditions[] = '(' . implode(' AND ', $subConditions) . ')';
            }

            $reFetchSql = "SELECT id, " . implode(', ', $fields) . "
                            FROM metrics
                            WHERE " . (empty($conditions) ? '1=0' : implode(' OR ', $conditions));

            $newMetrics = $manager->getConnection()->executeQuery($reFetchSql, $reFetchParams)->fetchAllAssociative();

            foreach ($newMetrics as $metric) {
                $metricKey = KeyGenerator::generateMetricKey(
                    channel: $metric['channel'],
                    name: $metric['name'],
                    period: $metric['period'],
                    metricDate: $metric['metricDate'],
                    account: isset($metric['account_id']) ? $accountMap['mapReverse'][$metric['account_id']] : null,
                    channeledAccount: isset($metric['channeledAccount_id']) ? $channeledAccountMap['mapReverse'][$metric['channeledAccount_id']] : null,
                    campaign: isset($metric['campaign_id']) ? $campaignMap['mapReverse'][$metric['campaign_id']] : null,
                    channeledCampaign: isset($metric['channeledCampaign_id']) ? $channeledCampaignMap['mapReverse'][$metric['channeledCampaign_id']] : null,
                    channeledAdGroup: isset($metric['channeledAdGroup_id']) ? $channeledAdGroupMap['mapReverse'][$metric['channeledAdGroup_id']] : null,
                    channeledAd: isset($metric['channeledAd_id']) ? $channeledAdMap['mapReverse'][$metric['channeledAd_id']] : null,
                    page: isset($metric['page_id']) ? $pageMap['mapReverse'][$metric['page_id']]->getUrl() : null,
                    query: isset($metric['query_id']) ? $queryMap['mapReverse'][$metric['query_id']] : null,
                    post: isset($metric['post_id']) ? $postMap['mapReverse'][$metric['post_id']] : null,
                    product: isset($metric['product_id']) ? $productMap['mapReverse'][$metric['product_id']] : null,
                    customer: isset($metric['customer_id']) ? $customerMap['mapReverse'][$metric['customer_id']] : null,
                    order: isset($metric['order_id']) ? $orderMap['mapReverse'][$metric['order_id']] : null,
                    country: isset($metric['country_id']) ? $countryMap['mapReverse'][$metric['country_id']]->getCode() : null,
                    device: isset($metric['device_id']) ? $deviceMap['mapReverse'][$metric['device_id']]->getType() : null,
                );
                $metricMap[$metricKey] = (int)$metric['id'];
                // $logger->info("Added metric to map: metricKey=$metricKey, metric_id={$metric['id']}");
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
     * @throws Exception
     */
    public static function processChanneledMetrics(
        ArrayCollection $metrics,
        EntityManager $manager,
        array $metricMap,
        LoggerInterface $logger,
    ): array {
        // $logger->info("Processing " . count($metrics->toArray()) . " metrics in processChanneledMetrics");
        // Extract channeled metrics from metrics
        $uniqueChanneledMetrics = [];
        foreach ($metrics->toArray() as $metric) {
            $metricKey = KeyGenerator::generateMetricKey(
                channel: $metric->channel,
                name: $metric->name,
                period: $metric->period,
                metricDate: $metric->metricDate instanceof DateTime ? $metric->metricDate->format('Y-m-d') : $metric->metricDate,
                account: isset($metric->account) ? $metric->account->getAccountId() : null,
                channeledAccount: isset($metric->channeledAccount) ? $metric->channeledAccount->getPlatformId() : null,
                campaign: isset($metric->campaign) ? $metric->campaign->getCampaignId() : null,
                channeledCampaign: isset($metric->channeledCampaign) ? $metric->channeledCampaign->getPlatformId() : null,
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
            );

            if (!isset($metricMap['map'][$metricKey])) {
                $logger->warning("Skipping channeled metric due to missing metricKey: metricKey=$metricKey, query=$metric->query, page={$metric->page->getUrl()}");
                continue;
            }
            if (empty($metric->platformId)) {
                $logger->warning("Skipping channeled metric: platformId is empty, metricKey=$metricKey");
                continue;
            }

            // $logger->info("ChanneledMetrics Inputs: metricKey=$metricKey, channel={$metric->channel}, platformId=" . ($metric->platformId ?? 'null') . ", metric_id={$metricMap['map'][$metricKey]}, platformCreatedAt=" . ($metric->platformCreatedAt->format('Y-m-d H:i:s')));
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
                'data' => $metric->data ?? ['impressions' => 0, 'clicks' => 0, 'position_weighted' => 0, 'ctr' => 0],
                'metricKey' => $metricKey,
            ];
            // $logger->info("Prepared channeled metric: channeledMetricKey=$channeledMetricKey, metricKey=$metricKey, metric_id={$metricMap['map'][$metricKey]}, platformId=$metric->platformId");
        }

        // Batch select channeled metrics from list
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

        // $logger->info("uniqueChanneledMetrics count: " . count($uniqueChanneledMetrics));
        // Map metrics to their IDs
        $channeledMetricMap = MapGenerator::getChanneledMetricMap(
            $manager,
            $sql,
            $selectParams,
            $metricMap,
        );
        // $logger->info("channeledMetricMap count after re-fetch: " . count($channeledMetricMap));

        // Get list of channeled metrics that need to be inserted and updated
        $channeledMetricsToInsert = [];
        $channeledMetricsToUpdate = [];
        foreach ($uniqueChanneledMetrics as $key => $channeledMetric) {
            if (!isset($channeledMetricMap[$key]) && !isset($channeledMetricsToInsert[$key])) {
                $channeledMetricsToInsert[$key] = [
                    'channel' => $channeledMetric['channel'],
                    'platformId' => $channeledMetric['platformId'],
                    'metric_id' => $channeledMetric['metric_id'],
                    'platformCreatedAt' => $channeledMetric['platformCreatedAt'],
                    'data' => json_encode($channeledMetric['data']),
                ];
                // $logger->info("Queuing channeled metric for insert: channeledMetricKey=$key, metric_id={$channeledMetric['metric_id']}, metricKey={$channeledMetric['metricKey']}, platformId={$channeledMetric['platformId']}");
            } elseif (isset($channeledMetricsToInsert[$key])) {
                // Update data
                $data = json_decode($channeledMetricsToInsert[$key]['data'], true);
                $newData = $channeledMetric['data'];

                $data['impressions'] = max($data['impressions'] ?? 0, $newData['impressions'] ?? 0);
                $data['clicks'] = max($data['clicks'] ?? 0, $newData['clicks'] ?? 0);
                $data['position_weighted'] = max($data['position_weighted'] ?? 0, $newData['position_weighted'] ?? 0);
                $data['ctr'] = max($data['ctr'] ?? 0, $newData['ctr'] ?? 0);

                $channeledMetricsToInsert[$key]['data'] = json_encode($data);
                // $logger->info("Updated queued channeled metric: channeledMetricKey=$key, metric_id={$channeledMetric['metric_id']}");
            } else {
                // Update existing
                $data = json_decode($channeledMetricMap[$key]['data'], true);
                $newData = $channeledMetric['data'];

                $data['impressions'] = max($data['impressions'] ?? 0, $newData['impressions'] ?? 0);
                $data['clicks'] = max($data['clicks'] ?? 0, $newData['clicks'] ?? 0);
                $data['position_weighted'] = max($data['position_weighted'] ?? 0, $newData['position_weighted'] ?? 0);
                $data['ctr'] = max($data['ctr'] ?? 0, $newData['ctr'] ?? 0);

                $channeledMetricsToUpdate[$key] = [
                    'id' => $channeledMetricMap[$key]['id'],
                    'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                ];
                // $logger->info("Queuing channeled metric for update: channeledMetricKey=$key, metric_id={$channeledMetric['metric_id']}");
            }
        }

        // Bulk Insert channeled metrics
        if (!empty($channeledMetricsToInsert)) {
            $insertPlaceholders = implode(', ', array_fill(0, count($channeledMetricsToInsert), '(?, ?, ?, ?, ?)'));
            $insertParams = [];
            foreach ($channeledMetricsToInsert as $row) {
                $insertParams[] = $row['channel'];
                $insertParams[] = $row['platformId'];
                $insertParams[] = $row['metric_id'];
                $insertParams[] = $row['platformCreatedAt'];
                $insertParams[] = $row['data'];
            }
            // $logger->info("Inserting " . count($channeledMetricsToInsert) . " channeled metrics");
            $manager->getConnection()->executeStatement(
                'INSERT INTO channeled_metrics (channel, platformId, metric_id, platformCreatedAt, data)
                    VALUES ' . $insertPlaceholders,
                $insertParams
            );

            // Re-fetch channeled metrics to get their IDs
            $selectParams = [];
            $selectPlaceholders = [];
            foreach ($channeledMetricsToInsert as $m) {
                $selectPlaceholders[] = '(?, ?, ?, ?)';
                $selectParams[] = $m['channel'];
                $selectParams[] = $m['platformId'];
                $selectParams[] = $m['metric_id'];
                $selectParams[] = $m['platformCreatedAt'];
            }
            $sql = "SELECT id, channel, platformId, metric_id, platformCreatedAt, data
                    FROM channeled_metrics
                    WHERE (channel, platformId, metric_id, platformCreatedAt) IN (" . implode(', ', $selectPlaceholders) . ")";
        }

        // Bulk Update channeled metrics
        if (!empty($channeledMetricsToUpdate)) {
            $updateCases = [];
            $updateParams = [];
            $ids = [];
            foreach ($channeledMetricsToUpdate as $update) {
                $id = $update['id'];
                $data = $update['data'];
                $updateCases[] = "WHEN id = ? THEN ?";
                $updateParams[] = $id;
                $updateParams[] = $data;
                $ids[] = $id;
            }
            $caseSql = implode("\n", $updateCases);
            $idPlaceholders = implode(', ', array_fill(0, count($ids), '?'));
            $whereParams = $ids;
            $updateParams = array_merge($updateParams, $whereParams);
            // $logger->info("Updating " . count($channeledMetricsToUpdate) . " channeled metrics");
            $updateSql = "UPDATE channeled_metrics
                      SET data = CASE
                                     $caseSql
                                     ELSE data
                                 END
                      WHERE id IN ($idPlaceholders)";
            $manager->getConnection()->executeStatement($updateSql, $updateParams);
        }

        // Re-fetch channeled metrics
        $channeledMetricMap = MapGenerator::getChanneledMetricMap(
            $manager,
            $sql,
            $selectParams,
            $metricMap,
        );
        // $logger->info("uniqueChanneledMetrics count: " . count($uniqueChanneledMetrics));
        // $logger->info("channeledMetricMap count after re-fetch: " . count($channeledMetricMap));

        $channeledMetricMapFlipped = [];
        foreach ($channeledMetricMap as $originalKey => $value) {
            if (isset($value['id'])) {
                $id = (string)$value['id'];
                $channeledMetricMapFlipped[$id] = [
                    'id' => $originalKey,
                    'data' => $value['data'],
                ];
            }
        }

        return [
            'channeledMetricMap' => $channeledMetricMap,
            'channeledMetricMapReverse' => $channeledMetricMapFlipped,
        ];
    }

    /**
     * Processes channeled metric dimensions from the given metrics.
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @param array $metricMap
     * @param array $channeledMetricMap
     * @param LoggerInterface $logger
     * @throws Exception
     */
    public static function processChanneledMetricDimensions(
        ArrayCollection $metrics,
        EntityManager $manager,
        array $metricMap,
        array $channeledMetricMap,
        LoggerInterface $logger
    ): void {
        // $logger->info("Processing " . count($metrics->toArray()) . " metrics in processChanneledMetricDimensions");
        // Extract dimensions from metrics
        $uniqueDimensions = [];
        foreach ($metrics->toArray() as $metric) {
            $dimensions = $metric->dimensions;
            foreach ($dimensions as $dimension) {
                $metricKey = KeyGenerator::generateMetricKey(
                    channel: $metric->channel,
                    name: $metric->name,
                    period: $metric->period,
                    metricDate: $metric->metricDate instanceof DateTime ? $metric->metricDate->format('Y-m-d') : $metric->metricDate,
                    account: isset($metric->account) ? $metric->account->getAccountId() : null,
                    channeledAccount: isset($metric->channeledAccount) ? $metric->channeledAccount->getPlatformId() : null,
                    campaign: isset($metric->campaign) ? $metric->campaign->getCampaignId() : null,
                    channeledCampaign: isset($metric->channeledCampaign) ? $metric->channeledCampaign->getPlatformId() : null,
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
                );

                if (!isset($metricMap['map'][$metricKey])) {
                    $logger->warning("Skipping dimension: metricKey=$metricKey not found in metricMap");
                    continue;
                }

                // $logger->info("Dimensions Inputs: metricKey=$metricKey, channel={$metric->channel}, platformId=" . ($metric->platformId ?? 'null') . ", metric_id={$metricMap['map'][$metricKey]}, platformCreatedAt=" . ($metric->platformCreatedAt->format('Y-m-d H:i:s')));
                $channeledMetricKey = KeyGenerator::generateChanneledMetricKey(
                    channel: $metric->channel,
                    platformId: $metric->platformId,
                    metric: $metricMap['map'][$metricKey],
                    platformCreatedAt: Carbon::parse($metric->platformCreatedAt)->format('Y-m-d'),
                );

                if (!isset($channeledMetricMap['channeledMetricMap'][$channeledMetricKey])) {
                    $logger->error("ChanneledMetric not found for key=$channeledMetricKey, metricId=".$metricMap['map'][$metricKey]." metricKey=$metricKey, platformId=" . ($metric->platformId ?? 'null') . ", platformCreatedAt=" . Carbon::parse($metric->platformCreatedAt)->format('Y-m-d'));
                    continue;
                }

                $dimensionKey = KeyGenerator::generateChanneledMetricDimensionKey(
                    channeledMetric: $channeledMetricMap['channeledMetricMap'][$channeledMetricKey]['id'],
                    dimensionKey: $dimension->dimensionKey,
                    dimensionValue: $dimension->dimensionValue,
                );

                $uniqueDimensions[$dimensionKey] = [
                    'dimensionKey' => $dimension->dimensionKey,
                    'dimensionValue' => $dimension->dimensionValue,
                    'channeledMetric_id' => $channeledMetricMap['channeledMetricMap'][$channeledMetricKey]['id'],
                ];
            }
        }

        // Batch select dimensions from list
        $selectParams = [];
        $selectPlaceholders = [];
        $nullConditions = [];

        foreach ($uniqueDimensions as $d) {
            $channeledMetricId = $d['channeledMetric_id'];
            $dimensionKey = $d['dimensionKey'];
            $dimensionValue = $d['dimensionValue'];

            if ($dimensionKey === null || $dimensionValue === null) {
                // Use IS NULL-safe fallback condition for NULLs
                $conditions = [];
                $conditions[] = 'channeledMetric_id = ?';
                $selectParams[] = $channeledMetricId;

                $conditions[] = $dimensionKey === null ? 'dimensionKey IS NULL' : 'dimensionKey = ?';
                if ($dimensionKey !== null) $selectParams[] = $dimensionKey;

                $conditions[] = $dimensionValue === null ? 'dimensionValue IS NULL' : 'dimensionValue = ?';
                if ($dimensionValue !== null) $selectParams[] = $dimensionValue;

                $nullConditions[] = '(' . implode(' AND ', $conditions) . ')';
            } else {
                $selectParams[] = $channeledMetricId;
                $selectParams[] = $dimensionKey;
                $selectParams[] = $dimensionValue;
                $selectPlaceholders[] = '(?, ?, ?)';
            }
        }

        $whereParts = [];

        if (!empty($selectPlaceholders)) {
            $whereParts[] = '(channeledMetric_id, dimensionKey, dimensionValue) IN (' . implode(', ', $selectPlaceholders) . ')';
        }

        if (!empty($nullConditions)) {
            $whereParts[] = implode(' OR ', $nullConditions);
        }

        $sql = "SELECT id, dimensionKey, dimensionValue, channeledMetric_id
                FROM channeled_metric_dimensions";

        if (!empty($whereParts)) {
            $sql .= ' WHERE ' . implode(' OR ', $whereParts);
        } else {
            $sql .= ' WHERE 1=0'; // Safe fallback if no dimensions to match
        }

        // Map dimensions to their IDs
        $dimensionMap = MapGenerator::getDimensionMap(
            $manager,
            $sql,
            $selectParams,
        );

        // Get list of dimensions that need to be inserted
        $dimensionsToInsert = [];
        foreach ($uniqueDimensions as $key => $dimension) {
            if (!isset($dimensionMap[$key])) {
                $dimensionsToInsert[] = [
                    'channeledMetric_id' => $dimension['channeledMetric_id'],
                    'dimensionKey' => $dimension['dimensionKey'],
                    'dimensionValue' => $dimension['dimensionValue'],
                ];
            }
        }

        // Bulk Insert dimensions that are not in the database
        if (!empty($dimensionsToInsert)) {
            $insertPlaceholders = implode(', ', array_fill(0, count($dimensionsToInsert), '(?, ?, ?)'));
            $insertParams = [];
            foreach ($dimensionsToInsert as $dimensionToInsert) {
                $insertParams[] = $dimensionToInsert['channeledMetric_id'];
                $insertParams[] = $dimensionToInsert['dimensionKey'];
                $insertParams[] = $dimensionToInsert['dimensionValue']; // null-safe
            }

            $manager->getConnection()->executeStatement(
                "INSERT INTO channeled_metric_dimensions (channeledMetric_id, dimensionKey, dimensionValue)
                    VALUES $insertPlaceholders",
                $insertParams);
        }
    }
}