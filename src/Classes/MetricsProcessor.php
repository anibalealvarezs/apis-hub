<?php

namespace Classes;

use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
use Anibalealvarezs\ApiSkeleton\Enums\Channel;
use Anibalealvarezs\ApiSkeleton\Enums\Period;
use Carbon\Carbon;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;
use RuntimeException;

class MetricsProcessor
{
    use \Traits\CalculatesMetricDeltas;

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processQueries(ArrayCollection $metrics, EntityManager $manager): array
    {
        // Extract queries from metrics
        $queries = array_filter(array_map(function ($metric) {
            $queryValue = (isset($metric->query) || (is_object($metric) && property_exists($metric, 'query'))) ? $metric->query : null;

            return is_object($queryValue) && method_exists($queryValue, 'getQuery') ? $queryValue->getQuery() : $queryValue;
        }, $metrics->toArray()));

        // Remove duplicates
        $uniqueQueries = array_unique($queries);
        if (empty($uniqueQueries)) {
            return ['map' => [], 'mapReverse' => []];
        }

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
        if (! empty($queriesToInsert)) {
            $cols = ['query', 'created_at', 'updated_at'];
            $now = date('Y-m-d H:i:s');
            $insertParams = [];
            foreach ($queriesToInsert as $q) {
                $insertParams[] = $q;
                $insertParams[] = $now;
                $insertParams[] = $now;
            }
            $sql = Helpers::buildInsertIgnoreSql(
                'queries',
                $cols,
                ['query'],
                count($queriesToInsert)
            );
            $manager->getConnection()->executeStatement($sql, $insertParams);
        }

        // Re-fetch ALL unique queries to ensure map is complete
        try {
            $allExistingQueries = $manager->getConnection()
                ->executeQuery($sql = "SELECT id, query FROM queries WHERE query IN ($selectPlaceholders)", $selectParams)
                ->fetchAllAssociative();
        } catch (Exception $e) {
            throw new RuntimeException("Failed to re-fetch queries: " . $e->getMessage(), 0, $e);
        }

        $finalMap = [];
        $mapReverse = [];
        foreach ($allExistingQueries as $query) {
            $finalMap[$query['query']] = $query['id'];
            $mapReverse[$query['id']] = $query['query'];
        }

        return ['map' => $finalMap, 'mapReverse' => $mapReverse];
    }

    public static function processCountries(ArrayCollection $metrics, EntityManager $manager): array
    {
        $repo = $manager->getRepository(\Entities\Analytics\Country::class);
        $countries = $repo->findAll();
        $map = [];
        $mapReverse = [];
        /** @var \Entities\Analytics\Country $country */
        foreach ($countries as $country) {
            $map[$country->getCode()->value] = $country;
            $mapReverse[$country->getId()] = $country;
        }

        return ['map' => $map, 'mapReverse' => $mapReverse];
    }

    public static function processDevices(ArrayCollection $metrics, EntityManager $manager): array
    {
        $repo = $manager->getRepository(\Entities\Analytics\Device::class);
        $devices = $repo->findAll();
        $map = [];
        $mapReverse = [];
        /** @var \Entities\Analytics\Device $device */
        foreach ($devices as $device) {
            $map[$device->getType()->value] = $device;
            $mapReverse[$device->getId()] = $device;
        }

        return ['map' => $map, 'mapReverse' => $mapReverse];
    }

    public static function processDimensionSets(ArrayCollection $metrics, EntityManager $manager): array
    {
        $dimManager = new \Classes\DimensionManager($manager);
        $map = [];
        foreach ($metrics as $metric) {
            if (isset($metric->dimensions) && ! empty($metric->dimensions)) {
                $hash = $metric->dimensionsHash ?? KeyGenerator::generateDimensionsHash((array)$metric->dimensions);
                if (! isset($map[$hash])) {
                    $set = $dimManager->resolveDimensionSet((array)$metric->dimensions);
                    $map[$hash] = $set->getId();
                }
            }
        }

        return ['map' => $map];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processAccounts(ArrayCollection $metrics, EntityManager $manager): array
    {
        $ids = [];
        foreach ($metrics as $metric) {
            if (isset($metric->account)) {
                $ids[] = is_object($metric->account) ? (method_exists($metric->account, 'getId') ? $metric->account->getId() : 0) : (int)$metric->account;
            } elseif (isset($metric->accountPlatformId)) {
                $ids[] = (int)$metric->accountPlatformId;
            }
        }
        $ids = array_unique($ids);
        if (empty($ids)) {
            return ['map' => [], 'mapReverse' => []];
        }

        $results = $manager->getConnection()->executeQuery(
            "SELECT id FROM accounts WHERE id IN (?)",
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER]
        )->fetchAllAssociative();

        $map = [];
        $mapReverse = [];
        foreach ($results as $row) {
            $map[$row['id']] = (int) $row['id'];
            $mapReverse[$row['id']] = $row['id'];
        }

        return ['map' => $map, 'mapReverse' => $mapReverse];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processChanneledAccounts(ArrayCollection $metrics, EntityManager $manager): array
    {
        $ids = [];
        foreach ($metrics as $metric) {
            if (isset($metric->channeledAccount)) {
                $ids[] = is_object($metric->channeledAccount) ? $metric->channeledAccount->getPlatformId() : (string)$metric->channeledAccount;
            } elseif (isset($metric->channeledAccountPlatformId)) {
                $ids[] = $metric->channeledAccountPlatformId;
            }
        }
        $ids = array_unique($ids);
        if (empty($ids)) {
            return ['map' => [], 'mapReverse' => []];
        }

        $results = $manager->getConnection()->executeQuery(
            "SELECT id, platform_id, account_id FROM channeled_accounts WHERE platform_id IN (?)",
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $map = [];
        $mapReverse = [];
        foreach ($results as $row) {
            $map[$row['platform_id']] = (int) $row['id'];
            $map['global'][$row['platform_id']] = (int) ($row['account_id'] ?? 0);
            $mapReverse[$row['id']] = $row['platform_id'];
        }

        return ['map' => $map, 'mapReverse' => $mapReverse];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processCampaigns(ArrayCollection $metrics, EntityManager $manager): array
    {
        $ids = [];
        foreach ($metrics as $metric) {
            if (isset($metric->campaign)) {
                $ids[] = is_object($metric->campaign) ? $metric->campaign->getCampaignId() : (string)$metric->campaign;
            } elseif (isset($metric->campaignPlatformId)) {
                $ids[] = $metric->campaignPlatformId;
            }
        }
        $ids = array_unique($ids);
        if (empty($ids)) {
            return ['map' => [], 'mapReverse' => []];
        }

        $results = $manager->getConnection()->executeQuery(
            "SELECT id, campaign_id FROM campaigns WHERE campaign_id IN (?)",
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $map = [];
        $mapReverse = [];
        foreach ($results as $row) {
            $map[$row['campaign_id']] = (int) $row['id'];
            $mapReverse[$row['id']] = $row['campaign_id'];
        }

        return ['map' => $map, 'mapReverse' => $mapReverse];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processChanneledCampaigns(ArrayCollection $metrics, EntityManager $manager): array
    {
        $ids = [];
        foreach ($metrics as $metric) {
            if (isset($metric->channeledCampaign)) {
                $ids[] = is_object($metric->channeledCampaign) ? $metric->channeledCampaign->getPlatformId() : (string)$metric->channeledCampaign;
            } elseif (isset($metric->channeledCampaignPlatformId)) {
                $ids[] = $metric->channeledCampaignPlatformId;
            }
        }
        $ids = array_unique($ids);
        if (empty($ids)) {
            return ['map' => [], 'mapReverse' => []];
        }

        $results = $manager->getConnection()->executeQuery(
            "SELECT id, platform_id, campaign_id, channeled_account_id FROM channeled_campaigns WHERE platform_id IN (?)",
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $map = [];
        $mapReverse = [];
        foreach ($results as $row) {
            $map[$row['platform_id']] = (int) $row['id'];
            $map['global'][$row['platform_id']] = (int) ($row['campaign_id'] ?? 0);
            $map['account'][$row['platform_id']] = (int) ($row['channeled_account_id'] ?? 0);
            $mapReverse[$row['id']] = $row['platform_id'];
        }

        return ['map' => $map, 'mapReverse' => $mapReverse];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processChanneledAdGroups(ArrayCollection $metrics, EntityManager $manager): array
    {
        $ids = [];
        foreach ($metrics as $metric) {
            if (isset($metric->channeledAdGroup)) {
                $ids[] = is_object($metric->channeledAdGroup) ? $metric->channeledAdGroup->getPlatformId() : (string)$metric->channeledAdGroup;
            } elseif (isset($metric->channeledAdGroupPlatformId)) {
                $ids[] = $metric->channeledAdGroupPlatformId;
            }
        }
        $ids = array_unique($ids);
        if (empty($ids)) {
            return ['map' => [], 'mapReverse' => []];
        }

        $results = $manager->getConnection()->executeQuery(
            "SELECT id, platform_id, campaign_id, channeled_campaign_id, channeled_account_id FROM channeled_ad_groups WHERE platform_id IN (?)",
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $map = [];
        $mapReverse = [];
        foreach ($results as $row) {
            $map[$row['platform_id']] = (int) $row['id'];
            $map['global'][$row['platform_id']] = (int) ($row['campaign_id'] ?? 0);
            $map['campaign'][$row['platform_id']] = (int) ($row['channeled_campaign_id'] ?? 0);
            $map['account'][$row['platform_id']] = (int) ($row['channeled_account_id'] ?? 0);
            $mapReverse[$row['id']] = $row['platform_id'];
        }

        return ['map' => $map, 'mapReverse' => $mapReverse];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processChanneledAds(ArrayCollection $metrics, EntityManager $manager): array
    {
        $ids = [];
        foreach ($metrics as $metric) {
            if (isset($metric->channeledAd)) {
                $ids[] = is_object($metric->channeledAd) ? $metric->channeledAd->getPlatformId() : (string)$metric->channeledAd;
            } elseif (isset($metric->channeledAdPlatformId)) {
                $ids[] = $metric->channeledAdPlatformId;
            }
        }
        $ids = array_unique($ids);
        if (empty($ids)) {
            return ['map' => [], 'mapReverse' => []];
        }

        $results = $manager->getConnection()->executeQuery(
            "SELECT id, platform_id, channeled_ad_group_id, channeled_campaign_id, channeled_account_id FROM channeled_ads WHERE platform_id IN (?)",
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $map = [];
        $mapReverse = [];
        foreach ($results as $row) {
            $map[$row['platform_id']] = (int) $row['id'];
            $map['ad_group'][$row['platform_id']] = (int) ($row['channeled_ad_group_id'] ?? 0);
            $map['campaign'][$row['platform_id']] = (int) ($row['channeled_campaign_id'] ?? 0);
            $map['account'][$row['platform_id']] = (int) ($row['channeled_account_id'] ?? 0);
            $mapReverse[$row['id']] = $row['platform_id'];
        }

        return ['map' => $map, 'mapReverse' => $mapReverse];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processCreatives(ArrayCollection $metrics, EntityManager $manager): array
    {
        $ids = [];
        foreach ($metrics as $metric) {
            if (isset($metric->creative)) {
                $ids[] = is_object($metric->creative) ? $metric->creative->getCreativeId() : (string)$metric->creative;
            } elseif (isset($metric->creativePlatformId)) {
                $ids[] = $metric->creativePlatformId;
            }
        }
        $ids = array_unique($ids);
        if (empty($ids)) {
            return ['map' => [], 'mapReverse' => []];
        }

        $results = $manager->getConnection()->executeQuery(
            "SELECT id, creative_id FROM creatives WHERE creative_id IN (?)",
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $map = [];
        $mapReverse = [];
        foreach ($results as $row) {
            $map[$row['creative_id']] = (int) $row['id'];
            $mapReverse[$row['id']] = $row['creative_id'];
        }

        return ['map' => $map, 'mapReverse' => $mapReverse];
    }

    public static function processPages(ArrayCollection $metrics, EntityManager $manager): array
    {
        $platformIds = [];
        $canonicalIds = [];
        foreach ($metrics as $metric) {
            if (isset($metric->page)) {
                $pId = is_object($metric->page) ? $metric->page->getPlatformId() : (string)$metric->page;
                if ($pId) {
                    if (str_starts_with($pId, 'http') || str_contains($pId, '/')) {
                        $canonicalIds[] = Helpers::getCanonicalPageId($pId, null, 'website');
                    } else {
                        $platformIds[] = $pId;
                    }
                }
            }
        }
        $platformIds = array_unique(array_filter($platformIds));
        $canonicalIds = array_unique(array_filter($canonicalIds));

        if (empty($platformIds) && empty($canonicalIds)) {
            return ['map' => [], 'mapReverse' => []];
        }

        $conditions = [];
        if (! empty($platformIds)) {
            $conditions[] = "platform_id IN (".implode(',', array_fill(0, count($platformIds), '?')).")";
        }
        if (! empty($canonicalIds)) {
            $conditions[] = "canonical_id IN (".implode(',', array_fill(0, count($canonicalIds), '?')).")";
        }

        $sql = "SELECT id, platform_id, canonical_id FROM pages WHERE " . implode(" OR ", $conditions);
        $results = $manager->getConnection()->executeQuery($sql, array_merge($platformIds, $canonicalIds))->fetchAllAssociative();

        $map = [];
        $mapReverse = [];
        foreach ($results as $row) {
            if ($row['platform_id']) {
                $map[$row['platform_id']] = (int) $row['id'];
            }
            if ($row['canonical_id']) {
                $map[$row['canonical_id']] = (int) $row['id'];
            }
            $mapReverse[$row['id']] = $row['canonical_id'] ?? $row['platform_id'];
        }

        return ['map' => $map, 'mapReverse' => $mapReverse];
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    public static function processPosts(ArrayCollection $metrics, EntityManager $manager): array
    {
        $ids = [];
        foreach ($metrics as $metric) {
            if (isset($metric->post)) {
                $ids[] = is_object($metric->post) ? $metric->post->getPostId() : (string)$metric->post;
            } elseif (isset($metric->postPlatformId)) {
                $ids[] = $metric->postPlatformId;
            }
        }
        $ids = array_unique($ids);
        if (empty($ids)) {
            return ['map' => [], 'mapReverse' => []];
        }

        $results = $manager->getConnection()->executeQuery(
            "SELECT id, post_id FROM posts WHERE post_id IN (?)",
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $map = [];
        $mapReverse = [];
        foreach ($results as $row) {
            $map[$row['post_id']] = (int) $row['id'];
            $mapReverse[$row['id']] = $row['post_id'];
        }

        return ['map' => $map, 'mapReverse' => $mapReverse];
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
        bool $processCountries = false,
        bool $processDevices = false,
        bool $processDimensions = false,
        bool $processCreatives = false,
        ?array $countryMap = null,
        ?array $deviceMap = null,
        ?array $dimensionSetMap = null,
        ?array $pageMap = null,
        ?array $postMap = null,
        ?array $accountMap = null,
        ?array $channeledAccountMap = null,
        ?array $campaignMap = null,
        ?array $channeledCampaignMap = null,
        ?array $channeledAdGroupMap = null,
        ?array $channeledAdMap = null,
        ?array $creativeMap = null,
        ?LoggerInterface $logger = null,
        ?string $channel = null,
    ): array {
        $logger = self::resolveLogger($logger, $channel);

        // Inject virtual DAILY metrics from LIFETIME ones (Channel Agnostic Delta Engine)
        self::injectVirtualDailyMetrics($metrics, $manager);

        // Initialize null maps
        $queryMap = null;
        $productMap = null;
        $customerMap = null;
        $orderMap = null;

        // Map queries
        if ($processQueries) {
            $queryMap = self::processQueries($metrics, $manager);
        }

        // Map pages
        if (! $pageMap) {
            $pageMap = self::processPages($metrics, $manager);
        }

        // Map countries
        if ($processCountries && ! $countryMap) {
            $countryMap = self::processCountries($metrics, $manager);
        }

        // Map devices
        if ($processDevices && ! $deviceMap) {
            $deviceMap = self::processDevices($metrics, $manager);
        }

        // Map dimension sets
        if ($processDimensions && ! $dimensionSetMap) {
            $dimensionSetMap = self::processDimensionSets($metrics, $manager);
        }

        // Map accounts
        if ($processAccounts && ! $accountMap) {
            $accountMap = self::processAccounts($metrics, $manager);
        }

        // Map channeled accounts
        if ($processChanneledAccounts && ! $channeledAccountMap) {
            $channeledAccountMap = self::processChanneledAccounts($metrics, $manager);
        }

        // Map campaigns
        if ($processCampaigns && ! $campaignMap) {
            $campaignMap = self::processCampaigns($metrics, $manager);
        }

        // Map channeled campaigns
        if ($processChanneledCampaigns && ! $channeledCampaignMap) {
            $channeledCampaignMap = self::processChanneledCampaigns($metrics, $manager);
        }

        // Map channeled campaigns
        if ($processChanneledAdGroups && ! $channeledAdGroupMap) {
            $channeledAdGroupMap = self::processChanneledAdGroups($metrics, $manager);
        }

        // Map channeled ads
        if ($processChanneledAds && ! $channeledAdMap) {
            $channeledAdMap = self::processChanneledAds($metrics, $manager);
        }

        // Map posts
        if ($processPosts && ! $postMap) {
            $postMap = self::processPosts($metrics, $manager);
        }

        // Map products
        if ($processProducts && ! $productMap) {
            $productMap = self::processProducts($metrics, $manager);
        }

        // Map customers
        if ($processCustomers && ! $customerMap) {
            $customerMap = self::processCustomers($metrics, $manager);
        }

        // Map orders
        if ($processOrders && ! $orderMap) {
            $orderMap = self::processOrders($metrics, $manager);
        }

        // Map creatives
        if ($processCreatives && ! $creativeMap) {
            $creativeMap = self::processCreatives($metrics, $manager);
        }

        // Extract metrics from metrics
        $uniqueMetricConfigs = [];
        foreach ($metrics->toArray() as $metric) {
            if (! $metric) {
                continue;
            }
            $mObj = $metric;
            /** @var object{channel: mixed, name: mixed, period: mixed, account: mixed, channeledAccount: mixed, campaign: mixed, channeledCampaign: mixed, channeledAdGroup: mixed, channeledAd: mixed, page: mixed, query: mixed, post: mixed, product: mixed, customer: mixed, creative: mixed, country: mixed, device: mixed} $mObj */

            $mContext = (is_object($mObj) && method_exists($mObj, 'getContext')) ? $mObj->getContext() : (array)$mObj;

            $rowPostValue = $mContext['post'] ?? $mObj->post ?? $mObj->postPlatformId ?? null;
            if (is_object($rowPostValue)) {
                $rowPostValue = method_exists($rowPostValue, 'getPostId') ? $rowPostValue->getPostId() : (method_exists($rowPostValue, 'getPlatformId') ? $rowPostValue->getPlatformId() : (string)$rowPostValue);
            }

            if (str_contains((string)($mObj->name ?? ''), 'daily')) {
                error_log("[MetricsProcessor] Generating Key for " . ($mObj->name ?? 'unknown') . " | Resolved Post ID: " . ($rowPostValue ?? 'NULL'));
            }

            $metricConfigKey = KeyGenerator::generateMetricConfigKey(
                channel: $mObj->channel ?? null,
                name: $mObj->name ?? null,
                period: $mObj->period ?? null,
                account: ($v = $mContext['account'] ?? $mObj->account ?? $mObj->accountPlatformId ?? null) ? (is_object($v) ? (method_exists($v, 'getName') ? $v->getName() : (string)$v) : (string)$v) : null,
                channeledAccount: ($v = $mContext['channeledAccount'] ?? $mObj->channeledAccount ?? $mObj->channeledAccountPlatformId ?? null) ? (is_object($v) ? (method_exists($v, 'getPlatformId') ? $v->getPlatformId() : (string)$v) : (string)$v) : null,
                campaign: ($v = $mContext['campaign'] ?? $mObj->campaign ?? $mObj->campaignPlatformId ?? null) ? (is_object($v) ? (method_exists($v, 'getCampaignId') ? $v->getCampaignId() : (string)$v) : (string)$v) : null,
                channeledCampaign: ($v = $mContext['channeledCampaign'] ?? $mObj->channeledCampaign ?? $mObj->channeledCampaignPlatformId ?? null) ? (is_object($v) ? (method_exists($v, 'getPlatformId') ? $v->getPlatformId() : (string)$v) : (string)$v) : null,
                channeledAdGroup: ($v = $mContext['channeledAdGroup'] ?? $mObj->channeledAdGroup ?? $mObj->channeledAdGroupPlatformId ?? null) ? (is_object($v) ? (method_exists($v, 'getPlatformId') ? $v->getPlatformId() : (string)$v) : (string)$v) : null,
                channeledAd: ($v = $mContext['channeledAd'] ?? $mObj->channeledAd ?? $mObj->channeledAdPlatformId ?? null) ? (is_object($v) ? (method_exists($v, 'getPlatformId') ? $v->getPlatformId() : (string)$v) : (string)$v) : null,
                page: ($v = $mContext['page'] ?? $mObj->page ?? $mObj->pagePlatformId ?? null) ? (is_object($v) ? (method_exists($v, 'getUrl') ? $v->getUrl() : (string)$v) : (string)$v) : null,
                query: $mContext['query'] ?? $mObj->query ?? null,
                post: $rowPostValue,
                product: ($v = $mContext['product'] ?? $mObj->product ?? $mObj->productPlatformId ?? null) ? (is_object($v) ? (method_exists($v, 'getProductId') ? $v->getProductId() : (string)$v) : (string)$v) : null,
                customer: ($v = $mContext['customer'] ?? $mObj->customer ?? $mObj->customerPlatformId ?? null) ? (is_object($v) ? (method_exists($v, 'getEmail') ? $v->getEmail() : (string)$v) : (string)$v) : null,
                order: ($v = $mContext['order'] ?? $mObj->order ?? $mObj->orderPlatformId ?? null) ? (is_object($v) ? $v->getOrderId() : (string)$v) : (string)$v,
                country: $mObj->countryCode ?? ($mObj->country ?? null),
                device: $mObj->deviceType ?? ($mObj->device ?? null),
                creative: ($v = $mContext['creative'] ?? $mObj->creative ?? $mObj->creativePlatformId ?? null) ? (is_object($v) ? $v->getCreativeId() : (string)$v) : (string)$v,
                dimensionSet: $mObj->dimensionsHash ?? (isset($mObj->dimensions) ? KeyGenerator::generateDimensionsHash((array)$mObj->dimensions) : null)
            );
            $metric->metricConfigKey = $metricConfigKey;

            $channelObj = Channel::tryFromName((string) $metric->channel);
            $channelId = $channelObj ? $channelObj->value : $metric->channel;

            $channeledAccountId = self::resolveChanneledAccountId($metric, $channeledAccountMap);
            $accountId = self::resolveAccountId($metric, $accountMap);
            if (!$accountId && $channeledAccountMap) {
                if ($pId = self::getMetricPlatformId($metric, 'channeledAccount')) {
                    $accountId = $channeledAccountMap['global'][$pId] ?? null;
                }
            }

            $uniqueMetricConfigs[$metricConfigKey] = [
                'channel' => $channelId,
                'name' => $metric->name,
                'period' => $metric->period,
                'account_id' => $accountId,
                'channeled_account_id' => $channeledAccountId,
                'campaign_id' => self::resolveCampaignId($metric, $campaignMap),
                'channeled_campaign_id' => self::resolveChanneledCampaignId($metric, $channeledCampaignMap),
                'channeled_ad_group_id' => self::resolveChanneledAdGroupId($metric, $channeledAdGroupMap),
                'channeled_ad_id' => self::resolveChanneledAdId($metric, $channeledAdMap),
                'query_id' => self::resolveQueryId($metric, $queryMap),
                'page_id' => self::resolvePageId($metric, $pageMap),
                'creative_id' => self::resolveCreativeId($metric, $creativeMap),
                'post_id' => self::resolvePostId($metric, $postMap),
                'product_id' => self::resolveProductId($metric, $productMap),
                'customer_id' => self::resolveCustomerId($metric, $customerMap),
                'order_id' => self::resolveOrderId($metric, $orderMap),
                'country_id' => self::resolveCountryId($metric, $countryMap),
                'device_id' => self::resolveDeviceId($metric, $deviceMap),
                'dimension_set_id' => self::resolveDimensionSetId($metric, $dimensionSetMap),
                'key' => $metricConfigKey,
            ];
        }


        // Batch select metrics from list by signature
        $selectParams = array_column($uniqueMetricConfigs, 'key');
        $metricConfigMap = [];

        if (! empty($selectParams)) {
            foreach (array_chunk($selectParams, 1000) as $chunkParams) {
                $placeholders = implode(', ', array_fill(0, count($chunkParams), '?'));
                $sql = "SELECT id, config_signature FROM metric_configs WHERE config_signature IN ($placeholders)";

                $chunkMap = MapGenerator::getMetricConfigMap(
                    manager: $manager,
                    sql: $sql,
                    params: $chunkParams,
                );

                foreach ($chunkMap as $k => $v) {
                    $metricConfigMap[$k] = $v;
                }
            }
        }

        // INSERT IGNORE / ON CONFLICT: atomic upsert.
        if (! empty($uniqueMetricConfigs)) {
            $cols = [
                'channel', 'name', 'period', 'account_id', 'channeled_account_id',
                'campaign_id', 'channeled_campaign_id', 'channeled_ad_group_id', 'channeled_ad_id',
                'creative_id', 'query_id', 'page_id', 'post_id', 'product_id',
                'customer_id', 'order_id', 'country_id', 'device_id', 'dimension_set_id',
                'config_signature'
            ];
            $numCols = count($cols);
            $chunkLimit = floor(30000 / $numCols);
            $buffer = [];
            $count = 0;

            foreach ($uniqueMetricConfigs as $key => $metricConfig) {
                if (isset($metricConfigMap[$key])) {
                    continue;
                }

                $buffer[] = $metricConfig['channel'];
                $buffer[] = $metricConfig['name'];
                $buffer[] = $metricConfig['period'];
                $buffer[] = $metricConfig['account_id'] ?? null;
                $buffer[] = $metricConfig['channeled_account_id'] ?? null;
                $buffer[] = $metricConfig['campaign_id'] ?? null;
                $buffer[] = $metricConfig['channeled_campaign_id'] ?? null;
                $buffer[] = $metricConfig['channeled_ad_group_id'] ?? null;
                $buffer[] = $metricConfig['channeled_ad_id'] ?? null;
                $buffer[] = $metricConfig['creative_id'] ?? null;
                $buffer[] = $metricConfig['query_id'] ?? null;
                $buffer[] = $metricConfig['page_id'] ?? null;
                $buffer[] = $metricConfig['post_id'] ?? null;
                $buffer[] = $metricConfig['product_id'] ?? null;
                $buffer[] = $metricConfig['customer_id'] ?? null;
                $buffer[] = $metricConfig['order_id'] ?? null;
                $buffer[] = $metricConfig['country_id'] ?? null;
                $buffer[] = $metricConfig['device_id'] ?? null;
                $buffer[] = $metricConfig['dimension_set_id'] ?? null;
                $buffer[] = $key; // config_signature

                $count++;

                if ($count >= $chunkLimit) {
                    $sql = Helpers::buildUpsertSql('metric_configs', $cols, ['dimension_set_id'], ['config_signature'], $count);
                    $affected = $manager->getConnection()->executeStatement($sql, $buffer);
                    $logger->info("[MetricsProcessor] Inserted chunk of $count metric_configs. Affected: $affected rows.");
                    $buffer = [];
                    $count = 0;
                }
            }

            if (! empty($buffer)) {
                $sql = Helpers::buildUpsertSql('metric_configs', $cols, ['dimension_set_id'], ['config_signature'], $count);
                $affected = $manager->getConnection()->executeStatement($sql, $buffer);
                $logger->info("[MetricsProcessor] Inserted final chunk of $count metric_configs. Affected: $affected rows.");
            }
        }

        // Re-fetch all metric_configs
        $reFetchParams = array_column($uniqueMetricConfigs, 'key');
        if (! empty($reFetchParams)) {
            foreach (array_chunk($reFetchParams, 1000) as $chunkParams) {
                $placeholders = implode(', ', array_fill(0, count($chunkParams), '?'));
                $reFetchSql = "SELECT id, config_signature FROM metric_configs WHERE config_signature IN ($placeholders)";
                $allMetricConfigs = $manager->getConnection()->executeQuery($reFetchSql, $chunkParams)->fetchAllAssociative();
                foreach ($allMetricConfigs as $metricConfigRow) {
                    $metricConfigMap[$metricConfigRow['config_signature']] = (int)$metricConfigRow['id'];
                }
            }
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
        ArrayCollection|array $metrics,
        EntityManager $manager,
        array $metricConfigMap,
        ?LoggerInterface $logger = null,
        ?string $channel = null,
    ): array {
        $logger = self::resolveLogger($logger, $channel);

        $config = Helpers::getProjectConfig();
        $cacheRawMetrics = filter_var($config['analytics']['cache_raw_metrics'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $uniqueMetrics = [];
        foreach ($metrics->toArray() as $metric) {
            if (! $metric) {
                continue;
            }
            $mObj = is_object($metric) ? $metric : (object)$metric;
            $mDimensions = $mObj->dimensions ?? [];
            $dimensions = array_map(function ($dimension) {
                $d = (array) $dimension;
                return [ 'dimensionKey' => $d['dimensionKey'] ?? null, 'dimensionValue' => $d['dimensionValue'] ?? null ];
            }, (array) $mDimensions);

            $dimensionsHash = KeyGenerator::generateDimensionsHash($dimensions);
            $metricKey = KeyGenerator::generateMetricKey(
                dimensionsHash: $dimensionsHash,
                metricConfigKey: $mObj->metricConfigKey ?? '',
                metricDate: $mObj->metricDate ?? '',
            );

            if (! isset($metricConfigMap['map'][$metric->metricConfigKey])) {
                $logger->warning("Missing metric_config_id for key: " . $metric->metricConfigKey . " (Metric: " . $metric->name . ")");

                continue;
            }

            KeyGenerator::sortDimensions($dimensions);
            $uniqueMetrics[$metricKey] = [
                'value' => $metric->value,
                'metadata' => $metric->metadata,
                'dimensions_hash' => $dimensionsHash,
                'metric_config_id' => $metricConfigMap['map'][$metric->metricConfigKey],
                'metric_date' => $metric->metricDate instanceof DateTime ? $metric->metricDate->format('Y-m-d') : $metric->metricDate,
            ];
        }

        // Select existing
        $metricMap = [];
        foreach (array_chunk($uniqueMetrics, 1000, true) as $chunkMetrics) {
            $selectParams = [];
            $tuples = [];
            foreach ($chunkMetrics as $m) {
                if (isset($m['dimensions_hash']) && isset($m['metric_config_id']) && isset($m['metric_date'])) {
                    $selectParams[] = $m['dimensions_hash'];
                    $selectParams[] = $m['metric_config_id'];
                    $selectParams[] = $m['metric_date'];
                    $tuples[] = '(?, ?, ?)';
                }
            }
            if (! empty($tuples)) {
                $placeholders = implode(', ', $tuples);
                $sql = "SELECT id, dimensions_hash, metric_config_id, metric_date 
                        FROM metrics 
                        WHERE (dimensions_hash, metric_config_id, metric_date) IN ($placeholders)";

                $chunkMap = MapGenerator::getMetricMap($manager, $sql, $selectParams, $metricConfigMap);
                foreach ($chunkMap as $k => $v) {
                    $metricMap[$k] = $v;
                }
            }
        }
        $logger->info("Metrics mapping complete: " . count($metricMap) . " existing global metrics found in DB.");

        $metricsToInsert = [];
        $metricsToUpdate = [];
        foreach ($uniqueMetrics as $key => $metric) {
            if (! isset($metricMap[$key])) {
                $metricsToInsert[] = [
                    'value' => $metric['value'],
                    'metadata' => $cacheRawMetrics ? json_encode($metric['metadata'] ?? []) : null,
                    'dimensions_hash' => $metric['dimensions_hash'],
                    'metric_config_id' => $metric['metric_config_id'],
                    'metric_date' => $metric['metric_date'],
                    'key' => $key,
                ];
            } else {
                $metricsToUpdate[] = [
                    'id' => $metricMap[$key],
                    'value' => $metric['value'],
                ];
            }
        }

        $logger->info("Metrics analysis: " . count($metricsToInsert) . " new metrics to insert, " . count($metricsToUpdate) . " existing metrics to update.");

        if (! empty($metricsToInsert)) {
            $cols = ['value', 'metadata', 'dimensions_hash', 'metric_config_id', 'metric_date'];
            $numCols = count($cols);
            $chunkSize = floor(30000 / $numCols); // Safe buffer under 65535, aiming for ~30k params

            foreach (array_chunk($metricsToInsert, (int)$chunkSize) as $chunk) {
                $insertParams = [];
                foreach ($chunk as $row) {
                    $insertParams[] = $row['value'];
                    $insertParams[] = $row['metadata'];
                    $insertParams[] = $row['dimensions_hash'];
                    $insertParams[] = $row['metric_config_id'];
                    $insertParams[] = $row['metric_date'];
                }

                $sql = Helpers::buildInsertIgnoreSql(
                    'metrics',
                    $cols,
                    ['metric_config_id', 'dimensions_hash', 'metric_date'],
                    count($chunk)
                );
                $affected = $manager->getConnection()->executeStatement($sql, $insertParams);
                $logger->info("Inserted metrics chunk: $affected rows affected.");
            }

            foreach (array_chunk($metricsToInsert, 1000) as $chunk) {
                $reFetchParams = [];
                $tuples = [];
                $isPostgres = Helpers::isPostgres();
                foreach ($chunk as $row) {
                    $reFetchParams[] = $row['dimensions_hash'];
                    $reFetchParams[] = (int)$row['metric_config_id'];
                    $reFetchParams[] = $row['metric_date'];
                    $tuples[] = $isPostgres ? '(?::text, ?::integer, ?::date)' : '(?, ?, ?)';
                }
                $placeholders = implode(', ', $tuples);
                $isPostgres = Helpers::isPostgres();
                $reFetchSql = "SELECT id, dimensions_hash, metric_config_id, metric_date FROM metrics WHERE " .
                    ($isPostgres ? "(dimensions_hash::text, metric_config_id::integer, metric_date::date) " : "(dimensions_hash, metric_config_id, metric_date) ") .
                    "IN (" . ($isPostgres ? "VALUES " : "") . "$placeholders)";
                $fetched = $manager->getConnection()->executeQuery($reFetchSql, $reFetchParams)->fetchAllAssociative();
                foreach ($fetched as $metricRow) {
                    $metricKey = KeyGenerator::generateMetricKey(
                        dimensionsHash: $metricRow['dimensions_hash'],
                        metricConfigKey: $metricConfigMap['mapReverse'][$metricRow['metric_config_id']],
                        metricDate: $metricRow['metric_date'],
                    );
                    $metricMap[$metricKey] = (int)$metricRow['id'];
                }
            }
        }

        if (! empty($metricsToUpdate)) {
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
        $config = Helpers::getProjectConfig();
        $cacheRawMetrics = filter_var($config['analytics']['cache_raw_metrics'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $metricsMapByMKey = [];
        foreach ($metrics as $m) {
            if (!$m) continue;
            $mObj = (object)$m;
            /** @var object{dimensions_hash: ?string, dimensionsHash: ?string, metric_config_key: ?string, metricConfigKey: ?string, metric_date: ?string, metricDate: ?string} $mObj */
            $mKey = KeyGenerator::generateMetricKey(
                dimensionsHash: $mObj->dimensions_hash ?? ($mObj->dimensionsHash ?? ''),
                metricConfigKey: $mObj->metric_config_key ?? ($mObj->metricConfigKey ?? ''),
                metricDate: $mObj->metric_date ?? ($mObj->metricDate ?? '')
            );
            $metricsMapByMKey[$mKey] = $m;
        }

        $uniqueChanneledMetrics = [];
        foreach ($metrics->toArray() as $metric) {
            if (!$metric) continue;
            $mObj = (object)$metric;
            /** @var object{dimensions_hash: ?string, dimensionsHash: ?string, metric_config_key: ?string, metricConfigKey: ?string, metric_date: ?string, metricDate: ?string, platform_id: ?string, platformId: ?string, channel: mixed} $mObj */
            $metricKey = KeyGenerator::generateMetricKey(
                dimensionsHash: $mObj->dimensions_hash ?? ($mObj->dimensionsHash ?? ''),
                metricConfigKey: $mObj->metric_config_key ?? ($mObj->metricConfigKey ?? ''),
                metricDate: $mObj->metric_date ?? ($mObj->metricDate ?? ''),
            );

            if (! isset($metricMap['map'][$metricKey])) {
                continue;
            }
            if (empty($mObj->platform_id) && empty($mObj->platformId)) {
                continue;
            }

            $channeledMetricKey = KeyGenerator::generateChanneledMetricKey(
                channel: $mObj->channel ?? '',
                platformId: $mObj->platform_id ?? ($mObj->platformId ?? ''),
                metric: $metricMap['map'][$metricKey],
                platformCreatedAt: Carbon::parse($metric->platform_created_at ?? $metric->platformCreatedAt)->format('Y-m-d'),
            );

            $channelObj = Channel::tryFromName((string) $metric->channel);
            $channelId = $channelObj ? $channelObj->value : $metric->channel;

            $uniqueChanneledMetrics[$channeledMetricKey] = [
                'channel' => $channelId,
                'platform_id' => $metric->platform_id ?? $metric->platformId,
                'metric_id' => $metricMap['map'][$metricKey],
                'platform_created_at' => Carbon::parse($metric->platform_created_at ?? $metric->platformCreatedAt)->format('Y-m-d'),
                'data' => $metric->data ?? [],
                'metricKey' => $metricKey,
            ];
        }

        $channeledMetricMap = [];
        foreach (array_chunk($uniqueChanneledMetrics, 1000, true) as $chunk) {
            $selectParams = [];
            $tuples = [];
            $isPostgres = Helpers::isPostgres();
            foreach ($chunk as $m) {
                $mChannelObj = Channel::tryFromName((string) $m['channel']);
                $mChannelId = $mChannelObj ? $mChannelObj->value : (int)$m['channel'];
                $selectParams[] = $mChannelId;
                $selectParams[] = (string)$m['platform_id'];
                $selectParams[] = (int)$m['metric_id'];
                $selectParams[] = (string)$m['platform_created_at'];
                $tuples[] = $isPostgres ? '(?::integer, ?::text, ?::integer, ?::text)' : '(?, ?, ?, ?)';
            }
            if (! empty($tuples)) {
                $placeholders = implode(', ', $tuples);
                $isPostgres = Helpers::isPostgres();
                $sql = "SELECT id, channel, platform_id, metric_id, platform_created_at, data
                        FROM channeled_metrics
                        WHERE " . ($isPostgres ? "(channel::integer, platform_id::text, metric_id::integer, platform_created_at::text)" : "(channel, platform_id, metric_id, platform_created_at)") . " IN (" . ($isPostgres ? "VALUES " : "") . "$placeholders)";

                $chunkMap = MapGenerator::getChanneledMetricMap($manager, $sql, $selectParams, $metricMap);
                foreach ($chunkMap as $k => $v) {
                    $channeledMetricMap[$k] = $v;
                }
            }
        }
        $logger->info("Channeled mapping complete: " . count($channeledMetricMap) . " existing channeled metrics found in DB.");

        $dimManager = new \Classes\DimensionManager($manager);
        $channeledMetricsToInsert = [];
        $channeledMetricsToUpdate = [];
        foreach ($uniqueChanneledMetrics as $key => $channeledMetric) {
            if (! isset($channeledMetricMap[$key]) && ! isset($channeledMetricsToInsert[$key])) {
                $originalMetric = $metricsMapByMKey[$channeledMetric['metricKey']] ?? null;

                $dimensionSetId = null;
                if ($originalMetric && isset($originalMetric->dimensions) && ! empty($originalMetric->dimensions)) {
                    $dimensionSetId = $dimManager->resolveDimensionSet((array)$originalMetric->dimensions)->getId();
                }

                $channeledMetricsToInsert[$key] = [
                    'channel' => $channeledMetric['channel'],
                    'platform_id' => $channeledMetric['platform_id'],
                    'metric_id' => $channeledMetric['metric_id'],
                    'platform_created_at' => $channeledMetric['platform_created_at'],
                    'data' => $cacheRawMetrics ? json_encode($channeledMetric['data']) : null,
                    'dimension_set_id' => $dimensionSetId,
                ];
            } elseif (isset($channeledMetricsToInsert[$key])) {
                if ($cacheRawMetrics) {
                    $data = json_decode($channeledMetricsToInsert[$key]['data'], true) ?? [];
                    $data = array_merge($data, $channeledMetric['data'] ?? []);
                    $channeledMetricsToInsert[$key]['data'] = json_encode($data);
                }
            } else {
                if ($cacheRawMetrics) {
                    $data = json_decode($channeledMetricMap[$key]['data'], true) ?? [];
                    $data = array_merge($data, $channeledMetric['data'] ?? []);
                    $channeledMetricsToUpdate[$key] = [
                        'id' => $channeledMetricMap[$key]['id'],
                        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                    ];
                }
            }
        }

        $logger->info("Channeled metrics analysis: " . count($channeledMetricsToInsert) . " new, " . count($channeledMetricsToUpdate) . " to update.");

        if (! empty($channeledMetricsToInsert)) {
            $cols = ['channel', 'platform_id', 'metric_id', 'platform_created_at', 'data', 'dimension_set_id'];
            $numCols = count($cols);
            $chunkSize = floor(30000 / $numCols); // Safe buffer under 65535, aiming for ~30k params

            foreach (array_chunk($channeledMetricsToInsert, (int)$chunkSize) as $chunk) {
                $params = [];
                foreach ($chunk as $row) {
                    $params[] = $row['channel'];
                    $params[] = $row['platform_id'];
                    $params[] = $row['metric_id'];
                    $params[] = $row['platform_created_at'];
                    $params[] = $row['data'];
                    $params[] = $row['dimension_set_id'];
                }

                $sql = Helpers::buildInsertIgnoreSql(
                    'channeled_metrics',
                    $cols,
                    ['platform_id', 'channel', 'metric_id', 'platform_created_at'],
                    count($chunk)
                );
                $affected = $manager->getConnection()->executeStatement($sql, $params);
                $logger->info("Inserted channeled metrics chunk: $affected rows affected.");
            }
        }

        if (! empty($channeledMetricsToUpdate)) {
            foreach ($channeledMetricsToUpdate as $update) {
                $manager->getConnection()->executeStatement(
                    "UPDATE channeled_metrics SET data = ? WHERE id = ?",
                    [$update['data'], $update['id']]
                );
            }
        }

        $channeledMetricMap = [];
        foreach (array_chunk($uniqueChanneledMetrics, 1000, true) as $chunk) {
            $selectParams = [];
            $tuples = [];
            foreach ($chunk as $m) {
                $selectParams[] = (int)$m['channel'];
                $selectParams[] = (string)$m['platform_id'];
                $selectParams[] = (int)$m['metric_id'];
                $selectParams[] = (string)$m['platform_created_at'];
                $tuples[] = Helpers::isPostgres() ? '(?::integer, ?::text, ?::integer, ?::text)' : '(?, ?, ?, ?)';
            }
            if (! empty($tuples)) {
                $placeholders = implode(', ', $tuples);
                $isPostgres = Helpers::isPostgres();
                $sql = "SELECT id, channel, platform_id, metric_id, platform_created_at, data
                        FROM channeled_metrics
                        WHERE " . ($isPostgres ? "(channel::integer, platform_id::text, metric_id::integer, platform_created_at::text)" : "(channel, platform_id, metric_id, platform_created_at)") . " IN (" . ($isPostgres ? "VALUES " : "") . "$placeholders)";

                $chunkMap = MapGenerator::getChanneledMetricMap($manager, $sql, $selectParams, $metricMap);
                foreach ($chunkMap as $k => $v) {
                    $channeledMetricMap[$k] = $v;
                }
            }
        }
        $flipped = [];
        foreach ($channeledMetricMap as $k => $v) {
            if (isset($v['id'])) {
                $flipped[(string)$v['id']] = ['id' => $k, 'data' => $v['data']];
            }
        }

        return ['map' => $channeledMetricMap, 'mapReverse' => $flipped];
    }

    private static function getMetricPlatformId(object $metric, string $property): ?string
    {
        $mContext = (method_exists($metric, 'getContext')) ? $metric->getContext() : (array)$metric;
        $platformProp = $property . 'PlatformId';
        
        $val = $mContext[$property] ?? ($mContext[$platformProp] ?? ($metric->$property ?? ($metric->$platformProp ?? null)));
        
        $resolvedId = null;
        if ($val) {
            if (is_object($val)) {
                $methods = ['getPostId', 'getPlatformId', 'getCampaignId', 'getCreativeId', 'getProductId', 'getOrderId', 'getUrl', 'getEmail', 'getId'];
                foreach ($methods as $method) {
                    if (method_exists($val, $method)) {
                        $resolvedId = (string) $val->$method();
                        break;
                    }
                }
                if (!$resolvedId) {
                    $resolvedId = (string) $val;
                }
            } else {
                $resolvedId = (string) $val;
            }
        }

        if (str_contains((string)($metric->name ?? ''), 'daily') && $property === 'post') {
            error_log("[MetricsProcessor] Mapping Post for " . ($metric->name ?? 'unknown') . " | Raw: " . (is_object($val) ? get_class($val) : gettype($val)) . " | Resolved Platform ID: " . ($resolvedId ?? 'NULL'));
        }

        return $resolvedId ?: null;
    }

    private static function resolveChanneledAccountId(object $metric, ?array $channeledAccountMap): ?int
    {
        if (!$channeledAccountMap) {
            return null;
        }
        $pId = self::getMetricPlatformId($metric, 'channeledAccount');
        return $pId ? ($channeledAccountMap['map'][$pId] ?? null) : null;
    }

    private static function resolvePageId(object $metric, ?array $pageMap): ?int
    {
        if (!$pageMap) {
            return null;
        }
        $pId = self::getMetricPlatformId($metric, 'page');
        if (!$pId) {
            return null;
        }
        // Try direct platform ID mapping
        if (isset($pageMap['map'][$pId])) {
            return $pageMap['map'][$pId];
        }
        // Try canonical ID mapping as fallback
        if (str_starts_with($pId, 'http') || str_contains($pId, '/')) {
            $canonicalId = Helpers::getCanonicalPageId($pId, null, 'website');
            return $pageMap['map'][$canonicalId] ?? null;
        }
        return null;
    }

    private static function resolveAccountId(object $metric, ?array $map): ?int
    {
        if (!$map) {
            return null;
        }
        $pId = self::getMetricPlatformId($metric, 'account');
        return $pId ? ($map['map'][$pId] ?? null) : null;
    }

    private static function resolveCampaignId(object $metric, ?array $map): ?int
    {
        if (!$map) return null;
        $pId = self::getMetricPlatformId($metric, 'campaign');
        return $pId ? ($map['map'][$pId] ?? null) : null;
    }

    private static function resolveChanneledCampaignId(object $metric, ?array $map): ?int
    {
        if (!$map) return null;
        $pId = self::getMetricPlatformId($metric, 'channeledCampaign');
        return $pId ? ($map['map'][$pId] ?? null) : null;
    }

    private static function resolveChanneledAdGroupId(object $metric, ?array $map): ?int
    {
        if (!$map) return null;
        $pId = self::getMetricPlatformId($metric, 'channeledAdGroup');
        return $pId ? ($map['map'][$pId] ?? null) : null;
    }

    private static function resolveChanneledAdId(object $metric, ?array $map): ?int
    {
        if (!$map) return null;
        $pId = self::getMetricPlatformId($metric, 'channeledAd');
        return $pId ? ($map['map'][$pId] ?? null) : null;
    }

    private static function resolveQueryId(object $metric, ?array $map): ?int
    {
        if (!$map) return null;
        $pId = self::getMetricPlatformId($metric, 'query');
        return $pId ? ($map['map'][$pId] ?? null) : null;
    }

    private static function resolveCreativeId(object $metric, ?array $map): ?int
    {
        if (!$map) return null;
        $pId = self::getMetricPlatformId($metric, 'creative');
        return $pId ? ($map['map'][$pId] ?? null) : null;
    }

    private static function resolvePostId(object $metric, ?array $map): ?int
    {
        if (!$map) return null;
        $pId = self::getMetricPlatformId($metric, 'post');
        return $pId ? ($map['map'][$pId] ?? null) : null;
    }

    private static function resolveProductId(object $metric, ?array $map): ?int
    {
        if (!$map) return null;
        $pId = self::getMetricPlatformId($metric, 'product');
        return $pId ? ($map['map'][$pId] ?? null) : null;
    }

    private static function resolveCustomerId(object $metric, ?array $map): ?int
    {
        if (!$map) return null;
        $pId = self::getMetricPlatformId($metric, 'customer');
        return $pId ? ($map['map'][$pId] ?? null) : null;
    }

    private static function resolveOrderId(object $metric, ?array $map): ?int
    {
        if (!$map) return null;
        $pId = self::getMetricPlatformId($metric, 'order');
        return $pId ? ($map['map'][$pId] ?? null) : null;
    }

    private static function resolveCountryId(object $metric, ?array $map): ?int
    {
        if (!$map) return null;
        return isset($metric->countryCode) ? ($map['map'][$metric->countryCode]?->getId() ?? null) : (isset($metric->country) ? $metric->country?->getId() : null);
    }

    private static function resolveDeviceId(object $metric, ?array $map): ?int
    {
        if (!$map) return null;
        return isset($metric->deviceType) ? ($map['map'][$metric->deviceType]?->getId() ?? null) : (isset($metric->device) ? $metric->device?->getId() : null);
    }

    private static function resolveDimensionSetId(object $metric, ?array $map): ?int
    {
        if (!$map) return null;
        $hash = $metric->dimensionsHash ?? KeyGenerator::generateDimensionsHash((array)($metric->dimensions ?? []));
        return $map['map'][$hash] ?? ($map[$hash] ?? null);
    }

    private static function resolveLogger(?LoggerInterface $logger = null, ?string $channel = null): LoggerInterface
    {
        if ($logger) return $logger;
        return Helpers::setLogger(($channel ?? 'metrics-processor') . '.log');
    }
}
