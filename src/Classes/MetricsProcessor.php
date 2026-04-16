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
            "SELECT id, platform_id FROM channeled_accounts WHERE platform_id IN (?)",
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $map = [];
        $mapReverse = [];
        foreach ($results as $row) {
            $map[$row['platform_id']] = (int) $row['id'];
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
            "SELECT id, platform_id FROM channeled_campaigns WHERE platform_id IN (?)",
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $map = [];
        $mapReverse = [];
        foreach ($results as $row) {
            $map[$row['platform_id']] = (int) $row['id'];
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
            "SELECT id, platform_id FROM channeled_ad_groups WHERE platform_id IN (?)",
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $map = [];
        $mapReverse = [];
        foreach ($results as $row) {
            $map[$row['platform_id']] = (int) $row['id'];
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
            "SELECT id, platform_id FROM channeled_ads WHERE platform_id IN (?)",
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $map = [];
        $mapReverse = [];
        foreach ($results as $row) {
            $map[$row['platform_id']] = (int) $row['id'];
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
            $mObj = (object)$metric;
            /** @var object{channel: mixed, name: mixed, period: mixed, account: mixed, channeledAccount: mixed, campaign: mixed, channeledCampaign: mixed, channeledAdGroup: mixed, channeledAd: mixed, page: mixed, query: mixed, post: mixed, product: mixed, customer: mixed, creative: mixed, country: mixed, device: mixed} $mObj */

            $metricConfigKey = KeyGenerator::generateMetricConfigKey(
                channel: $mObj->channel ?? null,
                name: $mObj->name ?? null,
                period: $mObj->period ?? null,
                account: isset($mObj->account) ? (is_object($mObj->account) ? (method_exists($mObj->account, 'getName') ? $mObj->account->getName() : (string)$mObj->account) : (string)$mObj->account) : null,
                channeledAccount: isset($mObj->channeledAccount) ? (is_object($mObj->channeledAccount) ? (method_exists($mObj->channeledAccount, 'getPlatformId') ? $mObj->channeledAccount->getPlatformId() : (string)$mObj->channeledAccount) : (string)$mObj->channeledAccount) : ($mObj->channeledAccountPlatformId ?? null),
                campaign: isset($mObj->campaign) ? (is_object($mObj->campaign) ? (method_exists($mObj->campaign, 'getCampaignId') ? $mObj->campaign->getCampaignId() : (string)$mObj->campaign) : (string)$mObj->campaign) : null,
                channeledCampaign: isset($mObj->channeledCampaign) ? (is_object($mObj->channeledCampaign) ? (method_exists($mObj->channeledCampaign, 'getPlatformId') ? $mObj->channeledCampaign->getPlatformId() : (string)$mObj->channeledCampaign) : (string)$mObj->channeledCampaign) : ($mObj->channeledCampaignPlatformId ?? null),
                channeledAdGroup: isset($mObj->channeledAdGroup) ? (is_object($mObj->channeledAdGroup) ? (method_exists($mObj->channeledAdGroup, 'getPlatformId') ? $mObj->channeledAdGroup->getPlatformId() : (string)$mObj->channeledAdGroup) : (string)$mObj->channeledAdGroup) : ($mObj->channeledAdGroupPlatformId ?? null),
                channeledAd: isset($mObj->channeledAd) ? (is_object($mObj->channeledAd) ? (method_exists($mObj->channeledAd, 'getPlatformId') ? $mObj->channeledAd->getPlatformId() : (string)$mObj->channeledAd) : (string)$mObj->channeledAd) : ($mObj->channeledAdPlatformId ?? null),
                page: isset($mObj->page) ? (is_object($mObj->page) ? (method_exists($mObj->page, 'getUrl') ? $mObj->page->getUrl() : (string)$mObj->page) : (string)$mObj->page) : null,
                query: $mObj->query ?? null,
                post: isset($mObj->post) ? (is_object($mObj->post) ? (method_exists($mObj->post, 'getPostId') ? $mObj->post->getPostId() : (string)$mObj->post) : (string)$mObj->post) : null,
                product: isset($mObj->product) ? (is_object($mObj->product) ? (method_exists($mObj->product, 'getProductId') ? $mObj->product->getProductId() : (string)$mObj->product) : (string)$mObj->product) : null,
                customer: isset($mObj->customer) ? (is_object($mObj->customer) ? (method_exists($mObj->customer, 'getEmail') ? $mObj->customer->getEmail() : (string)$mObj->customer) : (string)$mObj->customer) : null,
                order: isset($metric->order) ? (is_object($metric->order) ? $metric->order->getOrderId() : (string)$metric->order) : null,
                country: $metric->countryCode ?? null,
                device: $metric->deviceType ?? null,
                creative: isset($metric->creative) ? (is_object($metric->creative) ? $metric->creative->getCreativeId() : (string)$metric->creative) : ($metric->creativePlatformId ?? null),
                dimensionSet: $metric->dimensionsHash ?? (isset($metric->dimensions) ? KeyGenerator::generateDimensionsHash((array)$metric->dimensions) : null)
            );
            $metric->metricConfigKey = $metricConfigKey;

            $channelObj = Channel::tryFromName((string) $metric->channel);
            $channelId = $channelObj ? $channelObj->value : $metric->channel;

            $uniqueMetricConfigs[$metricConfigKey] = [
                'channel' => $channelId,
                'name' => $metric->name,
                'period' => $metric->period,
                'account_id' => isset($metric->account) ? ($accountMap['map'][is_object($metric->account) ? (method_exists($metric->account, 'getId') ? $metric->account->getId() : 0) : (int)$metric->account] ?? null) : (isset($metric->accountPlatformId) ? ($accountMap['map'][(int)$metric->accountPlatformId] ?? null) : null),
                'channeled_account_id' => self::resolveChanneledAccountId($metric, $channeledAccountMap),
                'campaign_id' => isset($metric->campaign) ? ($campaignMap['map'][is_object($metric->campaign) ? $metric->campaign->getCampaignId() : (string)$metric->campaign] ?? null) : (isset($metric->campaignPlatformId) ? ($campaignMap['map'][$metric->campaignPlatformId] ?? null) : null),
                'channeled_campaign_id' => isset($metric->channeledCampaign) ? ($channeledCampaignMap['map'][is_object($metric->channeledCampaign) ? $metric->channeledCampaign->getPlatformId() : (string)$metric->channeledCampaign] ?? null) : (isset($metric->channeledCampaignPlatformId) ? ($channeledCampaignMap['map'][$metric->channeledCampaignPlatformId] ?? null) : null),
                'channeled_ad_group_id' => isset($metric->channeledAdGroup) ? ($channeledAdGroupMap['map'][is_object($metric->channeledAdGroup) ? $metric->channeledAdGroup->getPlatformId() : (string)$metric->channeledAdGroup] ?? null) : (isset($metric->channeledAdGroupPlatformId) ? ($channeledAdGroupMap['map'][$metric->channeledAdGroupPlatformId] ?? null) : null),
                'channeled_ad_id' => isset($metric->channeledAd) ? ($channeledAdMap['map'][is_object($metric->channeledAd) ? $metric->channeledAd->getPlatformId() : (string)$metric->channeledAd] ?? null) : (isset($metric->channeledAdPlatformId) ? ($channeledAdMap['map'][$metric->channeledAdPlatformId] ?? null) : null),
                'query_id' => isset($metric->query) ? ($queryMap['map'][$metric->query] ?? null) : null,
                'page_id' => self::resolvePageId($metric, $pageMap),
                'post_id' => isset($metric->post) ? ($postMap['map'][is_object($metric->post) ? $metric->post->getPostId() : (string)$metric->post] ?? null) : (isset($metric->postPlatformId) ? ($postMap['map'][$metric->postPlatformId] ?? null) : null),
                'product_id' => isset($metric->product) ? ($productMap['map'][is_object($metric->product) ? $metric->product->getProductId() : (string)$metric->product] ?? null) : (isset($metric->productPlatformId) ? ($productMap['map'][$metric->productPlatformId] ?? null) : null),
                'customer_id' => isset($metric->customer) ? ($customerMap['map'][is_object($metric->customer) ? $metric->customer->getEmail() : (string)$metric->customer] ?? null) : (isset($metric->customerPlatformId) ? ($customerMap['map'][$metric->customerPlatformId] ?? null) : null),
                'order_id' => isset($metric->order) ? ($orderMap['map'][is_object($metric->order) ? $metric->order->getOrderId() : (string)$metric->order] ?? null) : (isset($metric->orderPlatformId) ? ($orderMap['map'][$metric->orderPlatformId] ?? null) : null),
                'country_id' => isset($metric->countryCode) ? ($countryMap['map'][$metric->countryCode]?->getId() ?? null) : (isset($metric->country) ? $metric->country?->getId() : null),
                'device_id' => isset($metric->deviceType) ? ($deviceMap['map'][$metric->deviceType]?->getId() ?? null) : (isset($metric->device) ? $metric->device?->getId() : null),
                'creative_id' => isset($metric->creative) ? ($creativeMap['map'][is_object($metric->creative) ? $metric->creative->getCreativeId() : (string)$metric->creative] ?? null) : (isset($metric->creativePlatformId) ? ($creativeMap['map'][$metric->creativePlatformId] ?? null) : null),
                'dimension_set_id' => $dimensionSetMap['map'][$metric->dimensionsHash ?? KeyGenerator::generateDimensionsHash((array)$metric->dimensions)] ?? ($dimensionSetMap[$metric->dimensionsHash ?? KeyGenerator::generateDimensionsHash((array)$metric->dimensions)] ?? null),
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

        // Get the list of metrics that need to be inserted
        $metricConfigsToInsert = [];
        foreach ($uniqueMetricConfigs as $key => $metricConfig) {
            if (! isset($metricConfigMap[$key])) {
                $metricConfigsToInsert[] = [
                    'channel' => $metricConfig['channel'],
                    'name' => $metricConfig['name'],
                    'period' => $metricConfig['period'],
                    'account_id' => $metricConfig['account_id'] ?? null,
                    'channeled_account_id' => $metricConfig['channeled_account_id'] ?? null,
                    'campaign_id' => $metricConfig['campaign_id'] ?? null,
                    'channeled_campaign_id' => $metricConfig['channeled_campaign_id'] ?? null,
                    'channeled_ad_group_id' => $metricConfig['channeled_ad_group_id'] ?? null,
                    'channeled_ad_id' => $metricConfig['channeled_ad_id'] ?? null,
                    'query_id' => $metricConfig['query_id'] ?? null,
                    'page_id' => $metricConfig['page_id'] ?? null,
                    'post_id' => $metricConfig['post_id'] ?? null,
                    'product_id' => $metricConfig['product_id'] ?? null,
                    'customer_id' => $metricConfig['customer_id'] ?? null,
                    'order_id' => $metricConfig['order_id'] ?? null,
                    'country_id' => $metricConfig['country_id'] ?? null,
                    'device_id' => $metricConfig['device_id'] ?? null,
                    'dimension_set_id' => $metricConfig['dimension_set_id'] ?? null,
                    'key' => $key,
                ];
            }
        }

        // INSERT IGNORE: atomic upsert.
        if (! empty($uniqueMetricConfigs)) {
            $cols = ['channel', 'name', 'period', 'account_id', 'channeled_account_id', 'campaign_id', 'channeled_campaign_id', 'channeled_ad_group_id', 'channeled_ad_id', 'creative_id', 'query_id', 'page_id', 'post_id', 'product_id', 'customer_id', 'order_id', 'country_id', 'device_id', 'dimension_set_id', 'config_signature'];
            $numCols = count($cols);
            $chunkSize = floor(30000 / $numCols); // Ultra safe margin for Postgres (30k params max per chunk)

            foreach (array_chunk($metricConfigsToInsert, (int)$chunkSize) as $chunk) {
                $insertParams = [];
                foreach ($chunk as $metricConfig) {
                    $insertParams[] = $metricConfig['channel'];
                    $insertParams[] = $metricConfig['name'];
                    $insertParams[] = $metricConfig['period'];
                    $insertParams[] = $metricConfig['account_id'] ?? null;
                    $insertParams[] = $metricConfig['channeled_account_id'] ?? null;
                    $insertParams[] = $metricConfig['campaign_id'] ?? null;
                    $insertParams[] = $metricConfig['channeled_campaign_id'] ?? null;
                    $insertParams[] = $metricConfig['channeled_ad_group_id'] ?? null;
                    $insertParams[] = $metricConfig['channeled_ad_id'] ?? null;
                    $insertParams[] = $metricConfig['creative_id'] ?? null;
                    $insertParams[] = $metricConfig['query_id'] ?? null;
                    $insertParams[] = $metricConfig['page_id'] ?? null;
                    $insertParams[] = $metricConfig['post_id'] ?? null;
                    $insertParams[] = $metricConfig['product_id'] ?? null;
                    $insertParams[] = $metricConfig['customer_id'] ?? null;
                    $insertParams[] = $metricConfig['order_id'] ?? null;
                    $insertParams[] = $metricConfig['country_id'] ?? null;
                    $insertParams[] = $metricConfig['device_id'] ?? null;
                    $insertParams[] = $metricConfig['dimension_set_id'] ?? null;
                    $insertParams[] = $metricConfig['key']; // config_signature
                }
                $sql = Helpers::buildUpsertSql(
                    'metric_configs',
                    $cols,
                    ['dimension_set_id'],
                    ['config_signature'],
                    count($chunk)
                );
                $affected = $manager->getConnection()->executeStatement($sql, $insertParams);
                $logger->info("[MetricsProcessor] Inserted " . count($chunk) . " metric_configs (Ignore on duplicate). Affected: $affected rows.");
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
            $dimensions = array_map(function ($dimension) {
                return [ 'dimensionKey' => $dimension['dimensionKey'], 'dimensionValue' => $dimension['dimensionValue'] ];
            }, $metric->dimensions);
            $dimensionsHash = KeyGenerator::generateDimensionsHash($dimensions);
            $metricKey = KeyGenerator::generateMetricKey(
                dimensionsHash: $dimensionsHash,
                metricConfigKey: $metric->metricConfigKey,
                metricDate: $metric->metricDate,
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

    private static function resolveChanneledAccountId(object $metric, array $channeledAccountMap): ?int
    {
        if (! isset($metric->channeledAccount) && ! isset($metric->channeledAccountPlatformId)) {
            return null;
        }

        $pId = isset($metric->channeledAccount)
            ? (is_object($metric->channeledAccount) ? $metric->channeledAccount->getPlatformId() : (string)$metric->channeledAccount)
            : $metric->channeledAccountPlatformId;

        return $channeledAccountMap['map'][$pId] ?? null;
    }

    private static function resolvePageId(object $metric, array $pageMap): ?int
    {
        if (! isset($metric->page)) {
            return null;
        }

        $pId = isset($metric->page)
            ? (is_object($metric->page) ? $metric->page->getPlatformId() : (string)$metric->page)
            : ($metric->pagePlatformId ?? null);

        if (! $pId) {
            return null;
        }

        // Try direct platform ID mapping
        if (isset($pageMap['map'][$pId])) {
            return $pageMap['map'][$pId];
        }

        // Try canonical ID mapping
        if (str_starts_with($pId, 'http') || str_contains($pId, '/')) {
            $canonicalId = Helpers::getCanonicalPageId($pId, null, 'website');

            return $pageMap['map'][$canonicalId] ?? null;
        }

        return null;
    }

    private static function resolveLogger(?LoggerInterface $logger = null, ?string $channel = null): LoggerInterface
    {
        if ($logger) {
            return $logger;
        }
        if ($channel) {
            return Helpers::setLogger($channel . '.log');
        }

        return Helpers::setLogger('metrics-processor.log');
    }
}
