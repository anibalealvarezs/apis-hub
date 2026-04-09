<?php

declare(strict_types=1);

namespace Classes\Requests;

use Anibalealvarezs\FacebookGraphApi\Enums\MediaType;
use Anibalealvarezs\FacebookGraphApi\Enums\MetricBreakdown;
use Anibalealvarezs\FacebookGraphApi\Enums\MetricSet;
use Anibalealvarezs\FacebookGraphApi\FacebookGraphApi;
use Core\Conversions\UniversalMetricConverter;
use Core\Conversions\UniversalEntityConverter;
use Carbon\Carbon;
use Anibalealvarezs\FacebookGraphApi\Conversions\FacebookMarketingMetricConvert;
use Anibalealvarezs\FacebookGraphApi\Conversions\FacebookOrganicMetricConvert;
use Anibalealvarezs\GoogleApi\Conversions\GoogleSearchConsoleConvert;
use Anibalealvarezs\KlaviyoApi\Conversions\KlaviyoConvert;
use Anibalealvarezs\ShopifyApi\Conversions\ShopifyConvert;
use Anibalealvarezs\NetSuiteApi\Conversions\NetSuiteConvert;
use Classes\MetricsProcessor;
use Symfony\Component\HttpFoundation\Response;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Entities\Analytics\Account;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Channeled\ChanneledMetric;
use Entities\Analytics\Metric;
use Entities\Analytics\Page;
use Entities\Analytics\Post;
use Entities\Analytics\Query;
use Enums\Channel;
use Enums\Period;
use Exception;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;

class MetricRequests
{
    /**
     * @return array
     */
    public static function supportedChannels(): array
    {
        return [
            'shopify',
            'klaviyo',
            'amazon',
            'bigcommerce',
            'netsuite',
            'facebook_organic',
            'facebook_marketing',
            'pinterest',
            'linkedin',
            'x',
            'tiktok',
            'google_search_console',
            'google_analytics',
        ];
    }

    /**
     * @param LoggerInterface $logger
     * @return array
     * @throws Exception
     */
    public static function validateGoogleConfig(LoggerInterface $logger): array
    {
        return \Helpers\GoogleSearchConsoleHelpers::validateGoogleConfig($logger);
    }

    /**
     * @param LoggerInterface|null $logger
     * @param string|null $channel
     * @return array
     */
    public static function validateFacebookConfig(?LoggerInterface $logger = null, ?string $channel = null): array
    {
        return \Classes\Clients\FacebookClient::getConfig($logger, $channel);
    }

    /**
     * @param array $config
     * @param LoggerInterface $logger
     * @return FacebookGraphApi
     * @throws Exception
     */
    public static function initializeFacebookGraphApi(array $config, LoggerInterface $logger): FacebookGraphApi
    {
        return \Classes\Clients\FacebookClient::getInstance($logger, $config);
    }

    /**
     * @param ?string $createdAtMin
     * @param ?string $createdAtMax
     * @param ?object $filters
     * @param string|bool $resume
     * @param ?int $jobId
     * @return Response
     * @throws Exception
     */
    public static function getListFromKlaviyo(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        return (new \Core\Services\SyncService())->execute('klaviyo', $createdAtMin, $createdAtMax, [
            'jobId' => $jobId,
            'resume' => $resume,
        ]);
    }

    public static function getListFromShopify(
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        return (new \Core\Services\SyncService())->execute('shopify', $filters->createdAtMin ?? null, $filters->createdAtMax ?? null, [
            'jobId' => $jobId,
            'resume' => $resume,
        ]);
    }

    public static function getListFromFacebookOrganic(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        return (new \Core\Services\SyncService())->execute('facebook_organic', $createdAtMin, $createdAtMax, [
            'jobId' => $jobId,
            'resume' => $resume,
        ]);
    }

    public static function getListFromFacebookMarketing(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        return (new \Core\Services\SyncService())->execute('facebook_marketing', $createdAtMin, $createdAtMax, [
            'jobId' => $jobId,
            'resume' => $resume,
        ]);
    }

    public static function getListFromBigCommerce(?object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return (new \Core\Services\SyncService())->execute('bigcommerce', $filters->createdAtMin ?? null, $filters->createdAtMax ?? null, [
            'jobId' => $jobId,
            'resume' => $resume,
        ]);
    }

    public static function getListFromNetSuite(?object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return (new \Core\Services\SyncService())->execute('netsuite', $filters->createdAtMin ?? null, $filters->createdAtMax ?? null, [
            'jobId' => $jobId,
            'resume' => $resume,
        ]);
    }

    public static function getListFromAmazon(?object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return (new \Core\Services\SyncService())->execute('amazon', $filters->createdAtMin ?? null, $filters->createdAtMax ?? null, [
            'jobId' => $jobId,
            'resume' => $resume,
        ]);
    }

    public static function getListFromPinterest(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        return (new \Core\Services\SyncService())->execute('pinterest', $createdAtMin, $createdAtMax, [
            'jobId' => $jobId,
            'resume' => $resume,
        ]);
    }

    public static function getListFromLinkedIn(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        return (new \Core\Services\SyncService())->execute('linkedin', $createdAtMin, $createdAtMax, [
            'jobId' => $jobId,
            'resume' => $resume,
        ]);
    }

    public static function getListFromX(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        return (new \Core\Services\SyncService())->execute('x', $createdAtMin, $createdAtMax, [
            'jobId' => $jobId,
            'resume' => $resume,
        ]);
    }

    public static function getListFromTikTok(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        return (new \Core\Services\SyncService())->execute('tiktok', $createdAtMin, $createdAtMax, [
            'jobId' => $jobId,
            'resume' => $resume,
        ]);
    }

    public static function getListFromGoogleSearchConsole(?string $startDate = null, ?string $endDate = null, array $config = []): Response
    {
        return (new \Core\Services\SyncService())->execute('google_search_console', $startDate, $endDate, $config);
    }

    // --- Modular Processor Bridges ---

    public static function processGSCSite(array $data, array $site, string $dayStr, array $config): array
    {
        $logger = Helpers::setLogger('google-search-console.log');
        $manager = Helpers::getManager();
        $pageEntity = $manager->getRepository(Page::class)->getByUrl(rtrim($site['url'], '/'));

        if (!$pageEntity) {
            $logger->error("Page entity not found for site: " . $site['url']);
            return ['metrics' => 0, 'rows' => 0, 'duplicates' => 0];
        }

        $collection = GoogleSearchConsoleConvert::metrics($data, $site['url'], $site['url'], $logger, $pageEntity);
        self::process($collection, $logger);

        return ['metrics' => $collection->count(), 'rows' => count($data), 'duplicates' => 0];
    }

    public static function processFacebookOrganicChunk(array $data, string $startDate, string $endDate, bool $resume, LoggerInterface $logger, ?int $jobId, array $page, array $config): array
    {
        $manager = Helpers::getManager();
        $pageEntity = $manager->getRepository(Page::class)->findOneBy(['platformId' => (string)$page['id']]);

        if (!$pageEntity) {
            $logger->error("Page entity not found for FB platformId=" . $page['id']);
            return ['metrics' => 0, 'rows' => 0, 'duplicates' => 0];
        }

        $collection = FacebookOrganicMetricConvert::pageMetrics(
            rows: $data['insights'] ?? [],
            pagePlatformId: (string)$pageEntity->getPlatformId(),
            logger: $logger,
            page: $pageEntity
        );
        self::process($collection, $logger);

        return ['metrics' => $collection->count(), 'rows' => count($data['insights'] ?? []), 'duplicates' => 0];
    }

    public static function processFacebookMarketingChunk(array $data, string $startDate, string $endDate, bool $resume, LoggerInterface $logger, ?int $jobId, array $adAccount, array $config): array
    {
        $manager = Helpers::getManager();
        $account = $manager->getRepository(Account::class)->findOneBy(['name' => $config['accounts_group_name'] ?? 'Default']);

        $collection = FacebookMarketingMetricConvert::adAccountMetrics(
            rows: $data['data'] ?? [],
            logger: $logger,
            account: $account,
            channeledAccountPlatformId: (string)$adAccount['id'],
            period: Period::Daily,
            metricSet: MetricSet::KEY
        );
        self::process($collection, $logger);

        return ['metrics' => $collection->count(), 'rows' => count($data['data'] ?? []), 'duplicates' => 0];
    }

    public static function processInstagramAccount(
        array $page,
        array $data,
        EntityManager $manager,
        Account $account,
        Page $pageEntity,
        LoggerInterface $logger,
        array $pageMap,
        string $startDate,
        string $endDate,
        array $config
    ): array {
        $channeledAccount = $manager->getRepository(ChanneledAccount::class)->findOneBy(['platformId' => $page['ig_account']]);

        $collection = FacebookOrganicMetricConvert::igAccountMetrics(
            rows: $data['data'] ?? [],
            date: $startDate,
            page: $pageEntity,
            account: $account,
            channeledAccount: $channeledAccount,
            logger: $logger
        );
        self::process($collection, $logger);

        return ['metrics' => $collection->count(), 'rows' => count($data['data'] ?? []), 'duplicates' => 0];
    }

    public static function processKlaviyoChunk(array $data, string $type, array $config, ?string $metricId = null, array $metricMap = []): array
    {
        $logger = Helpers::setLogger('klaviyo.log');
        $collection = match ($type) {
            'metrics' => KlaviyoConvert::metricAggregates($data, (string)$metricId, $metricMap),
            'customers' => KlaviyoConvert::customers($data),
            'products' => KlaviyoConvert::products($data),
            default => new ArrayCollection(),
        };
        if ($collection->count() > 0) {
            self::process($collection, $logger);
        }

        return ['metrics' => $collection->count(), 'rows' => count($data)];
    }

    public static function processShopifyChunk(array $data, string $type, array $config): array
    {
        $logger = Helpers::setLogger('shopify.log');
        $collection = match ($type) {
            'orders' => ShopifyConvert::orders($data),
            'customers' => ShopifyConvert::customers($data),
            'products' => ShopifyConvert::products($data),
            default => new ArrayCollection(),
        };
        if (($type === 'metrics' || $type === 'aggregates') && $collection->count() > 0) {
            self::process($collection, $logger);
        }

        return ['metrics' => $collection->count(), 'rows' => count($data)];
    }

    public static function processNetSuiteChunk(array $data, string $type, array $config): array
    {
        $logger = Helpers::setLogger('netsuite.log');
        $collection = match ($type) {
            'orders' => NetSuiteConvert::orders($data),
            'customers' => NetSuiteConvert::customers($data),
            'items' => NetSuiteConvert::products($data),
            default => new ArrayCollection(),
        };
        if (($type === 'metrics' || $type === 'aggregates') && $collection->count() > 0) {
            self::process($collection, $logger);
        }

        return ['metrics' => $collection->count(), 'rows' => count($data)];
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private static function processCampaignsBulk(
        FacebookGraphApi $api,
        EntityManager $manager,
        ChanneledAccount $channeledAccount,
        LoggerInterface $logger,
        ?string $startDate = null,
        ?string $endDate = null,
        array $channeledCampaignMap = [],
        array $campaignMap = [],
        ?Period $period = null,
        $cacheInclude = null,
        $cacheExclude = null
    ): array {
        $data = $api->getCampaignInsightsFromAdAccount(
            adAccountId: (string)$channeledAccount->getPlatformId(),
            additionalParams: ['date_start' => $startDate, 'date_stop' => $endDate]
        );

        $collection = FacebookMarketingMetricConvert::adAccountMetrics(
            rows: $data['data'] ?? [],
            logger: $logger,
            account: $channeledAccount->getAccount(),
            channeledAccountPlatformId: (string)$channeledAccount->getPlatformId(),
            period: $period ?? Period::Daily
        );

        if ($cacheInclude || $cacheExclude) {
            $items = $collection->toArray();
            $filtered = array_filter($items, function ($m) use ($cacheInclude, $cacheExclude) {
                return Helpers::matchesFilter((string)$m->name, $cacheInclude, $cacheExclude);
            });
            $collection = new ArrayCollection($filtered);
        }

        self::process($collection, $logger);

        return ['metrics' => $collection->count(), 'rows' => count($data['data'] ?? []), 'duplicates' => 0];
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private static function processAdsetsBulk(
        FacebookGraphApi $api,
        EntityManager $manager,
        ChanneledAccount $channeledAccount,
        LoggerInterface $logger,
        ?string $startDate = null,
        ?string $endDate = null,
        array $campaignMap = [],
        array $channeledCampaignMap = [],
        array $channeledAdGroupMap = [],
        ?Period $period = null,
        $cacheInclude = null,
        $cacheExclude = null
    ): array {
        $data = $api->getAdsetInsightsFromAdAccount(
            adAccountId: (string)$channeledAccount->getPlatformId(),
            additionalParams: ['date_start' => $startDate, 'date_stop' => $endDate]
        );

        $collection = FacebookMarketingMetricConvert::adAccountMetrics(
            rows: $data['data'] ?? [],
            logger: $logger,
            account: $channeledAccount->getAccount(),
            channeledAccountPlatformId: (string)$channeledAccount->getPlatformId(),
            period: $period ?? Period::Daily
        );

        self::process($collection, $logger);

        return ['metrics' => $collection->count(), 'rows' => count($data['data'] ?? []), 'duplicates' => 0];
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private static function processAdsBulk(
        FacebookGraphApi $api,
        EntityManager $manager,
        ChanneledAccount $channeledAccount,
        LoggerInterface $logger,
        ?string $startDate = null,
        ?string $endDate = null,
        array $campaignMap = [],
        array $channeledCampaignMap = [],
        array $channeledAdGroupMap = [],
        array $channeledAdMap = [],
        ?Period $period = null,
        $cacheInclude = null,
        $cacheExclude = null
    ): array {
        $data = $api->getAdInsightsFromAdAccount(
            adAccountId: (string)$channeledAccount->getPlatformId(),
            additionalParams: ['date_start' => $startDate, 'date_stop' => $endDate]
        );

        $collection = FacebookMarketingMetricConvert::adAccountMetrics(
            rows: $data['data'] ?? [],
            logger: $logger,
            account: $channeledAccount->getAccount(),
            channeledAccountPlatformId: (string)$channeledAccount->getPlatformId(),
            period: $period ?? Period::Daily
        );

        self::process($collection, $logger);

        return ['metrics' => $collection->count(), 'rows' => count($data['data'] ?? []), 'duplicates' => 0];
    }

    public static function getFacebookMarketingMetricsFields(array $config, string $type): array
    {
        return [
            'metricSet' => MetricSet::BASIC,
            'breakdowns' => [MetricBreakdown::AGE, MetricBreakdown::GENDER],
            'fields' => 'account_id,account_name,campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,impressions,clicks,spend,actions,action_values',
            'metrics' => []
        ];
    }

    // --- Universal Persistence Logic (Delegated to MetricsProcessor) ---

    public static function process(ArrayCollection $channeledCollection, ?LoggerInterface $logger = null): Response
    {
        if (!$logger) {
            $logger = Helpers::setLogger('metrics-processor.log');
        }

        if ($channeledCollection->isEmpty()) {
            return new Response(json_encode(['No metrics to process']));
        }

        $manager = Helpers::getManager();

        try {
            // MetricsProcessor handles transactions internally if needed, but we keep it here for safety
            $manager->getConnection()->beginTransaction();

            // 1. Process Configurations (Ensures metric_configs exist)
            $metricConfigMap = MetricsProcessor::processMetricConfigs(
                metrics: $channeledCollection,
                manager: $manager,
                processQueries: true,
                processAccounts: true,
                processChanneledAccounts: true,
                processDimensions: true
            );

            // 2. Process Global Metrics (The high-level aggregated data)
            $metricMap = MetricsProcessor::processMetrics(
                metrics: $channeledCollection,
                manager: $manager,
                metricConfigMap: $metricConfigMap
            );

            // 3. Process Channeled Metrics (The per-platform raw data)
            $channeledMetricMap = MetricsProcessor::processChanneledMetrics(
                metrics: $channeledCollection,
                manager: $manager,
                metricMap: $metricMap,
                logger: $logger
            );

            $manager->getConnection()->commit();

            return new Response(json_encode([
                'status' => 'success', 
                'processed' => $channeledCollection->count()
            ]));

        } catch (Exception $e) {
            if ($manager->getConnection()->isTransactionActive()) {
                $manager->getConnection()->rollback();
            }
            $logger->error("Error in MetricRequests::process delegation: " . $e->getMessage());
            throw $e;
        }
    }

    private static function initializeSearchConsoleApi(array $loadedConfig, LoggerInterface $logger): \Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi
    {
        $googleConfig = $loadedConfig['google'] ?? [];
        $scConfig = $loadedConfig['google_search_console'] ?? [];

        return new \Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi(
            redirectUrl: $scConfig['redirect_uri'] ?? ($googleConfig['redirect_uri'] ?? ''),
            clientId: ($scConfig['client_id'] ?? '') ?: ($googleConfig['client_id'] ?? ''),
            clientSecret: ($scConfig['client_secret'] ?? '') ?: ($googleConfig['client_secret'] ?? ''),
            refreshToken: ($scConfig['token'] ?? '') ?: ($googleConfig['refresh_token'] ?? ''),
            userId: ($scConfig['user_id'] ?? '') ?: ($googleConfig['user_id'] ?? 'default'),
            scopes: $scConfig['scope'] ?? ($googleConfig['scopes'] ?? []),
            token: $scConfig['token'] ?? '',
            tokenPath: $scConfig['token_path'] ?? ($googleConfig['token_path'] ?? '')
        );
    }

    public static function getFacebookFilter(array $config, string $entityKey = '', string $filterType = 'cache_include'): ?string
    {
        return $config[$entityKey][$filterType] ?? null;
    }

    public static function getRetentionRange(array $config, string $channel, string $default): Carbon
    {
        $days = $config['retention_days'] ?? 30;
        return Carbon::now()->subDays((int)$days);
    }

    protected static function determineDateRange(string $channel, ?string $start, ?string $end): array
    {
        return [
            'start' => $start ?: Carbon::now()->subDays(30)->format('Y-m-d'),
            'end' => $end ?: Carbon::now()->format('Y-m-d'),
        ];
    }
}
