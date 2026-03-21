<?php

declare(strict_types=1);

namespace Classes\Requests;

use Anibalealvarezs\FacebookGraphApi\Enums\MediaType;
use Anibalealvarezs\FacebookGraphApi\Enums\MetricBreakdown;
use Anibalealvarezs\FacebookGraphApi\Enums\MetricSet;
use Anibalealvarezs\FacebookGraphApi\FacebookGraphApi;
use Classes\Overrides\FacebookGraphApiOverride;
use Anibalealvarezs\GoogleApi\Services\SearchConsole\Enums\Dimension;
use Anibalealvarezs\GoogleApi\Services\SearchConsole\Enums\GroupType;
use Anibalealvarezs\GoogleApi\Services\SearchConsole\Enums\Operator;
use Anibalealvarezs\GoogleApi\Google\Exceptions\GoogleQuotaExceededException;
use Anibalealvarezs\FacebookGraphApi\Exceptions\FacebookRateLimitException;
use Anibalealvarezs\KlaviyoApi\Enums\AggregatedMeasurement;
use Carbon\Carbon;
use Classes\Conversions\FacebookMarketingMetricConvert;
use Classes\Conversions\FacebookOrganicMetricConvert;
use Classes\Conversions\GoogleSearchConsoleConvert;
use Classes\Conversions\KlaviyoConvert;
use Classes\MetricsProcessor;
use Classes\Overrides\GoogleApi\SearchConsoleApi\SearchConsoleApi;
use Classes\Overrides\KlaviyoApi\KlaviyoApi;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\Persistence\Mapping\MappingException;
use Entities\Analytics\Account;
use Entities\Analytics\Campaign;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Channeled\ChanneledAd;
use Entities\Analytics\Channeled\ChanneledAdGroup;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Entities\Analytics\Creative;
use Entities\Analytics\Channeled\ChanneledMetric;
use Entities\Analytics\Channeled\ChanneledSyncError;
use Entities\Analytics\Country;
use Entities\Analytics\Device;
use Entities\Analytics\Metric;
use Entities\Analytics\Page;
use Entities\Analytics\Post;
use Entities\Analytics\Query;
use Enums\Channel;
use Enums\Country as CountryEnum;
use Enums\Device as DeviceEnum;
use Enums\Period;
use Enums\Account as AccountEnum;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\GoogleSearchConsoleHelpers;
use Helpers\Helpers;
use Repositories\Channeled\ChanneledMetricRepository;
use Repositories\QueryRepository;
use Repositories\MetricRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;
use Exception;

class MetricRequests
{
    /**
     * @return \Enums\Channel[]
     */
    public static function supportedChannels(): array
    {
        return [
            Channel::shopify,
            Channel::klaviyo,
            Channel::facebook_marketing,
            Channel::facebook_organic,
            Channel::bigcommerce,
            Channel::netsuite,
            Channel::amazon,
            Channel::instagram,
            Channel::google_analytics,
            Channel::google_search_console,
            Channel::pinterest,
            Channel::linkedin,
            Channel::x,
        ];
    }

    /**
     * Fetches metrics from Klaviyo with deduplication and caching.
     *
     * @param string|null $createdAtMin
     * @param string|null $createdAtMax
     * @param array|null $fields
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     * @throws GuzzleException
     * @throws NotSupported
     */
    public static function getListFromKlaviyo(
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?array $fields = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?int $jobId = null
    ): Response {
        $config = Helpers::getChannelsConfig()['klaviyo'];
        $klaviyoClient = new KlaviyoApi(
            apiKey: $config['klaviyo_api_key'],
        );

        $metricNames = $filters->metricNames ?? ($config['metrics'] ?? []);
        $metricIds = [];
        $metricMap = [];
        $klaviyoClient->getAllMetricsAndProcess(
            metricFields: ['id', 'name'],
            callback: function ($metrics) use (&$metricIds, &$metricMap, $metricNames, $jobId) {
                Helpers::checkJobStatus($jobId);
                foreach ($metrics as $metric) {
                    if (empty($metricNames) || in_array($metric['attributes']['name'], $metricNames)) {
                        $metricIds[] = $metric['id'];
                        $metricMap[$metric['id']] = $metric['attributes']['name'];
                    }
                }
            }
        );

        $manager = Helpers::getManager();
        /** @var \Repositories\Channeled\ChanneledMetricRepository $channeledMetricRepository */
        $channeledMetricRepository = $manager->getRepository(ChanneledMetric::class);
        $lastChanneledMetric = $channeledMetricRepository->getLastByPlatformCreatedAt(Channel::klaviyo->value);

        $origin = Carbon::parse("2000-01-01");
        $min = $createdAtMin ? Carbon::parse($createdAtMin) : (isset($lastChanneledMetric['platformCreatedAt']) && filter_var($resume, FILTER_VALIDATE_BOOLEAN) ? Carbon::parse($lastChanneledMetric['platformCreatedAt']) : null);
        $max = $createdAtMax ? Carbon::parse($createdAtMax) : null;
        $now = Carbon::now();
        $from = $min && $min->lt($now) && $min->lt($max) && $origin->lte($min) ?
            $min->format('Y-m-d H:i:s') :
            $origin->format("Y-m-d H:i:s");
        $to = $max && $max->lte($now) ?
            $max->format('Y-m-d H:i:s') :
            $now->format('Y-m-d H:i:s');
        $formattedFilters = [];
        if ($filters) {
            foreach ($filters as $key => $value) {
                if ($key !== 'metricNames') {
                    $formattedFilters[] = [
                        "operator" => 'equals',
                        "field" => $key,
                        "value" => $value,
                    ];
                }
            }
        }
        $formattedFilters[] = [
            "operator" => "greater-than",
            "field" => "datetime",
            "value" => $from,
        ];
        $formattedFilters[] = [
            "operator" => "less-than",
            "field" => "datetime",
            "value" => $to,
        ];

        foreach ($metricIds as $metricId) {
            $klaviyoClient->getAllMetricAggregatesAndProcess(
                metricId: $metricId,
                returnFields: $fields,
                measurements: [AggregatedMeasurement::count],
                filter: $formattedFilters,
                sortField: 'datetime',
                callback: function ($aggregates) use ($metricId, $metricMap, $jobId) {
                    Helpers::checkJobStatus($jobId);
                    self::process(KlaviyoConvert::metricAggregates($aggregates, $metricId, $metricMap));
                }
            );
        }

        return new Response(json_encode(['Metrics retrieved']));
    }

    /**
     * Fetches metrics from Shopify with deduplication and caching.
     *
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromShopify(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        /* Placeholder for ShopifyApi integration */
        return new Response(json_encode([]));
    }

    /**
     * @param string|null $startDate
     * @param string|null $endDate
     * @param string|bool $resume
     * @param LoggerInterface|null $logger
     * @return Response
     * @throws NotSupported
     * @throws ORMException
     * @throws \Doctrine\DBAL\Exception
     * @throws Exception
     */
    public static function getListFromFacebookOrganic(
        ?string $startDate = null,
        ?string $endDate = null,
        string|bool $resume = false,
        ?LoggerInterface $logger = null,
        ?int $jobId = null
    ): Response {
        if (!$logger) {
            $logger = Helpers::setLogger('facebook-organic.log');
        }

        // Apply default dates if missing for "recent" safety
        if (empty($startDate)) {
            $startDate = Carbon::today()->subDays(3)->format('Y-m-d');
            $logger->info("No startDate provided, defaulting to $startDate");
        }
        if (empty($endDate)) {
            $endDate = Carbon::today()->format('Y-m-d');
            $logger->info("No endDate provided, defaulting to $endDate");
        }

        $logger->info("Starting getListFromFacebookOrganic: startDate=$startDate, endDate=$endDate, resume=$resume");
        $manager = Helpers::getManager();
        try {
            // Validate configuration
            $config = self::validateFacebookConfig($logger);

            // Initialize API client
            $api = self::initializeFacebookGraphApi($config, $logger);

            // Initialize repositories
            $pageRepository = $manager->getRepository(Page::class);
            $postRepository = $manager->getRepository(Post::class);
            $accountRepository = $manager->getRepository(Account::class);
            $channeledAccountRepository = $manager->getRepository(ChanneledAccount::class);

            // Load global entities
            $accountEntityName = $config['accounts_group_name'] ?? null;
            $accountEntity = $accountEntityName ? $accountRepository->findOneBy(['name' => $accountEntityName]) : null;

            if (!$accountEntity) {
                $logger->warning("Account group '{$accountEntityName}' not found. Instagram accounts might not be processed correctly.");
            }

            // Apply retention range limit to startDate
            $retentionLimit = self::getRetentionRange($config, 'facebook_organic', '2 years');
            $requestedStart = Carbon::parse($startDate);
            if ($requestedStart->lt($retentionLimit)) {
                $logger->info("Truncating startDate from $startDate to " . $retentionLimit->format('Y-m-d') . " due to cache_history_range");
                $startDate = $retentionLimit->format('Y-m-d');
            }

            if (Carbon::parse($startDate)->gt(Carbon::parse($endDate))) {
                $logger->info("Job date range ($startDate to $endDate) is entirely before the retention limit (" . $retentionLimit->format('Y-m-d') . "). Skipping.");
                return new Response(json_encode(['message' => 'Job skipped due to retention policy']), 200);
            }

            // Process Pages
            Helpers::reconnectIfNeeded($manager);
            $pagesToProcessRaw = $config['pages'] ?? [];
            $globalExcludeIds = array_map('strval', $config['exclude_from_caching'] ?? []);
            $globalPageConfig = $config['PAGE'] ?? [
                'page_metrics' => false,
                'posts' => true,
                'post_metrics' => false,
                'ig_accounts' => true,
                'ig_account_metrics' => false,
                'ig_account_media' => true,
                'ig_account_media_metrics' => false,
            ];

            $pagesToProcess = [];
            if (empty($pagesToProcessRaw)) {
                $logger->info("No specific pages listed in config. Fetching all available pages from database.");
                $allPages = $pageRepository->findByDataAttribute('source', 'fb_page');
                foreach ($allPages as $p) {
                    $pageId = (string) $p->getPlatformId();
                    if (in_array($pageId, $globalExcludeIds)) {
                        continue;
                    }
                    $pageName = $p->getTitle();
                    $includeFilter = self::getFacebookFilter($config, 'PAGE', 'cache_include');
                    $excludeFilter = self::getFacebookFilter($config, 'PAGE', 'cache_exclude');
                    if (!Helpers::matchesFilter((string)$pageName, $includeFilter, $excludeFilter) && !Helpers::matchesFilter((string)$pageId, $includeFilter, $excludeFilter)) {
                        continue;
                    }
                    $pagesToProcess[] = array_merge($globalPageConfig, [
                        'id' => $pageId,
                        'url' => $p->getUrl(),
                        'title' => $pageName,
                        'enabled' => true,
                        // If IG user ID is stored in data, we can try to extract it
                        'ig_account' => $p->getData()['instagram_business_account']['id'] ?? null
                    ]);
                }
            } else {
                foreach ($pagesToProcessRaw as $p) {
                    $pagesToProcess[] = array_merge($globalPageConfig, $p);
                }
            }

            $logger->info("Processing " . count($pagesToProcess) . " Facebook pages");

            // Create a map for active pages
            $pageMap = [
                'map' => [],
                'mapReverse' => [],
            ];
            foreach ($pageRepository->findAll() as $p) {
                $pageMap['map'][$p->getUrl()] = $p->getId();
                $pageMap['mapReverse'][$p->getId()] = $p->getUrl();
            }

            $totalMetrics = 0;
            $totalRows = 0;
            $totalDuplicates = 0;

            // Process Pages & Instagram
            Helpers::reconnectIfNeeded($manager);
            foreach ($pagesToProcess as $page) {
                Helpers::checkJobStatus($jobId);

                $pageId = (string) ($page['id'] ?? '');
                $pageTitle = $page['title'] ?? '';
                $includeFilter = self::getFacebookFilter($config, 'PAGE', 'cache_include');
                $excludeFilter = self::getFacebookFilter($config, 'PAGE', 'cache_exclude');
                if (!Helpers::matchesFilter((string)$pageTitle, $includeFilter, $excludeFilter) && !Helpers::matchesFilter((string)$pageId, $includeFilter, $excludeFilter)) {
                    continue;
                }

                if (!$page['enabled'] || (!empty($page['exclude_from_caching']) && $page['exclude_from_caching'])) {
                    $logger->info("Skipping page: " . $page['id'] . ($page['enabled'] ? " (excluded from caching)" : " (disabled)"));
                    continue;
                }

                $pageEntity = $pageRepository->findOneBy(['platformId' => $page['id']]);
                if (!$pageEntity) {
                    $logger->error("Page entity not found for platformId=" . $page['id'] . ". Skipping.");
                    continue;
                }

                try {
                    $cacheChunkSize = $config['cache_chunk_size'] ?? '1 week';
                    $pageStartDate = $startDate;

                    if (filter_var($resume, FILTER_VALIDATE_BOOLEAN)) {
                        /** @var \Repositories\MetricRepository $metricRepo */
                        $metricRepo = $manager->getRepository(Metric::class);
                        $maxDate = $metricRepo->getMaxMetricDateForChannelAndPage(Channel::facebook_organic->value, $pageEntity->getId());
                        if ($maxDate) {
                            $latestFetchedDate = Carbon::parse($maxDate);
                            $jobStart = Carbon::parse($startDate);
                            if ($latestFetchedDate->gt($jobStart) && $latestFetchedDate->lt(Carbon::parse($endDate))) {
                                $pageStartDate = $latestFetchedDate->format('Y-m-d');
                                $logger->info("Smart resume: Starting {$pageId} from {$pageStartDate} based on latest cached metric date");
                            }
                        }
                    }

                    $chunks = Helpers::getDateChunks($pageStartDate, $endDate, $cacheChunkSize);
                    foreach ($chunks as $chunk) {
                        Helpers::checkJobStatus($jobId);
                        $cStart = $chunk['start'];
                        $cEnd = $chunk['end'];
                        $logger->info("Processing page chunk: $cStart to $cEnd");

                        if ($page['page_metrics']) {
                            self::processFacebookPage(
                                page: $page,
                                startDate: $cStart,
                                endDate: $cEnd,
                                api: $api,
                                manager: $manager,
                                pageRepository: $pageRepository,
                                logger: $logger,
                                pageMap: $pageMap,
                            );
                        }

                        if ($page['posts']) {
                            $postMap = self::getPostMap($manager, $pageEntity);
                            if ($page['post_metrics']) {
                                foreach ($postMap['map'] as $postIdInDb) {
                                    try {
                                        $postEntity = $postRepository->find($postIdInDb);
                                        if ($postEntity) {
                                            $postMsg = $postEntity->getData()['message'] ?? '';
                                            $includeFilter = self::getFacebookFilter($config, 'POST', 'cache_include');
                                            $excludeFilter = self::getFacebookFilter($config, 'POST', 'cache_exclude');
                                            if (!Helpers::matchesFilter($postMsg, $includeFilter, $excludeFilter) && !Helpers::matchesFilter($postEntity->getPostId(), $includeFilter, $excludeFilter)) {
                                                continue;
                                            }
                                            self::processFacebookPagePost(
                                                postEntity: $postEntity,
                                                pageEntity: $pageEntity,
                                                api: $api,
                                                manager: $manager,
                                                logger: $logger,
                                                postMap: $postMap,
                                                pageMap: $pageMap,
                                            );
                                        }
                                    } catch (Exception $e) {
                                        $logger->error("Error processing Facebook post " . $postIdInDb . ": " . $e->getMessage());
                                    }
                                }
                            }
                        }

                        if (!empty($page['ig_account']) && !empty($page['ig_accounts']) && !empty($page['ig_account_metrics'])) {
                            $includeFilter = self::getFacebookFilter($config, 'IG_ACCOUNT', 'cache_include');
                            $excludeFilter = self::getFacebookFilter($config, 'IG_ACCOUNT', 'cache_exclude');
                            if (!Helpers::matchesFilter((string) $page['ig_account'], $includeFilter, $excludeFilter)) {
                                $logger->info("Skipping Instagram account: " . $page['ig_account'] . " (filtered out)");
                            } elseif ($accountEntity) {
                                self::processInstagramAccount(
                                    page: $page,
                                    api: $api,
                                    manager: $manager,
                                    accountEntity: $accountEntity,
                                    pageEntity: $pageEntity,
                                    logger: $logger,
                                    pageMap: $pageMap,
                                    startDate: $cStart,
                                    endDate: $cEnd,
                                    config: $config,
                                    channel: 'facebook_organic'
                                );
                            } else {
                                $logger->error("Cannot process Instagram account " . $page['ig_account'] . " because accountEntity is null.");
                            }
                        }

                        if (!empty($page['ig_account']) && !empty($page['ig_accounts']) && !empty($page['ig_account_media'])) {
                            $channeledAccountEntity = $channeledAccountRepository->findOneBy([
                                'platformId' => $page['ig_account'],
                                'account' => $accountEntity,
                            ]);

                            if ($channeledAccountEntity) {
                                $mediaMap = self::getInstagramMediaMap($manager, $pageEntity, $channeledAccountEntity);
                                if ($page['ig_account_media_metrics']) {
                                    foreach ($mediaMap['map'] as $mediaIdInDb) {
                                        if ($page['ig_account_media_stop_id'] && ($mediaMap['mapReverse'][$mediaIdInDb] == $page['ig_account_media_stop_id'])) {
                                            break;
                                        }
                                        try {
                                            $mediaEntity = $postRepository->find($mediaIdInDb);
                                            if ($mediaEntity) {
                                                $mediaCaption = $mediaEntity->getData()['caption'] ?? '';
                                                $includeFilter = self::getFacebookFilter($config, 'IG_MEDIA', 'cache_include');
                                                $excludeFilter = self::getFacebookFilter($config, 'IG_MEDIA', 'cache_exclude');
                                                if (!Helpers::matchesFilter($mediaCaption, $includeFilter, $excludeFilter) && !Helpers::matchesFilter($mediaEntity->getPostId(), $includeFilter, $excludeFilter)) {
                                                    continue;
                                                }
                                                self::processInstagramMedia(
                                                    pageEntity: $pageEntity,
                                                    postEntity: $mediaEntity,
                                                    accountEntity: $accountEntity,
                                                    channeledAccountEntity: $channeledAccountEntity,
                                                    api: $api,
                                                    manager: $manager,
                                                    logger: $logger,
                                                    mediaMap: $mediaMap,
                                                    pageMap: $pageMap,
                                                );
                                            }
                                        } catch (Exception $e) {
                                            $logger->error("Error processing Instagram media " . $mediaIdInDb . ": " . $e->getMessage());
                                        }
                                    }
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    $logger->error("Error processing page " . $page['id'] . ": " . $e->getMessage());
                }
            }

            return self::finalizeTransaction($totalMetrics, $totalRows, $totalDuplicates, $logger, $startDate, $endDate);
        } catch (Exception $e) {
            $logger->error("Unexpected error in getListFromFacebookOrganic: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param string|null $startDate
     * @param string|null $endDate
     * @param string|bool $resume
     * @param LoggerInterface|null $logger
     * @return Response
     * @throws NotSupported
     * @throws ORMException
     * @throws \Doctrine\DBAL\Exception
     * @throws Exception
     */
    public static function getListFromFacebookMarketing(
        ?string $startDate = null,
        ?string $endDate = null,
        string|bool $resume = false,
        ?LoggerInterface $logger = null,
        ?int $jobId = null
    ): Response {
        if (!$logger) {
            $logger = Helpers::setLogger('facebook-marketing.log');
        }

        // Apply default dates if missing for "recent" safety
        if (empty($startDate)) {
            $startDate = Carbon::today()->subDays(3)->format('Y-m-d');
            $logger->info("No startDate provided, defaulting to $startDate");
        }
        if (empty($endDate)) {
            $endDate = Carbon::today()->format('Y-m-d');
            $logger->info("No endDate provided, defaulting to $endDate");
        }

        $logger->info("Starting getListFromFacebookMarketing: startDate=$startDate, endDate=$endDate, resume=$resume");
        $manager = Helpers::getManager();
        try {
            // Validate configuration
            $config = self::validateFacebookConfig($logger);

            // Initialize API client
            $api = self::initializeFacebookGraphApi($config, $logger);

            // Initialize repositories
            $accountRepository = $manager->getRepository(Account::class);
            $channeledAccountRepository = $manager->getRepository(ChanneledAccount::class);

            // Load global entities
            $accountEntityName = $config['accounts_group_name'] ?? null;
            $accountEntity = $accountEntityName ? $accountRepository->findOneBy(['name' => $accountEntityName]) : null;

            if (!$accountEntity) {
                $logger->error("Account group '{$accountEntityName}' not found. Facebook Marketing cannot be processed.");
                throw new Exception("Account group '{$accountEntityName}' not found.");
            }

            // Apply retention range limit to startDate
            $retentionLimit = self::getRetentionRange($config, 'facebook_marketing', '2 years');
            $requestedStart = Carbon::parse($startDate);
            if ($requestedStart->lt($retentionLimit)) {
                $logger->info("Truncating startDate from $startDate to " . $retentionLimit->format('Y-m-d') . " due to cache_history_range");
                $startDate = $retentionLimit->format('Y-m-d');
            }

            if (Carbon::parse($startDate)->gt(Carbon::parse($endDate))) {
                $logger->info("Job date range ($startDate to $endDate) is entirely before the retention limit (" . $retentionLimit->format('Y-m-d') . "). Skipping.");
                return new Response(json_encode(['message' => 'Job skipped due to retention policy']), 200);
            }

            $totalMetrics = 0;
            $totalRows = 0;
            $totalDuplicates = 0;
            $hasErrors = false;

            // Process Ad Accounts
            Helpers::reconnectIfNeeded($manager);
            $adAccountsToProcessRaw = $config['ad_accounts'] ?? [];
            $globalExcludeIds = array_map('strval', $config['exclude_from_caching'] ?? []);
            $globalAdAccountConfig = $config['AD_ACCOUNT'] ?? [
                'ad_account_metrics' => true,
                'campaigns' => true,
                'campaign_metrics' => true,
                'adsets' => false,
                'adset_metrics' => false,
                'ads' => false,
                'ad_metrics' => false,
                'creatives' => false,
                'creative_metrics' => false,
            ];

            $adAccountsToProcess = [];
            /** @var \Repositories\Channeled\ChanneledSyncErrorRepository $syncErrorRepo */
            $syncErrorRepo = $manager->getRepository(ChanneledSyncError::class);

            if (empty($adAccountsToProcessRaw)) {
                $logger->info("No specific ad accounts listed in config. Fetching all available ad accounts for channel from database.");
                $allChanneledAccounts = $channeledAccountRepository->findBy([
                    'channel' => Channel::facebook_marketing->value,
                    'type' => AccountEnum::META_AD_ACCOUNT->value
                ]);
                foreach ($allChanneledAccounts as $ca) {
                    $accId = (string) $ca->getPlatformId();
                    if (in_array($accId, $globalExcludeIds)) {
                        continue;
                    }
                    $accName = $ca->getName();
                    $includeFilter = self::getFacebookFilter($config, 'AD_ACCOUNT', 'cache_include');
                    $excludeFilter = self::getFacebookFilter($config, 'AD_ACCOUNT', 'cache_exclude');
                    if (!Helpers::matchesFilter((string)$accName, $includeFilter, $excludeFilter) && !Helpers::matchesFilter((string)$accId, $includeFilter, $excludeFilter)) {
                        continue;
                    }
                    $adAccountsToProcess[] = array_merge($globalAdAccountConfig, [
                        'id' => $accId,
                        'name' => $accName,
                        'enabled' => true
                    ]);
                }
            } else {
                foreach ($adAccountsToProcessRaw as $a) {
                    $adAccountsToProcess[] = array_merge($globalAdAccountConfig, $a);
                }
            }

            foreach ($adAccountsToProcess as $adAccount) {
                Helpers::checkJobStatus($jobId);

                $adAccId = (string) ($adAccount['id'] ?? '');
                $adAccName = $adAccount['name'] ?? '';
                $includeFilter = self::getFacebookFilter($config, 'AD_ACCOUNT', 'cache_include');
                $excludeFilter = self::getFacebookFilter($config, 'AD_ACCOUNT', 'cache_exclude');
                if (!Helpers::matchesFilter((string)$adAccName, $includeFilter, $excludeFilter) && !Helpers::matchesFilter((string)$adAccId, $includeFilter, $excludeFilter)) {
                    continue;
                }

                $channeledAccountEntity = $channeledAccountRepository->findOneBy([
                    'platformId' => $adAccount['id'],
                    'account' => $accountEntity,
                ]);

                if (!$channeledAccountEntity) {
                    $logger->error("ChanneledAccount entity not found for adAccount=" . $adAccount['id'] . ". Skipping.");
                    continue;
                }

                if (!$adAccount['enabled'] || (!empty($adAccount['exclude_from_caching']) && $adAccount['exclude_from_caching'])) {
                    $logger->info("Skipping ad account: " . $adAccount['id'] . ($adAccount['enabled'] ? " (excluded from caching)" : " (disabled)"));
                    continue;
                }

                try {
                    $cacheChunkSize = $config['cache_chunk_size'] ?? '1 week';
                    $accStartDate = $startDate;

                    if (filter_var($resume, FILTER_VALIDATE_BOOLEAN)) {
                        /** @var \Repositories\MetricRepository $metricRepo */
                        $metricRepo = $manager->getRepository(Metric::class);
                        $maxDate = $metricRepo->getMaxMetricDateForChannelAndChanneledAccount(Channel::facebook_marketing->value, $channeledAccountEntity->getId());
                        if ($maxDate) {
                            $latestFetchedDate = Carbon::parse($maxDate);
                            $jobStart = Carbon::parse($startDate);
                            if ($latestFetchedDate->gt($jobStart) && $latestFetchedDate->lt(Carbon::parse($endDate))) {
                                $accStartDate = $latestFetchedDate->format('Y-m-d');
                                $logger->info("Smart resume: Starting {$adAccId} from {$accStartDate} based on latest cached metric date");
                            }
                        }
                    }

                    $chunks = Helpers::getDateChunks($accStartDate, $endDate, $cacheChunkSize);
                    foreach ($chunks as $chunk) {
                        Helpers::checkJobStatus($jobId);
                        $cStart = $chunk['start'];
                        $cEnd = $chunk['end'];
                        $logger->info("Processing ad account chunk: $cStart to $cEnd");

                        if ($adAccount['ad_account_metrics']) {
                            $res = self::processAdAccount(
                                adAccount: $adAccount,
                                api: $api,
                                manager: $manager,
                                accountEntity: $accountEntity,
                                channeledAccountEntity: $channeledAccountEntity,
                                logger: $logger,
                                startDate: $cStart,
                                endDate: $cEnd,
                            );
                            $totalMetrics += $res['metrics'] ?? 0;
                            $totalRows += $res['rows'] ?? 0;
                        }

                        if ($adAccount['campaigns']) {
                            $campaignsMultiMap = self::getCampaignMaps($manager, $channeledAccountEntity);
                            $campaignMap = $campaignsMultiMap['campaignMap'];
                            $channeledCampaignMap = $campaignsMultiMap['channeledCampaignMap'];

                            if ($adAccount['campaign_metrics']) {
                                $res = self::processCampaignsBulk(
                                    api: $api,
                                    manager: $manager,
                                    channeledAccountEntity: $channeledAccountEntity,
                                    logger: $logger,
                                    startDate: $cStart,
                                    endDate: $cEnd,
                                    channeledCampaignMap: $channeledCampaignMap,
                                    campaignMap: $campaignMap,
                                    jobId: $jobId,
                                    cacheInclude: self::getFacebookFilter($config, 'CAMPAIGN', 'cache_include'),
                                    cacheExclude: self::getFacebookFilter($config, 'CAMPAIGN', 'cache_exclude'),
                                );
                                $totalMetrics += $res['metrics'] ?? 0;
                                $totalRows += $res['rows'] ?? 0;
                            }

                            if ($adAccount['adsets']) {
                                $channeledAdGroupMap = self::getAdGroupMap($manager, $channeledAccountEntity);

                                if ($adAccount['adset_metrics']) {
                                    $res = self::processAdsetsBulk(
                                        api: $api,
                                        manager: $manager,
                                        channeledAccountEntity: $channeledAccountEntity,
                                        logger: $logger,
                                        startDate: $cStart,
                                        endDate: $cEnd,
                                        campaignMap: $campaignMap,
                                        channeledCampaignMap: $channeledCampaignMap,
                                        channeledAdGroupMap: $channeledAdGroupMap,
                                        jobId: $jobId,
                                        cacheInclude: self::getFacebookFilter($config, 'ADSET', 'cache_include'),
                                        cacheExclude: self::getFacebookFilter($config, 'ADSET', 'cache_exclude'),
                                    );
                                    $totalMetrics += $res['metrics'] ?? 0;
                                    $totalRows += $res['rows'] ?? 0;
                                }

                                if ($adAccount['ads']) {
                                    $channeledAdMap = self::getAdMap($manager, $channeledAccountEntity);

                                    if ($adAccount['ad_metrics']) {
                                        $res = self::processAdsBulk(
                                            api: $api,
                                            manager: $manager,
                                            channeledAccountEntity: $channeledAccountEntity,
                                            logger: $logger,
                                            startDate: $cStart,
                                            endDate: $cEnd,
                                            campaignMap: $campaignMap,
                                            channeledCampaignMap: $channeledCampaignMap,
                                            channeledAdGroupMap: $channeledAdGroupMap,
                                            channeledAdMap: $channeledAdMap,
                                            jobId: $jobId,
                                            cacheInclude: self::getFacebookFilter($config, 'AD', 'cache_include'),
                                            cacheExclude: self::getFacebookFilter($config, 'AD', 'cache_exclude'),
                                        );
                                        $totalMetrics += $res['metrics'] ?? 0;
                                        $totalRows += $res['rows'] ?? 0;
                                    }
                                }
                            }

                            if (!empty($adAccount['creatives']) && !empty($adAccount['creative_metrics'])) {
                                $res = self::processCreativesBulk(
                                    api: $api,
                                    manager: $manager,
                                    channeledAccountEntity: $channeledAccountEntity,
                                    logger: $logger,
                                    startDate: $cStart,
                                    endDate: $cEnd,
                                    jobId: $jobId,
                                    cacheInclude: self::getFacebookFilter($config, 'CREATIVE', 'cache_include'),
                                    cacheExclude: self::getFacebookFilter($config, 'CREATIVE', 'cache_exclude'),
                                );
                                $totalMetrics += $res['metrics'] ?? 0;
                                $totalRows += $res['rows'] ?? 0;
                            }
                        }
                    }
                } catch (FacebookRateLimitException $e) {
                    throw $e;
                } catch (Exception $e) {
                    $hasErrors = true;
                    $logger->error("Error processing ad account " . $adAccount['id'] . ": " . $e->getMessage());
                    $syncErrorRepo->logError([
                        'platformId' => $adAccount['id'],
                        'channel' => Channel::facebook_marketing->value,
                        'syncType' => 'metric',
                        'entityType' => 'ad_account',
                        'errorMessage' => $e->getMessage(),
                        'extraData' => [
                            'startDate' => $startDate,
                            'endDate' => $endDate,
                            'account_id' => $adAccount['id']
                        ]
                    ]);
                }
            }

            if ($hasErrors) {
                throw new Exception("Finished with partial errors. Check channeled_sync_errors table or logs for details.");
            }

            return self::finalizeTransaction($totalMetrics, $totalRows, $totalDuplicates, $logger, $startDate, $endDate);
        } catch (FacebookRateLimitException $e) {
            $logger->error("Facebook API Rate Limit Reach: " . $e->getMessage());
            return new Response(json_encode(['error' => 'Rate limit reached: ' . $e->getMessage()]), 429);
        } catch (Exception $e) {
            $logger->error("Unexpected error in getListFromFacebookMarketing: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromBigCommerce(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromNetSuite(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromAmazon(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromInstagram(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromGoogleAnalytics(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * Fetches metrics from Google Search Console with deduplication and caching.
     *
     * @param string|null $startDate
     * @param string|null $endDate
     * @param object|null $filters
     * @param string|bool $resume
     * @param LoggerInterface|null $logger
     * @return Response
     * @throws NotSupported
     * @throws Exception
     */
    public static function getListFromGoogleSearchConsole(
        ?string $startDate = null,
        ?string $endDate = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?LoggerInterface $logger = null,
        ?int $jobId = null
    ): Response {
        if (!$logger) {
            $logger = Helpers::setLogger('gsc.log');
        }

        $logger->info("Starting getListFromGoogleSearchConsole: startDate=$startDate, endDate=$endDate, resume=$resume");
        $manager = Helpers::getManager();
        try {
            // Validate configuration
            $config = self::validateGoogleConfig($logger);

            // Initialize API client
            $api = self::initializeSearchConsoleApi($config, $logger);

            // Initialize repositories and settings
            /** @var ChanneledMetricRepository $channeledMetricRepository */
            $channeledMetricRepository = $manager->getRepository(ChanneledMetric::class);
            $pageRepository = $manager->getRepository(Page::class);
            $countryRepository = $manager->getRepository(Country::class);
            $deviceRepository = $manager->getRepository(Device::class);
            $metricNames = $filters->metricNames ?? ($config['google_search_console']['metrics'] ?? ['clicks', 'impressions', 'ctr', 'position']);
            // $dimensions = $filters->dimensions ?? ['date', 'query', 'page', 'country', 'device'];
            // Custom filter for dimensions disabled for GSC given the strict structure. Config dimensions used instead

            $logger->info("Initialized repositories, dimensions=" . implode(',', GoogleSearchConsoleHelpers::$allDimensions) . ", metricNames=" . json_encode($metricNames));
            $logger->warning("Note: 'searchAppearance' is not included in dimensions due to GSC API restrictions; defaulting to 'WEB' in normalized dimensions");

            // Load countries and create a map
            /** @var Country[] $countries */
            $countries = $countryRepository->findAll();
            $countryMap = [
                'map' => [],
                'mapReverse' => [],
            ];
            foreach ($countries as $country) {
                $countryMap['map'][$country->getCode()->value] = $country;
                $countryMap['mapReverse'][$country->getId()] = $country;
            }

            // Load devices and create a map
            /** @var Device[] $devices */
            $devices = $deviceRepository->findAll();
            $deviceMap = [
                'map' => [],
                'mapReverse' => [],
            ];
            foreach ($devices as $device) {
                $deviceMap['map'][$device->getType()->value] = $device;
                $deviceMap['mapReverse'][$device->getId()] = $device;
            }

            // Load pages and create a map
            /** @var Page[] $pages */
            $pages = $pageRepository->findAll();
            $pageMap = [
                'map' => [],
                'mapReverse' => [],
            ];
            foreach ($pages as $page) {
                $pageMap['map'][$page->getUrl()] = $page;
                $pageMap['mapReverse'][$page->getId()] = $page;
            }

            $totalMetrics = 0;
            $totalRows = 0;
            $totalDuplicates = 0;

            // Process each site
            $sitesToProcess = $config['google_search_console']['sites'] ?? [];
            if (empty($sitesToProcess)) {
                $logger->info("No specific sites listed in config. Fetching all available sites from database.");
                $allPages = $pageRepository->findByDataAttribute('source', 'gsc_site');
                foreach ($allPages as $p) {
                    $siteUrl = $p->getUrl();
                    $siteTitle = $p->getTitle();
                    if (!Helpers::matchesFilter($siteUrl, $config['google_search_console']['cache_include'] ?? null, $config['google_search_console']['cache_exclude'] ?? null) && !Helpers::matchesFilter($siteTitle, $config['google_search_console']['cache_include'] ?? null, $config['google_search_console']['cache_exclude'] ?? null)) {
                        continue;
                    }
                    $sitesToProcess[] = [
                        'url' => $siteUrl,
                        'title' => $siteTitle,
                        'enabled' => true,
                        // Defaults for GSC sites if not in config
                        'target_keywords' => [],
                        'target_countries' => [],
                    ];
                }
            }

            foreach ($sitesToProcess as $site) {
                Helpers::checkJobStatus($jobId);

                $siteUrl = $site['url'];
                $siteTitle = $site['title'] ?? $siteUrl;
                if (!Helpers::matchesFilter($siteUrl, $config['google_search_console']['cache_include'] ?? null, $config['google_search_console']['cache_exclude'] ?? null) && !Helpers::matchesFilter($siteTitle, $config['google_search_console']['cache_include'] ?? null, $config['google_search_console']['cache_exclude'] ?? null)) {
                    continue;
                }

                if (!$site['enabled']) {
                    $logger->info("Skipping disabled site: " . $site['url']);
                    continue;
                }
                $result = self::processGSCSite(
                    $site,
                    $startDate,
                    $endDate,
                    $resume,
                    $api,
                    $manager,
                    $channeledMetricRepository,
                    $pageRepository,
                    $metricNames,
                    $filters,
                    $logger,
                    $deviceMap,
                    $countryMap,
                    $pageMap,
                );
                $totalMetrics += $result['metrics'];
                $totalRows += $result['rows'];
                $totalDuplicates += $result['duplicates'];
            }

            // Finalize transaction and cache
            return self::finalizeTransaction(
                $totalMetrics,
                $totalRows,
                $totalDuplicates,
                $logger,
                $startDate,
                $endDate
            );
        } catch (GoogleQuotaExceededException $e) {
            $logger->error("Google API Quota Exceeded: " . $e->getMessage());
            try {
                if ($manager->getConnection()->isTransactionActive()) {
                    $manager->getConnection()->rollback();
                }
            } catch (Exception $rollbackException) {
                $logger->error("Error during transaction rollback: " . $rollbackException->getMessage());
            }
            return new Response(json_encode(['error' => 'Quota exceeded: ' . $e->getMessage()]), 429);
        } catch (Exception $e) {
            try {
                if ($manager->getConnection()->isTransactionActive()) {
                    $manager->getConnection()->rollback();
                    $logger->info("Rolled back transaction");
                }
            } catch (Exception $rollbackException) {
                $logger->error("Error during transaction rollback: " . $rollbackException->getMessage());
            }
            $logger->error("Unexpected error in getListFromGoogleSearchConsole: " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
            throw $e;
        } catch (GuzzleException $e) {
            $logger->error("GuzzleException in getListFromGoogleSearchConsole: " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
            throw new Exception("GuzzleException in getListFromGoogleSearchConsole: " . $e->getMessage());
        }
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromPinterest(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromLinkedIn(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromX(object $filters = null, string|bool $resume = true, ?int $jobId = null): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * Validates Google and GSC configurations.
     *
     * @param LoggerInterface $logger
     * @return array
     * @throws Exception
     */
    public static function validateGoogleConfig(LoggerInterface $logger): array
    {
        return GoogleSearchConsoleHelpers::validateGoogleConfig($logger);
    }

    /**
     * Validates and returns the Facebook configuration with global defaults.
     *
     * @param LoggerInterface|null $logger
     * @return array
     */
    public static function validateFacebookConfig(?LoggerInterface $logger = null): array
    {
        $channels = Helpers::getChannelsConfig();
        
        $config = $channels['facebook'] ?? [];
        $organic = $channels['facebook_organic'] ?? [];
        $marketing = $channels['facebook_marketing'] ?? [];

        // Merge specialized files into the main config array
        $config = array_replace_recursive($config, $organic, $marketing);

        if (empty($config)) {
            $logger?->error("Facebook configuration not found in channels config.");
            throw new RuntimeException("Facebook configuration not found in channels config.");
        }

        // Global exclusion list
        $globalExclude = $config['exclude_from_caching'] ?? [];
        if (!is_array($globalExclude)) {
            $globalExclude = [$globalExclude];
        }

        // Apply global defaults for PAGES if they exist
        if (isset($config['PAGE'])) {
            $globalPageDefaults = $config['PAGE'];
            $config['pages'] = array_map(function ($page) use ($globalPageDefaults, $globalExclude) {
                $merged = array_merge($globalPageDefaults, $page);
                if (in_array((string) $merged['id'], array_map('strval', $globalExclude))) {
                    $merged['exclude_from_caching'] = true;
                }
                return $merged;
            }, $config['pages'] ?? []);
        }

        // Apply global defaults for AD_ACCOUNTS if they exist
        if (isset($config['AD_ACCOUNT'])) {
            $globalAdAccountDefaults = $config['AD_ACCOUNT'];
            $config['ad_accounts'] = array_map(function ($adAccount) use ($globalAdAccountDefaults, $globalExclude) {
                $merged = array_merge($globalAdAccountDefaults, $adAccount);
                if (in_array((string) $merged['id'], array_map('strval', $globalExclude))) {
                    $merged['exclude_from_caching'] = true;
                }
                return $merged;
            }, $config['ad_accounts'] ?? []);
        }

        // If 'pages' list is NOT provided or empty, we mark it as such so it's handled during request
        if (!isset($config['pages']) || !is_array($config['pages'])) {
             $config['pages'] = []; 
        }

        // If 'ad_accounts' list is NOT provided or empty
        if (!isset($config['ad_accounts']) || !is_array($config['ad_accounts'])) {
             $config['ad_accounts'] = [];
        }

        return $config;
    }

    /**
     * Initializes the SearchConsoleApi client with retry logic.
     *
     * @param array $config
     * @param LoggerInterface $logger
     * @return SearchConsoleApi
     * @throws Exception
     */
    public static function initializeSearchConsoleApi(array $config, LoggerInterface $logger): SearchConsoleApi
    {
        $maxApiRetries = 3;
        $apiRetryCount = 0;
        while ($apiRetryCount < $maxApiRetries) {
            try {
                // Scope resolution: service-level → global google → default
                $scopes = $config['google_search_console']['scope']
                    ?? $config['google_search_console']['scopes']
                    ?? $config['google']['scopes']
                    ?? $config['google']['scope']
                    ?? ["https://www.googleapis.com/auth/webmasters"];
                if (is_string($scopes)) {
                    // Accept space-separated (OAuth standard) or comma-separated
                    $scopes = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $scopes))));
                }

                $redirectUri = $config['google_search_console']['redirect_uri'] ?? ($config['google']['redirect_uri'] ?? null);
                $clientId = $config['google_search_console']['client_id'] ?? ($config['google']['client_id'] ?? null);
                $clientSecret = $config['google_search_console']['client_secret'] ?? ($config['google']['client_secret'] ?? null);

                if (!$clientId || !$clientSecret) {
                    throw new Exception("Google Search Console credentials (client_id/client_secret) are missing. Please check your .env file.");
                }

                $apiInstance = new SearchConsoleApi(
                    redirectUrl: $redirectUri,
                    clientId: $clientId,
                    clientSecret: $clientSecret,
                    refreshToken: $config['google_search_console']['refresh_token'] ?? ($config['google']['refresh_token'] ?? null),
                    userId: $config['google_search_console']['user_id'] ?? ($config['google']['user_id'] ?? null),
                    scopes: $scopes,
                    token: $config['google_search_console']['token'] ?? "",
                    tokenPath: $config['google_search_console']['token_path'] ?? ($config['google']['token_path'] ?? "")
                );
                $logger->info("Initialized SearchConsoleApi");
                return $apiInstance;
            } catch (Exception $e) {
                $apiRetryCount++;
                if ($apiRetryCount >= $maxApiRetries) {
                    $logger->error("Failed to initialize SearchConsoleApi after $maxApiRetries retries: " . $e->getMessage());
                    throw new Exception("Failed to initialize SearchConsoleApi after $maxApiRetries retries: " . $e->getMessage());
                }
                $logger->warning("SearchConsoleApi initialization failed, retry $apiRetryCount/$maxApiRetries: " . $e->getMessage());
                usleep(100000 * $apiRetryCount);
            }
        }
        throw new Exception("Failed to initialize SearchConsoleApi");
    }

    /**
     * Initializes the SearchConsoleApi client with retry logic.
     *
     * @param array $config
     * @param LoggerInterface $logger
     * @return FacebookGraphApi
     * @throws Exception
     */
    public static function initializeFacebookGraphApi(array $config, LoggerInterface $logger): FacebookGraphApi
    {
        // Handle wrapped configuration
        if (isset($config['facebook']) && is_array($config['facebook']) && !isset($config['app_id'])) {
            $config = $config['facebook'];
        }

        $maxApiRetries = 3;
        $apiRetryCount = 0;
        while ($apiRetryCount < $maxApiRetries) {
            try {
                // Ensure required keys are present as strings
                $appSecret = $config['app_secret'] ?? null;
                if ($appSecret === null) {
                    $keysFound = implode(', ', array_keys($config));
                    $logger->error("Facebook app_secret is missing or null. Keys found: $keysFound");
                    throw new Exception("Facebook app_secret is missing or null.");
                }

                $apiInstance = new FacebookGraphApiOverride(
                    userId: (string) ($config['user_id'] ?? ''),
                    appId: (string) ($config['app_id'] ?? ''),
                    appSecret: (string) $appSecret,
                    redirectUrl: (string) ($config['app_redirect_uri'] ?? ''),
                    userAccessToken: (string) ($config['graph_user_access_token'] ?? ''),
                    longLivedUserAccessToken: (string) ($config['graph_long_lived_user_access_token'] ?? ''),
                    appAccessToken: (string) ($config['graph_app_access_token'] ?? ''),
                    pageAccesstoken: (string) ($config['graph_page_access_token'] ?? ''),
                    longLivedPageAccesstoken: (string) ($config['graph_long_lived_page_access_token'] ?? ''),
                    clientAccesstoken: (string) ($config['graph_client_access_token'] ?? ''),
                    longLivedClientAccesstoken: (string) ($config['graph_long_lived_client_access_token'] ?? ''),
                    tokenPath: (string) ($config['graph_token_path'] ?? ''),
                );
                $logger->info("Initialized FacebookGraphApi");
                return $apiInstance;
            } catch (Exception $e) {
                $apiRetryCount++;
                if ($apiRetryCount >= $maxApiRetries) {
                    $logger->error("Failed to initialize FacebookGraphApi after $maxApiRetries retries: " . $e->getMessage());
                    throw new Exception("Failed to initialize FacebookGraphApi after $maxApiRetries retries: " . $e->getMessage());
                }
                $logger->warning("FacebookGraphApi initialization failed, retry $apiRetryCount/$maxApiRetries: " . $e->getMessage());
                usleep(100000 * $apiRetryCount);
            }
        }
        throw new Exception("Failed to initialize FacebookGraphApi");
    }

    /**
     * Processes a single site, including page lookup and data fetching.
     *
     * @param array $site
     * @param string|null $startDate
     * @param string|null $endDate
     * @param string|bool $resume
     * @param SearchConsoleApi $api
     * @param EntityManager $manager
     * @param ChanneledMetricRepository $channeledMetricRepository
     * @param EntityRepository $pageRepository
     * @param array $metricNames
     * @param object|null $filters
     * @param LoggerInterface $logger
     * @param array $deviceMap
     * @param array $countryMap
     * @param array $pageMap
     * @return array
     * @throws GuzzleException
     * @throws NotSupported
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws \Doctrine\DBAL\Exception
     * @throws Exception
     */
    private static function processGSCSite(
        array $site,
        ?string $startDate,
        ?string $endDate,
        string|bool $resume,
        SearchConsoleApi $api,
        EntityManager $manager,
        ChanneledMetricRepository $channeledMetricRepository,
        EntityRepository $pageRepository,
        array $metricNames,
        ?object $filters,
        LoggerInterface $logger,
        array $deviceMap,
        array $countryMap,
        array $pageMap,
    ): array {
        $siteUrl = $site['url'];
        $siteKey = str_replace(['https://', 'sc-domain:', '/'], '', $siteUrl);
        $normalizedSiteUrl = rtrim($siteUrl, '/');
        $logger->info("Processing site: $siteUrl, normalized: $normalizedSiteUrl, siteKey=$siteKey");

        $targetKeywords = $site['target_keywords'] ?? [];
        $targetCountries = $site['target_countries'] ?? [];
        $dimensionFilterGroups = self::getDimensionFilterGroups($filters, $site);
        $logger->info("Target keywords: " . implode(',', $targetKeywords) . ", countries: " . implode(',', $targetCountries));

        // Get page entity
        $pageEntity = $pageRepository->getByUrl($normalizedSiteUrl);
        if (!$pageEntity) {
            $logger->error("Page entity not found for URL=$normalizedSiteUrl. Run app:initialize-entities command.");
            throw new Exception("Page entity not found for URL=$normalizedSiteUrl");
        }
        $logger->info("Found Page: ID=" . $pageEntity->getId() . ", URL=$normalizedSiteUrl");

        // Get last channeled metric
        $lastChanneledMetric = $channeledMetricRepository->getLastByPlatformCreatedAtForSite(
            Channel::google_search_console->value,
            $siteKey
        );
        $logger->info("Last channeled metric: " . ($lastChanneledMetric ? json_encode($lastChanneledMetric) : 'none'));

        // Determine date range
        list($from, $to) = self::determineDateRange($startDate, $lastChanneledMetric, $resume, $endDate, $logger);

        // Initialize daily processing
        $startTime = microtime(true);
        $siteMetrics = 0;
        $siteRows = 0;
        $siteDuplicates = 0;
        $period = Carbon::parse($from)->toPeriod($to, '1 day');
        foreach ($period as $day) {
            $dayStr = $day->format('Y-m-d');
            $result = self::fetchGSCDailyData(
                $dayStr,
                $site,
                $api,
                $manager,
                $pageEntity,
                $metricNames,
                $targetKeywords,
                $targetCountries,
                $dimensionFilterGroups,
                $logger,
                $deviceMap,
                $countryMap,
                $pageMap,
            );
            $siteMetrics += $result['metrics'];
            $siteRows += $result['rows'];
            $siteDuplicates += $result['duplicates'];

            self::updateGSCMetricsValues($manager, $siteUrl, $dayStr, $logger);
        }

        $totalTime = microtime(true) - $startTime;
        $logger->info("Processed site $siteUrl: metrics=$siteMetrics, rows=$siteRows, duplicates=$siteDuplicates, took $totalTime seconds");

        return [
            'metrics' => $siteMetrics,
            'rows' => $siteRows,
            'duplicates' => $siteDuplicates
        ];
    }

    /**
     * Processes a single site, including page lookup and data fetching.
     *
     * @param array $page
     * @param string|null $startDate
     * @param string|null $endDate
     * @param FacebookGraphApi $api
     * @param EntityManager $manager
     * @param EntityRepository $pageRepository
     * @param LoggerInterface $logger
     * @param array $pageMap
     * @return void
     * @throws GuzzleException
     * @throws \Doctrine\DBAL\Exception
     * @throws Exception
     */
    private static function processFacebookPage(
        array $page,
        ?string $startDate,
        ?string $endDate,
        FacebookGraphApi $api,
        EntityManager $manager,
        EntityRepository $pageRepository,
        LoggerInterface $logger,
        array $pageMap,
    ): void {

        // Get page entity
        $pageEntity = $pageRepository->findOneBy(['platformId' => $page['id']]);
        if (!$pageEntity) {
            $logger->error("Page entity not found for platformId=". $page['id']. ". Run app:initialize-entities command.");
            throw new Exception("Page entity not found for platformId=". $page['id']);
        }
        $logger->info("Found Page: ID=" . $pageEntity->getId() . ", platformId=". $page['id']);

        $allMetrics = new ArrayCollection();

        try {
            $maxRetries = 3;
            $retryCount = 0;
            $fetched = false;
            $rows = ['data' => []];

            while ($retryCount < $maxRetries && !$fetched) {
                try {
                    $rows = $api->getFacebookPageInsights(
                        pageId: (string) $page['id'],
                        since: $startDate ?: Carbon::today()->subMonths(3)->format('Y-m-d'),
                        until: $endDate ?: Carbon::today()->format('Y-m-d'),
                    );
                    $fetched = true;
                } catch (Exception $e) {
                    $retryCount++;
                    if ($retryCount >= $maxRetries) {
                        throw $e;
                    }
                    $logger->warning("Retry $retryCount/$maxRetries for FB page insights " . $page['id'] . ": " . $e->getMessage());
                    usleep(200000 * $retryCount);
                }
            }

            if (count($rows['data']) === 0) {
                $logger->info("No rows found for page " . $page['id']);
                return;
            }

            $metrics = FacebookOrganicMetricConvert::pageMetrics(
                rows: $rows['data'],
                pagePlatformId: (string) $page['id'],
                logger: $logger,
                pageEntity: $pageEntity,
            );

            foreach ($metrics as $metric) {
                $metric->page = $pageEntity;
                $allMetrics->add($metric);
            }

            if (count($allMetrics) === 0) {
                $logger->info("No metrics found for page " . $page['id']);
                return;
            }

            try {
                $manager->getConnection()->beginTransaction();

                // Map metric configs
                $metricConfigMap = MetricsProcessor::processMetricConfigs(
                    metrics: $allMetrics,
                    manager: $manager,
                    pageMap: $pageMap,
                );

                // Map metrics
                $metricMap = MetricsProcessor::processMetrics(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricConfigMap: $metricConfigMap,
                );

                // Map channeled metrics
                $channeledMetricMap = MetricsProcessor::processChanneledMetrics(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricMap: $metricMap,
                    logger: $logger,
                );

                // Map dimensions

                $manager->getConnection()->commit();
            } catch (Exception $e) {
                if ($manager->getConnection()->isTransactionActive()) {
                    $manager->getConnection()->rollback();
                }
                throw $e;
            }

            $logger->info("Completed FB page insights request");

            return;
        } catch (Exception $e) {
            $logger->error("Error during FB page insights request for page " . $page['id'] . ": " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Processes a single site, including page lookup and data fetching.
     *
     * @param array $adAccount
     * @param FacebookGraphApi $api
     * @param EntityManager $manager
     * @param Account $accountEntity
     * @param ChanneledAccount $channeledAccountEntity
     * @param LoggerInterface $logger
     * @return void
     * @throws GuzzleException
     * @throws \Doctrine\DBAL\Exception
     */
    private static function processAdAccount(
        array $adAccount,
        FacebookGraphApi $api,
        EntityManager $manager,
        Account $accountEntity,
        ChanneledAccount $channeledAccountEntity,
        LoggerInterface $logger,
        ?string $startDate,
        ?string $endDate,
    ): array {

        $allMetrics = new ArrayCollection();
        $accountMap = [
            'map' => [
                $accountEntity->getName() => $accountEntity->getId(),
            ],
            'mapReverse' => [
                $accountEntity->getId() => $accountEntity->getName(),
            ],
        ];
        $channeledAccountMap = [
            'map' => [
                $channeledAccountEntity->getPlatformId() => $channeledAccountEntity->getId(),
            ],
            'mapReverse' => [
                $channeledAccountEntity->getId() => $channeledAccountEntity->getPlatformId(),
            ],
        ];

        try {
            $additionalParams = [];
            if ($startDate && $endDate) {
                $additionalParams['time_range'] = json_encode(['since' => $startDate, 'until' => $endDate]);
            }

            $maxRetries = 3;
            $retryCount = 0;
            $fetched = false;
            $rows = ['data' => []];

            while ($retryCount < $maxRetries && !$fetched) {
                try {
                    $rows = $api->getAdAccountInsights(
                        adAccountId: (string) $adAccount['id'],
                        metricBreakdown: [MetricBreakdown::AGE, MetricBreakdown::GENDER],
                        additionalParams: $additionalParams,
                        metricSet: MetricSet::KEY,
                    );
                    $fetched = true;
                } catch (Exception $e) {
                    $retryCount++;
                    if ($retryCount >= $maxRetries) {
                        throw $e;
                    }
                    $logger->warning("Retry $retryCount/$maxRetries for Meta ad account insights " . $adAccount['id'] . ": " . $e->getMessage());
                    usleep(200000 * $retryCount);
                }
            }

            if (count($rows['data']) === 0) {
                $logger->info("No rows found for ad account " . $adAccount['id']);
                return ['metrics' => 0, 'rows' => 0];
            }

            $metrics = FacebookMarketingMetricConvert::adAccountMetrics(
                rows: $rows['data'],
                logger: $logger,
                accountEntity: $accountEntity,
                channeledAccountPlatformId: $channeledAccountEntity->getPlatformId(),
                metricSet: MetricSet::KEY,
            );

            foreach ($metrics as $metric) {
                $metric->account = $accountEntity;
                $metric->channeledAccount = $channeledAccountEntity;
                $allMetrics->add($metric);
            }

            if (count($allMetrics) === 0) {
                $logger->info("No metrics found for ad account " . $adAccount['id']);
                return ['metrics' => 0, 'rows' => count($rows['data'])];
            }

            try {
                $manager->getConnection()->beginTransaction();

                // Map metric configs
                $metricConfigMap = MetricsProcessor::processMetricConfigs(
                    metrics: $allMetrics,
                    manager: $manager,
                    accountMap: $accountMap,
                    channeledAccountMap: $channeledAccountMap,
                );

                // Map metrics
                $metricMap = MetricsProcessor::processMetrics(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricConfigMap: $metricConfigMap,
                );

                // Map channeled metrics
                $channeledMetricMap = MetricsProcessor::processChanneledMetrics(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricMap: $metricMap,
                    logger: $logger,
                );

                // Map dimensions
                $manager->getConnection()->commit();
            } catch (Exception $e) {
                if ($manager->getConnection()->isTransactionActive()) {
                    $manager->getConnection()->rollback();
                }
                throw $e;
            }

            return ['metrics' => count($allMetrics), 'rows' => count($rows['data'])];
        } catch (Exception $e) {
            $logger->error("Error during Meta account insights request for ad account " . $adAccount['id'] . ": " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Processes a single site, including page lookup and data fetching.
     *
     * @param array $page
     * @param FacebookGraphApi $api
     * @param EntityManager $manager
     * @param Account $accountEntity
     * @param Page $pageEntity
     * @param LoggerInterface $logger
     * @param array $pageMap
     * @param string|null $startDate
     * @param string|null $endDate
     * @return void
     * @throws GuzzleException
     * @throws NotSupported
     * @throws \Doctrine\DBAL\Exception
     * @throws Exception
     */
    private static function processInstagramAccount(
        array $page,
        FacebookGraphApi $api,
        EntityManager $manager,
        Account $accountEntity,
        Page $pageEntity,
        LoggerInterface $logger,
        array $pageMap,
        ?string $startDate = null,
        ?string $endDate = null,
        array $config = [],
        string $channel = 'facebook_organic'
    ): void {

        if (!$startDate) {
            $startDate = self::getRetentionRange($config, $channel, '2 years')->endOfDay(); // Default to configured years ago at the end of the day
        } else {
            $startDate = Carbon::parse($startDate)->startOfDay(); // Ensure start date is at the beginning of the day
        }
        if (!$endDate) {
            $endDate = Carbon::today()->endOfDay()->subDays(2); // Default to 2 days ago at the end of the day
        } else {
            $endDate = Carbon::parse($endDate)->startOfDay()->addDay(); // Ensure end date is at the beginning of the following day
        }

        if (!$startDate->isBefore(Carbon::parse($endDate))) {
            $logger->error("Start date must be before end date. startDate=$startDate, endDate=$endDate");
            throw new Exception("Start date must be before end date. startDate=$startDate, endDate=$endDate");
        }

        $allMetrics = new ArrayCollection();
        $accountMap = [
            'map' => [
                $accountEntity->getName() => $accountEntity->getId(),
            ],
            'mapReverse' => [
                $accountEntity->getId() => $accountEntity->getName(),
            ],
        ];
        $channeledAccountMap = [
            'map' => [],
            'mapReverse' => [],
        ];

        $channeledAccountRepository = $manager->getRepository(ChanneledAccount::class);
        $channeledAccountEntity = $channeledAccountRepository->findOneBy([
            'platformId' => (string) $page['ig_account'],
            'channel' => Channel::facebook_organic->value,
            'type' => AccountEnum::INSTAGRAM->value,
        ]);
        $channeledAccountMap['map'][(string) $page['ig_account']] = $channeledAccountEntity->getId();
        $channeledAccountMap['mapReverse'][$channeledAccountEntity->getId()] = (string) $page['ig_account'];

        try {
            do {
                $rows = [
                    'data' => [],
                ];
                $option = 1;
                while ($option <= 5) {
                    // OPTIONS LIST:
                    // 1. Get REACH and VIEWS broken by FOLLOW_TYPE and MEDIA_PRODUCT_TYPE (Default)
                    // 2. Get FOLLOWS_AND_UNFOLLOWS broken by FOLLOW_TYPE
                    // 3. Get COMMENTS, LIKES, SAVES, SHARES and TOTAL_INTERACTIONS broken by MEDIA_PRODUCT_TYPE
                    // 4. Get PROFILE_LINK_TAPS broken by CONTACT_BUTTON_TYPE
                    // 5. Get WEBSITE_CLICKS, PROFILE_VIEWS, ACCOUNTS_ENGAGED, REPLIES and CONTENT_VIEWS with no breakdowns
                    $logger->info("Fetching Instagram account insights for page " . $page['id'] . ", option: $option");
                    
                    $maxRetries = 3;
                    $retryCount = 0;
                    $fetched = false;
                    $insights = ['data' => []];

                    while ($retryCount < $maxRetries && !$fetched) {
                        try {
                            $insights = $api->getDailyInstagramAccountTotalValueInsights(
                                instagramAccountId: (string) $page['ig_account'],
                                since: $startDate->format('Y-m-d'),
                                option: $option,
                            );
                            $fetched = true;
                        } catch (Exception $e) {
                            $retryCount++;
                            if ($retryCount >= $maxRetries) {
                                throw $e;
                            }
                            $logger->warning("Retry $retryCount/$maxRetries for IG account insights " . $page['ig_account'] . " (option $option): " . $e->getMessage());
                            usleep(200000 * $retryCount);
                        }
                    }

                    if (isset($insights['data']) && count($insights['data']) > 0) {
                        $rows['data'] = [
                            ...$rows['data'],
                            ...$insights['data']
                        ];
                    }
                    $option++;
                }
                $metrics = FacebookOrganicMetricConvert::igAccountMetrics(
                    rows: $rows['data'],
                    date: $startDate->format('Y-m-d'),
                    pageEntity: $pageEntity,
                    accountEntity: $accountEntity,
                    channeledAccountEntity: $channeledAccountEntity,
                    logger: $logger,
                );

                foreach ($metrics as $metric) {
                    $metric->page = $pageEntity;
                    $metric->account = $accountEntity;
                    $metric->channeledAccount = $channeledAccountEntity;
                    $allMetrics->add($metric);
                }
                $startDate->addDay();
            } while ($startDate->isBefore(Carbon::now()->endOfDay()->subDays(2)) && $startDate->isBefore(Carbon::parse($endDate))); // Continue until 2 days ago

            if (count($allMetrics) === 0) {
                $logger->info("No metrics found for page " . $page['id']);
                return;
            }

            try {
                $manager->getConnection()->beginTransaction();

                // Map metric configs
                $metricConfigMap = MetricsProcessor::processMetricConfigs(
                    metrics: $allMetrics,
                    manager: $manager,
                    pageMap: $pageMap,
                    accountMap: $accountMap,
                    channeledAccountMap: $channeledAccountMap,
                );

                // Map metrics
                $metricMap = MetricsProcessor::processMetrics(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricConfigMap: $metricConfigMap,
                );

                // Map channeled metrics
                $channeledMetricMap = MetricsProcessor::processChanneledMetrics(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricMap: $metricMap,
                    logger: $logger,
                );

                // Map dimensions
                $manager->getConnection()->commit();
            } catch (Exception $e) {
                if ($manager->getConnection()->isTransactionActive()) {
                    $manager->getConnection()->rollback();
                }
                throw $e;
            }

            $logger->info("Completed FB page insights request");

            return;
        } catch (Exception $e) {
            $logger->error("Error during FB page insights request for page " . $page['id'] . ": " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Processes a single site, including page lookup and data fetching.
     *
     * @param Page $pageEntity
     * @param Post $postEntity
     * @param Account $accountEntity
     * @param ChanneledAccount $channeledAccountEntity
     * @param FacebookGraphApi $api
     * @param EntityManager $manager
     * @param LoggerInterface $logger
     * @param array $mediaMap
     * @param array $pageMap
     * @return bool
     * @throws GuzzleException
     * @throws \Doctrine\DBAL\Exception
     */
    private static function processInstagramMedia(
        Page $pageEntity,
        Post $postEntity,
        Account $accountEntity,
        ChanneledAccount $channeledAccountEntity,
        FacebookGraphApi $api,
        EntityManager $manager,
        LoggerInterface $logger,
        array $mediaMap,
        array $pageMap,
    ): bool {

        $accountMap = [
            'map' => [
                $accountEntity->getName() => $accountEntity->getId(),
            ],
            'mapReverse' => [
                $accountEntity->getId() => $accountEntity->getName(),
            ],
        ];
        $channeledAccountMap = [
            'map' => [
                $channeledAccountEntity->getPlatformId() => $channeledAccountEntity->getId(),
            ],
            'mapReverse' => [
                $channeledAccountEntity->getId() => $channeledAccountEntity->getPlatformId(),
            ],
        ];

        $allMetrics = new ArrayCollection();

        try {
            $maxRetries = 3;
            $retryCount = 0;
            $fetched = false;
            $insights = ['data' => []];

            while ($retryCount < $maxRetries && !$fetched) {
                try {
                    $insights = $api->getInstagramMediaInsights(
                        mediaId: $postEntity->getPostId(),
                        mediaType: MediaType::from($mediaMap['mapData'][$postEntity->getPostId()]),
                    );
                    $fetched = true;
                } catch (Exception $e) {
                    $retryCount++;
                    if ($retryCount >= $maxRetries) {
                        throw $e;
                    }
                    $logger->warning("Retry $retryCount/$maxRetries for IG media insights " . $postEntity->getPostId() . ": " . $e->getMessage());
                    usleep(200000 * $retryCount);
                }
            }

            if (count($insights['data']) === 0) {
                $logger->info("No insights found for post " . $postEntity->getPostId());
                return false;
            }

            $metrics = FacebookOrganicMetricConvert::igMediaMetrics(
                rows: $insights['data'],
                pageEntity: $pageEntity,
                postEntity: $postEntity,
                accountEntity: $accountEntity,
                channeledAccountEntity: $channeledAccountEntity,
                logger: $logger,
            );

            foreach ($metrics as $metric) {
                $metric->postId = $postEntity;
                $metric->page = $pageEntity;
                $metric->account = $accountEntity;
                $metric->channeledAccount = $channeledAccountEntity;
                $allMetrics->add($metric);
            }

            try {
                $manager->getConnection()->beginTransaction();

                // Map metric configs
                $metricConfigMap = MetricsProcessor::processMetricConfigs(
                    metrics: $allMetrics,
                    manager: $manager,
                    pageMap: $pageMap,
                    postMap: $mediaMap,
                    accountMap: $accountMap,
                    channeledAccountMap: $channeledAccountMap,
                );

                // Map metrics
                $metricMap = MetricsProcessor::processMetrics(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricConfigMap: $metricConfigMap,
                );

                // Map channeled metrics
                $channeledMetricMap = MetricsProcessor::processChanneledMetrics(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricMap: $metricMap,
                    logger: $logger,
                );

                // Map dimensions
                $manager->getConnection()->commit();
            } catch (Exception $e) {
                if ($manager->getConnection()->isTransactionActive()) {
                    $manager->getConnection()->rollback();
                }
                throw $e;
            }

            $logger->info("Completed FB page insights request");

            return true;
        } catch (Exception $e) {
            $logger->error("Error during IG Media insights request for post " . $postEntity->getPostId() . ": " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Processes a single site, including page lookup and data fetching.
     *
     * @param FacebookGraphApi $api
     * @param EntityManager $manager
     * @param Campaign $campaignEntity
     * @param ChanneledCampaign $channeledCampaignEntity
     * @param ChanneledAccount $channeledAccountEntity
     * @param LoggerInterface $logger
     * @param array $campaignMap
     * @param array $channeledCampaignMap
     * @return bool
     * @throws GuzzleException
     * @throws \Doctrine\DBAL\Exception
     */
    private static function processCampaign(
        FacebookGraphApi $api,
        EntityManager $manager,
        Campaign $campaignEntity,
        ChanneledCampaign $channeledCampaignEntity,
        ChanneledAccount $channeledAccountEntity,
        LoggerInterface $logger,
        array $campaignMap,
        array $channeledCampaignMap,
    ): bool {

        $allMetrics = new ArrayCollection();
        $channeledAccountMap = [
            'map' => [
                $channeledAccountEntity->getPlatformId() => $channeledAccountEntity->getId(),
            ],
            'mapReverse' => [
                $channeledAccountEntity->getId() => $channeledAccountEntity->getPlatformId(),
            ],
        ];

        $campaignPlatformId = $channeledCampaignEntity->getPlatformId();

        try {
            $maxRetries = 3;
            $retryCount = 0;
            $fetched = false;
            $rows = ['data' => []];

            while ($retryCount < $maxRetries && !$fetched) {
                try {
                    $rows = $api->getCampaignInsights(
                        campaignId: $campaignPlatformId,
                    );
                    $fetched = true;
                } catch (Exception $e) {
                    $retryCount++;
                    if ($retryCount >= $maxRetries) {
                        throw $e;
                    }
                    $logger->warning("Retry $retryCount/$maxRetries for campaign insights $campaignPlatformId: " . $e->getMessage());
                    usleep(200000 * $retryCount);
                }
            }

            if (count($rows['data']) === 0) {
                $logger->info("No rows found for campaign " . $campaignPlatformId);
                return false;
            }

            $metrics = FacebookMarketingMetricConvert::campaignMetrics(
                rows: $rows['data'],
                logger: $logger,
                channeledAccountEntity: $channeledAccountEntity,
                campaignEntity: $campaignEntity,
                channeledCampaignEntity: $channeledCampaignEntity,
                metricSet: MetricSet::KEY,
            );

            foreach ($metrics as $metric) {
                $metric->channeledAccount = $channeledAccountEntity;
                $metric->campaign = $campaignEntity;
                $metric->channeledCampaign = $channeledCampaignEntity;
                $allMetrics->add($metric);
            }

            // Helpers::dumpDebugJson($allMetrics->toArray());

            try {
                $manager->getConnection()->beginTransaction();

                // Map metric configs
                $metricConfigMap = MetricsProcessor::processMetricConfigs(
                    metrics: $allMetrics,
                    manager: $manager,
                    channeledAccountMap: $channeledAccountMap,
                    campaignMap: $campaignMap,
                    channeledCampaignMap: $channeledCampaignMap,
                );

                // Helpers::dumpDebugJson($metricConfigMap);

                // Map metrics
                $metricMap = MetricsProcessor::processMetrics(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricConfigMap: $metricConfigMap,
                );

                // Map channeled metrics
                $channeledMetricMap = MetricsProcessor::processChanneledMetrics(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricMap: $metricMap,
                    logger: $logger,
                );

                // Map dimensions
                $manager->getConnection()->commit();
            } catch (Exception $e) {
                if ($manager->getConnection()->isTransactionActive()) {
                    $manager->getConnection()->rollback();
                }
                throw $e;
            }

            $logger->info("Completed Meta ad account's campaign insights request");

            return true;
        } catch (Exception $e) {
            $logger->error("Error during Meta account's campaign insights request for campaign " . $campaignEntity->getCampaignId() . ": " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Processes a single site, including page lookup and data fetching.
     *
     * @param FacebookGraphApi $api
     * @param EntityManager $manager
     * @param Campaign $campaignEntity
     * @param ChanneledCampaign $channeledCampaignEntity
     * @param ChanneledAccount $channeledAccountEntity
     * @param ChanneledAdGroup $channeledAdGroupEntity
     * @param LoggerInterface $logger
     * @param array $campaignMap
     * @param array $channeledCampaignMap
     * @param array $channeledAdGroupMap
     * @return bool
     * @throws GuzzleException
     * @throws \Doctrine\DBAL\Exception
     */
    private static function processAdset(
        FacebookGraphApi $api,
        EntityManager $manager,
        Campaign $campaignEntity,
        ChanneledCampaign $channeledCampaignEntity,
        ChanneledAccount $channeledAccountEntity,
        ChanneledAdGroup $channeledAdGroupEntity,
        LoggerInterface $logger,
        array $campaignMap,
        array $channeledCampaignMap,
        array $channeledAdGroupMap,
    ): bool {

        $allMetrics = new ArrayCollection();
        $channeledAccountMap = [
            'map' => [
                $channeledAccountEntity->getPlatformId() => $channeledAccountEntity->getId(),
            ],
            'mapReverse' => [
                $channeledAccountEntity->getId() => $channeledAccountEntity->getPlatformId(),
            ],
        ];

        $adsetPlatformId = $channeledAdGroupEntity->getPlatformId();
        try {
            $maxRetries = 3;
            $retryCount = 0;
            $fetched = false;
            $rows = ['data' => []];

            while ($retryCount < $maxRetries && !$fetched) {
                try {
                    $rows = $api->getAdsetInsights(
                        adsetId: $adsetPlatformId,
                    );
                    $fetched = true;
                } catch (Exception $e) {
                    $retryCount++;
                    if ($retryCount >= $maxRetries) {
                        throw $e;
                    }
                    $logger->warning("Retry $retryCount/$maxRetries for adset insights $adsetPlatformId: " . $e->getMessage());
                    usleep(200000 * $retryCount);
                }
            }

            if (count($rows['data']) === 0) {
                $logger->info("No rows found for adset " . $adsetPlatformId);
                return false;
            }

            $metrics = FacebookMarketingMetricConvert::adsetMetrics(
                rows: $rows['data'],
                logger: $logger,
                channeledAccountEntity: $channeledAccountEntity,
                campaignEntity: $campaignEntity,
                channeledCampaignEntity: $channeledCampaignEntity,
                channeledAdGroupEntity: $channeledAdGroupEntity,
                metricSet: MetricSet::KEY,
            );

            foreach ($metrics as $metric) {
                $metric->channeledAccount = $channeledAccountEntity;
                $metric->campaign = $campaignEntity;
                $metric->channeledCampaign = $channeledCampaignEntity;
                $metric->channeledAdGroup = $channeledAdGroupEntity;
                $allMetrics->add($metric);
            }

            // Helpers::dumpDebugJson($allMetrics->toArray());

            try {
                $manager->getConnection()->beginTransaction();

                // Map metric configs
                $metricConfigMap = MetricsProcessor::processMetricConfigs(
                    metrics: $allMetrics,
                    manager: $manager,
                    channeledAccountMap: $channeledAccountMap,
                    campaignMap: $campaignMap,
                    channeledCampaignMap: $channeledCampaignMap,
                    channeledAdGroupMap: $channeledAdGroupMap
                );

                // Helpers::dumpDebugJson($metricConfigMap);

                // Map metrics
                $metricMap = MetricsProcessor::processMetrics(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricConfigMap: $metricConfigMap,
                );

                // Map channeled metrics
                $channeledMetricMap = MetricsProcessor::processChanneledMetrics(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricMap: $metricMap,
                    logger: $logger,
                );

                // Map dimensions
                $manager->getConnection()->commit();
            } catch (Exception $e) {
                if ($manager->getConnection()->isTransactionActive()) {
                    $manager->getConnection()->rollback();
                }
                throw $e;
            }

            $logger->info("Completed Meta ad account's campaign's adset insights request");

            return true;
        } catch (Exception $e) {
            $logger->error("Error during Meta account's campaign's adset insights request for adset " . $channeledAdGroupEntity->getPlatformId() . ": " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Processes a single site, including page lookup and data fetching.
     *
     * @param FacebookGraphApi $api
     * @param EntityManager $manager
     * @param Campaign $campaignEntity
     * @param ChanneledCampaign $channeledCampaignEntity
     * @param ChanneledAccount $channeledAccountEntity
     * @param ChanneledAdGroup $channeledAdGroupEntity
     * @param ChanneledAd $channeledAdEntity
     * @param LoggerInterface $logger
     * @param array $campaignMap
     * @param array $channeledCampaignMap
     * @param array $channeledAdGroupMap
     * @param array $channeledAdMap
     * @return bool
     * @throws GuzzleException
     * @throws \Doctrine\DBAL\Exception
     */
    private static function processAd(
        FacebookGraphApi $api,
        EntityManager $manager,
        Campaign $campaignEntity,
        ChanneledCampaign $channeledCampaignEntity,
        ChanneledAccount $channeledAccountEntity,
        ChanneledAdGroup $channeledAdGroupEntity,
        ChanneledAd $channeledAdEntity,
        LoggerInterface $logger,
        array $campaignMap,
        array $channeledCampaignMap,
        array $channeledAdGroupMap,
        array $channeledAdMap,
    ): bool {

        $allMetrics = new ArrayCollection();
        $channeledAccountMap = [
            'map' => [
                $channeledAccountEntity->getPlatformId() => $channeledAccountEntity->getId(),
            ],
            'mapReverse' => [
                $channeledAccountEntity->getId() => $channeledAccountEntity->getPlatformId(),
            ],
        ];

        $adPlatformId = $channeledAdEntity->getPlatformId();
        try {
            $maxRetries = 3;
            $retryCount = 0;
            $fetched = false;
            $rows = ['data' => []];

            while ($retryCount < $maxRetries && !$fetched) {
                try {
                    $rows = $api->getAdInsights(
                        adId: $adPlatformId,
                    );
                    $fetched = true;
                } catch (Exception $e) {
                    $retryCount++;
                    if ($retryCount >= $maxRetries) {
                        throw $e;
                    }
                    $logger->warning("Retry $retryCount/$maxRetries for ad insights $adPlatformId: " . $e->getMessage());
                    usleep(200000 * $retryCount);
                }
            }

            if (count($rows['data']) === 0) {
                $logger->info("No rows found for ad " . $adPlatformId);
                return false;
            }

            $metrics = FacebookMarketingMetricConvert::adMetrics(
                rows: $rows['data'],
                logger: $logger,
                channeledAccountEntity: $channeledAccountEntity,
                campaignEntity: $campaignEntity,
                channeledCampaignEntity: $channeledCampaignEntity,
                channeledAdGroupEntity: $channeledAdGroupEntity,
                channeledAdEntity: $channeledAdEntity,
                metricSet: MetricSet::KEY,
            );

            foreach ($metrics as $metric) {
                $metric->channeledAccount = $channeledAccountEntity;
                $metric->campaign = $campaignEntity;
                $metric->channeledCampaign = $channeledCampaignEntity;
                $metric->channeledAdGroup = $channeledAdGroupEntity;
                $metric->channeledAd = $channeledAdEntity;
                $allMetrics->add($metric);
            }

            // Helpers::dumpDebugJson($allMetrics->toArray());

            try {
                $manager->getConnection()->beginTransaction();

                // Map metric configs
                $metricConfigMap = MetricsProcessor::processMetricConfigs(
                    metrics: $allMetrics,
                    manager: $manager,
                    channeledAccountMap: $channeledAccountMap,
                    campaignMap: $campaignMap,
                    channeledCampaignMap: $channeledCampaignMap,
                    channeledAdGroupMap: $channeledAdGroupMap,
                    channeledAdMap: $channeledAdMap,
                );

                // Helpers::dumpDebugJson($metricConfigMap);

                // Map metrics
                $metricMap = MetricsProcessor::processMetrics(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricConfigMap: $metricConfigMap,
                );

                // Map channeled metrics
                $channeledMetricMap = MetricsProcessor::processChanneledMetrics(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricMap: $metricMap,
                    logger: $logger,
                );

                // Map dimensions
                $manager->getConnection()->commit();
            } catch (Exception $e) {
                if ($manager->getConnection()->isTransactionActive()) {
                    $manager->getConnection()->rollback();
                }
                throw $e;
            }

            $logger->info("Completed Meta ad account's campaign's adset's ad insights request");

            return true;
        } catch (Exception $e) {
            $logger->error("Error during Meta ad account's campaign's adset's ad insights request for ad " . $channeledAdEntity->getPlatformId() . ": " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Processes a single site, including page lookup and data fetching.
     *
     * @param array $page
     * @param FacebookGraphApi $api
     * @param EntityManager $manager
     * @param LoggerInterface $logger
     * @param Page $pageEntity
     * @param Account $accountEntity
     * @return array
     * @throws GuzzleException
     * @throws \Doctrine\DBAL\Exception
     * @throws Exception
     */
    private static function getPostMap(
        EntityManager $manager,
        Page $pageEntity,
    ): array {
        $sql = "SELECT id, post_id FROM posts WHERE page_id = ? AND channeled_account_id IS NULL";
        $fetched = $manager->getConnection()->executeQuery($sql, [$pageEntity->getId()])->fetchAllAssociative();
        $map = [];
        foreach ($fetched as $row) {
            $map[$row['post_id']] = (int)$row['id'];
        }
        return [
            'map' => $map,
            'mapReverse' => array_flip($map),
        ];
    }

    private static function getInstagramMediaMap(
        EntityManager $manager,
        Page $pageEntity,
        ?ChanneledAccount $channeledAccountEntity = null,
    ): array {
        if (!$channeledAccountEntity) {
             return ['map' => [], 'mapReverse' => []];
        }
        $sql = "SELECT id, post_id FROM posts WHERE page_id = ? AND channeled_account_id = ?";
        $fetched = $manager->getConnection()->executeQuery($sql, [$pageEntity->getId(), $channeledAccountEntity->getId()])->fetchAllAssociative();
        $map = [];
        foreach ($fetched as $row) {
            $map[$row['post_id']] = (int)$row['id'];
        }
        return [
            'map' => $map,
            'mapReverse' => array_flip($map),
        ];
    }
    private static function getCampaignMaps(
        EntityManager $manager,
        ChanneledAccount $channeledAccountEntity,
    ): array {
        $conn = $manager->getConnection();

        // 1. Campaign Map
        $sqlCampaign = "SELECT id, campaign_id FROM campaigns";
        $fetchedCampaigns = $conn->executeQuery($sqlCampaign)->fetchAllAssociative();
        $campaignMap = [];
        foreach ($fetchedCampaigns as $row) {
            $campaignMap[$row['campaign_id']] = (int)$row['id'];
        }

        // 2. Channeled Campaign Map
        $sqlCC = "SELECT id, platform_id FROM channeled_campaigns WHERE channeled_account_id = ?";
        $fetchedCC = $conn->executeQuery($sqlCC, [$channeledAccountEntity->getId()])->fetchAllAssociative();
        $channeledCampaignMap = [];
        foreach ($fetchedCC as $row) {
            $channeledCampaignMap[$row['platform_id']] = (int)$row['id'];
        }

        return [
            'campaignMap' => [
                'map' => $campaignMap,
                'mapReverse' => array_flip($campaignMap),
            ],
            'channeledCampaignMap' => [
                'map' => $channeledCampaignMap,
                'mapReverse' => array_flip($channeledCampaignMap),
            ],
        ];
    }

    private static function getAdGroupMap(
        EntityManager $manager,
        ChanneledAccount $channeledAccountEntity,
    ): array {
        $sql = "SELECT cag.id, cag.platform_id, cc.platform_id as campaign_platform_id 
                FROM channeled_ad_groups cag
                LEFT JOIN channeled_campaigns cc ON cag.channeled_campaign_id = cc.id
                WHERE cag.channeled_account_id = ?";
        $fetched = $manager->getConnection()->executeQuery($sql, [$channeledAccountEntity->getId()])->fetchAllAssociative();
        $map = [];
        $mapCampaign = [];
        foreach ($fetched as $row) {
            $map[$row['platform_id']] = (int)$row['id'];
            $mapCampaign[$row['platform_id']] = $row['campaign_platform_id'];
        }
        return [
            'map' => $map,
            'mapReverse' => array_flip($map),
            'mapCampaign' => $mapCampaign,
        ];
    }

    private static function getAdMap(
        EntityManager $manager,
        ChanneledAccount $channeledAccountEntity,
    ): array {
        $sql = "SELECT ca.id, ca.platform_id, cag.platform_id as ad_group_platform_id 
                FROM channeled_ads ca
                LEFT JOIN channeled_ad_groups cag ON ca.channeled_ad_group_id = cag.id
                WHERE ca.channeled_account_id = ?";
        $fetched = $manager->getConnection()->executeQuery($sql, [$channeledAccountEntity->getId()])->fetchAllAssociative();
        $map = [];
        $mapAdGroup = [];
        foreach ($fetched as $row) {
            $map[$row['platform_id']] = (int)$row['id'];
            $mapAdGroup[$row['platform_id']] = $row['ad_group_platform_id'];
        }
        return [
            'map' => $map,
            'mapReverse' => array_flip($map),
            'mapAdGroup' => $mapAdGroup,
        ];
    }

    private static function getCreativeMap(
        EntityManager $manager,
        ChanneledAccount $channeledAccountEntity,
    ): array {
        $sql = "SELECT DISTINCT c.id, c.creative_id 
                FROM creatives c
                JOIN channeled_ads ca ON ca.creative_id = c.id
                WHERE ca.channeled_account_id = ?";
        $fetched = $manager->getConnection()->executeQuery($sql, [$channeledAccountEntity->getId()])->fetchAllAssociative();
        $map = [];
        foreach ($fetched as $row) {
            $map[$row['creative_id']] = (int)$row['id'];
        }
        return [
            'map' => $map,
            'mapReverse' => array_flip($map),
        ];
    }


    /**
     * Processes a single site, including page lookup and data fetching.
     *
     * @param Post $postEntity
     * @param Page $pageEntity
     * @param FacebookGraphApi $api
     * @param EntityManager $manager
     * @param LoggerInterface $logger
     * @param array $postMap
     * @param array $pageMap
     * @return bool
     * @throws GuzzleException
     * @throws \Doctrine\DBAL\Exception
     */
    private static function processFacebookPagePost(
        Post $postEntity,
        Page $pageEntity,
        FacebookGraphApi $api,
        EntityManager $manager,
        LoggerInterface $logger,
        array $postMap,
        array $pageMap,
    ): bool {
        $allMetrics = new ArrayCollection();

        try {
            $maxRetries = 3;
            $retryCount = 0;
            $fetched = false;
            $rows = ['data' => []];

            while ($retryCount < $maxRetries && !$fetched) {
                try {
                    $rows = $api->getFacebookPostInsights(
                        postId: $postEntity->getPostId(),
                    );
                    $fetched = true;
                } catch (Exception $e) {
                    $retryCount++;
                    if ($retryCount >= $maxRetries) {
                        throw $e;
                    }
                    $logger->warning("Retry $retryCount/$maxRetries for FB post insights " . $postEntity->getPostId() . ": " . $e->getMessage());
                    usleep(200000 * $retryCount);
                }
            }

            if (count($rows['data']) === 0) {
                $logger->info("No rows found for post " . $postEntity->getPostId());
                return false;
            }

            $metrics = FacebookOrganicMetricConvert::pageMetrics(
                rows: $rows['data'],
                postPlatformId: $postEntity->getPostId(),
                logger: $logger,
                pageEntity: $pageEntity,
                postEntity: $postEntity,
                period: Period::Lifetime,
            );

            foreach ($metrics as $metric) {
                $metric->post = $postEntity;
                $metric->page = $pageEntity;
                $allMetrics->add($metric);
            }

            try {
                $manager->getConnection()->beginTransaction();

                // Map metrics
                $metricConfigMap = MetricsProcessor::processMetricConfigs(
                    metrics: $allMetrics,
                    manager: $manager,
                    pageMap: $pageMap,
                    postMap: $postMap,
                );

                // Map metrics
                $metricMap = MetricsProcessor::processMetrics(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricConfigMap: $metricConfigMap,
                );

                // Map channeled metrics
                $channeledMetricMap = MetricsProcessor::processChanneledMetrics(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricMap: $metricMap,
                    logger: $logger,
                );

                // Map dimensions
                $manager->getConnection()->commit();
            } catch (Exception $e) {
                if ($manager->getConnection()->isTransactionActive()) {
                    $manager->getConnection()->rollback();
                }
                throw $e;
            }

            $logger->info("Completed FB page insights request");

            return true;
        } catch (Exception $e) {
            $logger->error("Error during FB page post insights request for post " . $postEntity->getPostId() . ": " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Fetches and processes data for a single day.
     *
     * @param string $dayStr
     * @param array $site
     * @param SearchConsoleApi $api
     * @param EntityManager $manager
     * @param Page $pageEntity
     * @param array $metricNames
     * @param array $targetKeywords
     * @param array $targetCountries
     * @param array $dimensionFilterGroups
     * @param LoggerInterface $logger
     * @param array $deviceMap
     * @param array $countryMap
     * @param array $pageMap
     * @return array
     * @throws GuzzleException
     * @throws \Doctrine\DBAL\Exception
     */
    private static function fetchGSCDailyData(
        string $dayStr,
        array $site,
        SearchConsoleApi $api,
        EntityManager $manager,
        Page $pageEntity,
        array $metricNames,
        array $targetKeywords,
        array $targetCountries,
        array $dimensionFilterGroups,
        LoggerInterface $logger,
        array $deviceMap,
        array $countryMap,
        array $pageMap,
    ): array {
        $siteUrl = $site['url'];
        $siteKey = str_replace(['https://', 'sc-domain:', '/'], '', $siteUrl);
        $rowLimit = $site['rowLimit'] ?? 25000;
        // $logger->info("Processing GSC data for site $siteUrl, date $dayStr");

        // Initialize counters
        $totalMetrics = 0;
        $totalRows = 0;
        $totalDuplicates = 0;
        $allMetrics = new ArrayCollection();
        $allRows = [];
        $subsetRows = [];

        try {
            $dimensionsSubsets = Helpers::getAllSubsets(GoogleSearchConsoleHelpers::$optionalDimensions);
            // $dimensionsSubsets = [GoogleSearchConsoleConvert::$optionalDimensions];
            foreach ($dimensionsSubsets as $dimensionsSubset) {
                $actualDimensionsSubset = array_merge(array_diff(GoogleSearchConsoleHelpers::$allDimensions, GoogleSearchConsoleHelpers::$optionalDimensions), $dimensionsSubset);
                
                $maxRetries = 3;
                $retryCount = 0;
                $fetched = false;
                $rows = ['rows' => []];

                while ($retryCount < $maxRetries && !$fetched) {
                    try {
                        $rows = $api->getAllSearchQueryResults(
                            siteUrl: $siteUrl,
                            startDate: $dayStr,
                            endDate: $dayStr,
                            rowLimit: $rowLimit,
                            dimensions: $actualDimensionsSubset,
                            dimensionFilterGroups: $dimensionFilterGroups,
                        );
                        $fetched = true;
                    } catch (Exception $e) {
                        $retryCount++;
                        if ($retryCount >= $maxRetries) {
                            throw $e;
                        }
                        $logger->warning("Retry $retryCount/$maxRetries for GSC site $siteUrl insights: " . $e->getMessage());
                        usleep(200000 * $retryCount);
                    }
                }

                $subsetRows[] = [
                    'rows' => $rows['rows'],
                    'subset' => $actualDimensionsSubset,
                ];
            }

            if (count($subsetRows) === 0) {
                $logger->info("No rows found for site $siteUrl, date $dayStr");
                return [
                    'metrics' => 0,
                    'rows' => 0,
                    'duplicates' => 0
                ];
            }

            foreach ($subsetRows as $rows) {
                foreach ($rows as $row) {
                    foreach ($row as $element) {
                        if (is_string($element)) {
                            continue;
                        }
                        $element['subset'] = $rows['subset'];
                        $allRows[] = $element;
                    }
                }
            }

            if (count($allRows) === 0) {
                $logger->info("No rows found for site $siteUrl, date $dayStr");
                return [
                    'metrics' => 0,
                    'rows' => 0,
                    'duplicates' => 0
                ];
            }

            $finalRecords = GoogleSearchConsoleHelpers::getFinalRecords($allRows, $targetKeywords, $targetCountries);

            // Helpers::dumpDebugJson($finalRecords);

            $metrics = GoogleSearchConsoleConvert::metrics($finalRecords, $siteUrl, $siteKey, $logger, $pageEntity, $manager);
            // $logger->info("Converted " . count($rows) . " rows to " . count($pageMetrics) . " metrics, first metric: " . (count($pageMetrics) > 0 ? json_encode(['name' => $pageMetrics[0]->name, 'query' => is_string($pageMetrics[0]->query) ? $pageMetrics[0]->query : ($pageMetrics[0]->query instanceof Query ? $pageMetrics[0]->query->getQuery() : 'none')]) : 'none'));

            foreach ($metrics as $metric) {
                if ($metricNames && !in_array($metric->name, $metricNames)) {
                    $logger->warning("Skipped metric: =$metric->name, not in allowed names: " . json_encode($metricNames));
                    continue;
                }

                $countryEnum = CountryEnum::tryFrom($metric->countryCode) ?? CountryEnum::UNK;
                $metric->country = $countryMap['map'][$countryEnum->value];

                $deviceEnum = DeviceEnum::from($metric->deviceType);
                $metric->device = $deviceMap['map'][$deviceEnum->value];

                $allMetrics->add($metric);
            }

            if (count($allMetrics) === 0) {
                $logger->info("No metrics found for site $siteUrl, date $dayStr");
                return [
                    'metrics' => 0,
                    'rows' => 0,
                    'duplicates' => 0
                ];
            }

            try {
                $manager->getConnection()->beginTransaction();

                // Map metrics configs
                $metricConfigMap = MetricsProcessor::processMetricConfigs(
                    metrics: $allMetrics,
                    manager: $manager,
                    processQueries: true,
                    countryMap: $countryMap,
                    deviceMap: $deviceMap,
                    pageMap: $pageMap,
                );

                // Map metrics
                $metricMap = MetricsProcessor::processMetrics(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricConfigMap: $metricConfigMap,
                );

                // Map channeled metrics
                $channeledMetricMap = MetricsProcessor::processChanneledMetrics(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricMap: $metricMap,
                    logger: $logger,
                );

                // Map dimensions
                $manager->getConnection()->commit();
            } catch (Exception $e) {
                if ($manager->getConnection()->isTransactionActive()) {
                    $manager->getConnection()->rollback();
                }
                throw $e;
            }

            $logger->info("Completed API query for date=$dayStr, duplicates=$totalDuplicates");

            return [
                'metrics' => $totalMetrics,
                'rows' => $totalRows,
                'duplicates' => $totalDuplicates
            ];
        } catch (Exception $e) {
            $logger->error("Error during GSC API query for site $siteUrl, date $dayStr: " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Updates aggregated metric values for a specific day.
     *
     * @param EntityManager $manager
     * @param string $objectReference
     * @param string $dayStr
     * @param LoggerInterface $logger
     * @throws \Doctrine\DBAL\Exception
     */
    private static function updateGSCMetricsValues(EntityManager $manager, string $objectReference, string $dayStr, LoggerInterface $logger): void
    {
        try {
            $connection = $manager->getConnection();
            $isPostgres = Helpers::isPostgres();
            if ($isPostgres) {
                $sql = "
                    UPDATE metrics m
                    SET value = CASE cm_agg.name
                        WHEN 'impressions' THEN GREATEST(COALESCE(m.value, 0), COALESCE(cm_agg.total_impressions, 0))
                        WHEN 'clicks' THEN GREATEST(COALESCE(m.value, 0), COALESCE(cm_agg.total_clicks, 0))
                        WHEN 'ctr' THEN GREATEST(COALESCE(m.value, 0), COALESCE(cm_agg.total_ctr, 0))
                        WHEN 'position' THEN CASE WHEN cm_agg.total_impressions > 0 THEN cm_agg.total_position_weighted / cm_agg.total_impressions ELSE 0 END
                        ELSE COALESCE(m.value, 0)
                    END
                    FROM (
                        SELECT 
                            cm.metric_id,
                            mc.name,
                            COALESCE(SUM((cm.data->>'impressions')::numeric), 0) as total_impressions,
                            COALESCE(SUM((cm.data->>'clicks')::numeric), 0) as total_clicks,
                            COALESCE(SUM((cm.data->>'position_weighted')::numeric), 0) as total_position_weighted,
                            COALESCE(SUM((cm.data->>'ctr')::numeric), 0) as total_ctr
                        FROM channeled_metrics cm
                        JOIN metrics m2 ON cm.metric_id = m2.id
                        JOIN metric_configs mc ON mc.id = m2.metric_config_id
                        WHERE cm.channel = :channel
                        AND cm.platform_created_at::text LIKE :date
                        GROUP BY cm.metric_id, mc.name
                    ) AS cm_agg
                    JOIN metric_configs mc2 ON mc2.id = m.metric_config_id
                    WHERE m.id = cm_agg.metric_id
                    AND mc2.channel = :channel
                ";
            } else {
                $sql = "
                    UPDATE metrics m
                    JOIN metric_configs mc ON mc.id = m.metric_config_id
                    JOIN (
                        SELECT 
                            cm.metric_id,
                            mc.name,
                            COALESCE(SUM(JSON_EXTRACT(cm.data, '$.impressions')), 0) as total_impressions,
                            COALESCE(SUM(JSON_EXTRACT(cm.data, '$.clicks')), 0) as total_clicks,
                            COALESCE(SUM(JSON_EXTRACT(cm.data, '$.position_weighted')), 0) as total_position_weighted,
                            COALESCE(SUM(JSON_EXTRACT(cm.data, '$.ctr')), 0) as total_ctr
                        FROM channeled_metrics cm
                        JOIN metrics m ON cm.metric_id = m.id
                        JOIN metric_configs mc ON mc.id = m.metric_config_id
                        WHERE cm.channel = :channel
                        AND cm.platform_created_at LIKE :date
                        GROUP BY cm.metric_id, mc.name
                    ) cm_agg ON m.id = cm_agg.metric_id
                    SET m.value = CASE cm_agg.name
                        WHEN 'impressions' THEN GREATEST(COALESCE(m.value, 0), COALESCE(cm_agg.total_impressions, 0))
                        WHEN 'clicks' THEN GREATEST(COALESCE(m.value, 0), COALESCE(cm_agg.total_clicks, 0))
                        WHEN 'ctr' THEN GREATEST(COALESCE(m.value, 0), COALESCE(cm_agg.total_ctr, 0))
                        WHEN 'position' THEN IF(cm_agg.total_impressions > 0, cm_agg.total_position_weighted / cm_agg.total_impressions, 0)
                        ELSE COALESCE(m.value, 0)
                    END
                    WHERE mc.channel = :channel
                ";
            }
            $connection->executeStatement($sql, [
                'channel' => Channel::google_search_console->value,
                'date' => $dayStr . '%'
            ]);
        } catch (\Doctrine\DBAL\Exception $e) {
            $logger->error("Error updating metrics values for $objectReference, date $dayStr: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Finalizes the transaction and invalidates cache.
     *
     * @param int $totalMetrics
     * @param int $totalRows
     * @param int $totalDuplicates
     * @param LoggerInterface $logger
     * @param string|null $startDate
     * @param string|null $endDate
     * @return Response
     */
    private static function finalizeTransaction(
        int $totalMetrics,
        int $totalRows,
        int $totalDuplicates,
        LoggerInterface $logger,
        ?string $startDate,
        ?string $endDate,
        array $entitiesToInvalidate = [],
        ?Channel $channel = null
    ): Response {
        $logger->info("Completed: metrics=$totalMetrics, rows=$totalRows, duplicates=$totalDuplicates");

        if (!empty($entitiesToInvalidate)) {
            $cacheService = CacheService::getInstance(Helpers::getRedisClient());
            foreach ($entitiesToInvalidate as $entity => $ids) {
                if (!empty($ids)) {
                    $cacheService->invalidateEntityCache(
                        entity: $entity,
                        ids: array_unique($ids),
                        channel: $channel ? $channel->getName() : Channel::google_search_console->getName()
                    );
                }
            }
        }

        $from = $startDate ?? 'unknown';
        $to = $endDate ?? 'unknown';
        $logger->info("Fetched and processed $totalMetrics metrics from $totalRows rows for all sites from $from to $to");

        return new Response(json_encode(['Metrics retrieved']));
    }

    /**
     * Processes a batch of channeled metrics.
     *
     * @param array $batch
     * @param EntityManager $manager
     * @param array $repos
     * @param array $entitiesToInvalidate
     * @param array $queryCache
     * @param array $metricCache
     * @param array $channeledMetricCache
     * @param array $dimensionCache
     * @param LoggerInterface $logger
     * @param Page|null $pageEntity
     * @throws ORMException
     * @throws OptimisticLockException|Exception
     */
    private static function processBatch(
        array $batch,
        EntityManager $manager,
        array $repos,
        array &$entitiesToInvalidate,
        array &$queryCache,
        array &$metricCache,
        array &$channeledMetricCache,
        array &$dimensionCache,
        LoggerInterface $logger,
        ?Page $pageEntity = null
    ): void {
        $logger->info("Processing batch with " . count($batch) . " metrics");

        $retryCount = 0;
        $maxRetries = 3;

        while ($retryCount < $maxRetries) {
            try {
                self::persistPageEntity($manager, $pageEntity, $logger);

                $entitiesToPersist = ['metrics' => [], 'channeledMetrics' => [], 'dimensions' => []];
                $skippedMetrics = 0;
                $metricsCount = 0;
                $channeledMetricsCount = 0;
                $dimensionsCount = 0;

                foreach ($batch as $index => $metric) {
                    if (empty($metric->name)) {
                        $logger->warning("Skipping metric at index $index: missing name");
                        $skippedMetrics++;
                        continue;
                    }

                    self::processSingleMetric(
                        $index,
                        $metric,
                        $manager,
                        $repos,
                        $pageEntity,
                        $entitiesToPersist,
                        $entitiesToInvalidate,
                        $queryCache,
                        $metricCache,
                        $channeledMetricCache,
                        $dimensionCache,
                        $logger,
                        $metricsCount,
                        $channeledMetricsCount,
                        $dimensionsCount
                    );
                }

                $logger->info("Entities queued for persistence: metrics=$metricsCount, channeledMetrics=$channeledMetricsCount, dimensions=$dimensionsCount, skipped=$skippedMetrics");

                self::persistEntities($manager, $entitiesToPersist, $metricsCount, $channeledMetricsCount, $dimensionsCount, $logger);

                break;
            } catch (Exception $e) {
                $retryCount = self::handleBatchRetry($retryCount, $maxRetries, $e, $logger);
            }
        }
    }

    /**
     * Persists the page entity, ensuring it is managed.
     *
     * @param EntityManager $manager
     * @param Page|null $pageEntity
     * @param LoggerInterface $logger
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    private static function persistPageEntity(EntityManager $manager, ?Page $pageEntity, LoggerInterface $logger): void
    {
        if (!$manager->isOpen()) {
            $logger->error("EntityManager closed in processBatch");
            throw new RuntimeException("EntityManager closed in processBatch");
        }

        if ($pageEntity && $pageEntity->getId()) {
            if (!$manager->contains($pageEntity)) {
                $pageId = $pageEntity->getId();
                $logger->warning("Page entity detached: ID=" . $pageId . ", URL=" . $pageEntity->getUrl());
                $pageEntity = $manager->find(Page::class, $pageId);
                if (!$pageEntity) {
                    $logger->error("Failed to reattach Page entity: ID=" . $pageId);
                    throw new RuntimeException("Failed to reattach Page entity");
                }
            }
            $manager->persist($pageEntity);
            $logger->info("Persisted Page entity: ID=" . $pageEntity->getId());
        }
    }

    /**
     * Processes a single metric, including get/create and dimension handling.
     *
     * @param int $index
     * @param object $metric
     * @param EntityManager $manager
     * @param array $repos
     * @param Page|null $pageEntity
     * @param array &$entitiesToPersist
     * @param array &$entitiesToInvalidate
     * @param array &$queryCache
     * @param array &$metricCache
     * @param array &$channeledMetricCache
     * @param array &$dimensionCache
     * @param LoggerInterface $logger
     * @param int &$metricsCount
     * @param int &$channeledMetricsCount
     * @param int &$dimensionsCount
     * @throws ORMException
     */
    private static function processSingleMetric(
        int $index,
        object $metric,
        EntityManager $manager,
        array $repos,
        ?Page $pageEntity,
        array &$entitiesToPersist,
        array &$entitiesToInvalidate,
        array &$queryCache,
        array &$metricCache,
        array &$channeledMetricCache,
        array &$dimensionCache,
        LoggerInterface $logger,
        int &$metricsCount,
        int &$channeledMetricsCount,
        int &$dimensionsCount
    ): void {
        try {
            // Validate metric name
            $queryString = is_string($metric->query) ? $metric->query : ($metric->query instanceof Query ? $metric->query->getQuery() : 'none');
            if ($metric->page instanceof Page && $metric->page !== $pageEntity) {
                $logger->warning("Metric page mismatch at index $index: query=$queryString, name={$metric->name}");
                $metric->page = $pageEntity;
            }

            // Get or create the metric entity
            $metricEntity = self::getOrCreateMetric(
                metric: $metric,
                repository: $repos['metric'],
                queryRepository: $repos['query'],
                logger: $logger,
                queryCache: $queryCache,
                metricCache: $metricCache,
                pageEntity: $pageEntity,
                em: $manager
            );

            // Check if the metric entity is managed
            if (!$manager->contains($metricEntity)) {
                $metricId = $metricEntity->getId();
                $logger->warning("Metric entity detached: ID=" . $metricId . ", name={$metric->name}");
                $metricEntity = $manager->find(Metric::class, $metricId);
                if (!$metricEntity) {
                    $logger->error("Failed to reattach Metric entity: ID=" . $metricId);
                    throw new RuntimeException("Failed to reattach Metric entity");
                }
            }

            // Persist the metric entity
            $manager->persist($metricEntity);
            $entitiesToPersist['metrics'][] = $metricEntity;
            $metricsCount++;
            $logger->info("Metric " . ($metricEntity->getId() ? "found" : "created") . ": ID=" . $metricEntity->getId() . ", name={$metric->name}");

            // Get or create the channeled metric entity
            $channeledMetricEntity = self::getOrCreateChanneledMetric(
                metricEntity: $metricEntity,
                channeledMetric: $metric,
                manager: $manager,
                repository: $repos['channeledMetric'],
                logger: $logger,
                channeledMetricCache: $channeledMetricCache,
                dimensionCache: $dimensionCache
            );

            // Check if the channeled metric entity is managed
            if (!$manager->contains($channeledMetricEntity)) {
                $channeledMetricId = $channeledMetricEntity->getId();
                $logger->warning("ChanneledMetric entity detached: ID=" . $channeledMetricId);
                $channeledMetricEntity = $manager->find(ChanneledMetric::class, $channeledMetricId);
                if (!$channeledMetricEntity) {
                    $logger->error("Failed to reattach ChanneledMetric entity: ID=" . $channeledMetricId);
                    throw new RuntimeException("Failed to reattach ChanneledMetric entity");
                }
            }

            // Persist the channeled metric entity
            $manager->persist($channeledMetricEntity);
            $entitiesToPersist['channeledMetrics'][] = $channeledMetricEntity;
            $channeledMetricsCount++;
            $logger->info("ChanneledMetric " . ($channeledMetricEntity->getId() ? "found" : "created") . ": ID=" . $channeledMetricEntity->getId());

            // Handle dimensions
            if (isset($metric->dimensions)) {
                foreach ($metric->dimensions as $dimension) {
                    if (isset($dimension['dimensionKey'], $dimension['dimensionValue'])) {
                        $dimCacheKey = md5($channeledMetricEntity->getId() . $dimension['dimensionKey'] . $dimension['dimensionValue']);
                        if (!isset($dimensionCache[$dimCacheKey])) {
                            $entitiesToPersist['dimensions'][] = $dimension;
                            $dimensionsCount++;
                        }
                    }
                }
            }

            // Invalidate caches
            $entitiesToInvalidate['metric'][] = $metricEntity->getId();
            $entitiesToInvalidate['channeledMetric'][] = $channeledMetricEntity->getId();
            if ($metricEntity->getMetricConfig()->getQuery()) {
                $query = $metricEntity->getMetricConfig()->getQuery();
                if (!$manager->contains($query)) {
                    $queryId = $query->getId();
                    $logger->warning("Query entity detached: ID=" . $queryId);
                    $query = $manager->find(Query::class, $queryId);
                    if (!$query) {
                        $logger->error("Failed to reattach Query entity: ID=" . $queryId);
                        throw new RuntimeException("Failed to reattach Query entity");
                    }
                    $metricEntity->getMetricConfig()->addQuery($query);
                }
                $entitiesToInvalidate['query'][] = $query->getId();
            }
        } catch (ORMException $e) {
            $logger->error("Database error processing metric at index $index, query=$queryString: " . $e->getMessage());
            throw $e;
        } catch (Exception $e) {
            $logger->error("Error processing metric at index $index, query=$queryString: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Persists all entities and flushes the batch.
     *
     * @param EntityManager $manager
     * @param array $entitiesToPersist
     * @param int $metricsCount
     * @param int $channeledMetricsCount
     * @param int $dimensionsCount
     * @param LoggerInterface $logger
     * @throws ORMException
     */
    private static function persistEntities(
        EntityManager $manager,
        array $entitiesToPersist,
        int $metricsCount,
        int $channeledMetricsCount,
        int $dimensionsCount,
        LoggerInterface $logger
    ): void {
        try {
            $uow = $manager->getUnitOfWork();
            $metricManaged = $metricsCount > 0 ? ($manager->contains($entitiesToPersist['metrics'][0] ?? null) ? 'yes' : 'no') : 'none';
            $channeledMetricManaged = $channeledMetricsCount > 0 ? ($manager->contains($entitiesToPersist['channeledMetrics'][0] ?? null) ? 'yes' : 'no') : 'none';
            $logger->info("Entity management before flush: Metric managed=$metricManaged, ChanneledMetric managed=$channeledMetricManaged");
            $scheduledInserts = count($uow->getScheduledEntityInsertions());
            $scheduledUpdates = count($uow->getScheduledEntityUpdates());
            $logger->info("Scheduled before flush: inserts=$scheduledInserts, updates=$scheduledUpdates");

            $manager->flush();
            $logger->info("Flushed batch: inserts=$scheduledInserts, updates=$scheduledUpdates");
            $logger->info("Transaction active after flush: " . ($manager->getConnection()->isTransactionActive() ? 'yes' : 'no'));
            $logger->info("Committed batch with $metricsCount metrics");
        } catch (ORMException $e) {
            $logger->error("Flush failed: metrics=$metricsCount, channeledMetrics=$channeledMetricsCount, dimensions=$dimensionsCount, error=" . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Handles retry logic for batch processing errors.
     *
     * @param int $retryCount
     * @param int $maxRetries
     * @param Exception $e
     * @param LoggerInterface $logger
     * @return int
     * @throws Exception
     */
    private static function handleBatchRetry(
        int $retryCount,
        int $maxRetries,
        Exception $e,
        LoggerInterface $logger
    ): int {
        if ($retryCount < $maxRetries - 1) {
            $retryCount++;
            $logger->error("Processing retry $retryCount/$maxRetries: " . $e->getMessage());
            usleep(100000 * $retryCount);
            return $retryCount;
        }
        $logger->error("Failed after $maxRetries retries: " . $e->getMessage());
        throw $e;
    }

    /**
     * Processes a collection of channeled metrics.
     *
     * @param ArrayCollection $channeledCollection
     * @param LoggerInterface|null $logger
     * @return Response
     * @throws MappingException
     * @throws NotSupported
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws \Doctrine\DBAL\Exception
     */
    public static function process(ArrayCollection $channeledCollection, ?LoggerInterface $logger = null): Response
    {
        $manager = Helpers::getManager();
        $repos = self::initializeRepositories($manager);
        $cacheService = CacheService::getInstance(Helpers::getRedisClient());
        $batchSize = 500;
        $batch = [];
        $entitiesToInvalidate = ['metric' => [], 'channeledMetric' => [], 'query' => []];
        $queryCache = [];
        $metricCache = [];
        $channeledMetricCache = [];
        $dimensionCache = [];

        if (!$logger) {
            $logger = Helpers::setLogger('gsc.log');
        }
        $logger->info("Starting process with " . $channeledCollection->count() . " metrics");

        $manager->beginTransaction();
        try {
            $batchCount = 0;
            foreach ($channeledCollection as $channeledMetric) {
                $batch[] = $channeledMetric;
                if (count($batch) >= $batchSize) {
                    $batchCount++;
                    $batchStart = microtime(true);
                    $logger->info("Processing batch " . $batchCount . " (" . count($batch) . " records)");
                    Helpers::reconnectIfNeeded($manager);
                    self::processBatch($batch, $manager, $repos, $entitiesToInvalidate, $queryCache, $metricCache, $channeledMetricCache, $dimensionCache, $logger);
                    $batchTime = microtime(true) - $batchStart;
                    $logger->info("Completed batch " . $batchCount . ", took " . $batchTime . " seconds");
                    $batch = [];
                    $manager->flush();
                    $manager->clear(Metric::class);
                    $manager->clear(ChanneledMetric::class);
                    $manager->clear(Query::class);
                    gc_collect_cycles();
                }
            }

            if (!empty($batch)) {
                $batchCount++;
                $batchStart = microtime(true);
                $logger->info("Processing final batch " . $batchCount . " (" . count($batch) . " records)");
                Helpers::reconnectIfNeeded($manager);
                self::processBatch($batch, $manager, $repos, $entitiesToInvalidate, $queryCache, $metricCache, $channeledMetricCache, $dimensionCache, $logger);
                $batchTime = microtime(true) - $batchStart;
                $logger->info("Completed final batch " . $batchCount . ", took " . $batchTime . " seconds");
                $manager->flush();
                $manager->clear(Metric::class);
                $manager->clear(ChanneledMetric::class);
                $manager->clear(Query::class);
                gc_collect_cycles();
            }

            $logger->info("Updating metrics values");
            $connection = $manager->getConnection();
            $isPostgres = Helpers::isPostgres();
            if ($isPostgres) {
                $sql = "
                    UPDATE metrics m
                    SET value = CASE cm_agg.name
                        WHEN 'impressions' THEN cm_agg.total_impressions
                        WHEN 'clicks' THEN cm_agg.total_clicks
                        WHEN 'ctr' THEN CASE WHEN cm_agg.total_impressions > 0 THEN cm_agg.total_clicks::numeric / cm_agg.total_impressions ELSE 0 END
                        WHEN 'position' THEN CASE WHEN cm_agg.total_impressions > 0 THEN cm_agg.total_position_weighted::numeric / cm_agg.total_impressions ELSE 0 END
                        ELSE m.value
                    END
                    FROM (
                        SELECT 
                            cm.metric_id,
                            mc.name,
                            mc.channel,
                            SUM((cm.data->>'impressions')::numeric) as total_impressions,
                            SUM((cm.data->>'clicks')::numeric) as total_clicks,
                            SUM((cm.data->>'position_weighted')::numeric) as total_position_weighted
                        FROM channeled_metrics cm
                        JOIN metrics m2 ON cm.metric_id = m2.id
                        JOIN metric_configs mc ON m2.metric_config_id = mc.id
                        WHERE cm.channel = :channel
                        GROUP BY cm.metric_id, mc.name, mc.channel
                    ) AS cm_agg
                    JOIN metric_configs mc2 ON m.metric_config_id = mc2.id
                    WHERE m.id = cm_agg.metric_id
                    AND mc2.channel = :channel
                ";
            } else {
                $sql = "
                    UPDATE metrics m
                    JOIN (
                        SELECT 
                            cm.metric_id,
                            mc.name,
                            mc.channel,
                            SUM(JSON_EXTRACT(cm.data, '$.impressions')) as total_impressions,
                            SUM(JSON_EXTRACT(cm.data, '$.clicks')) as total_clicks,
                            SUM(JSON_EXTRACT(cm.data, '$.position_weighted')) as total_position_weighted
                        FROM channeled_metrics cm
                        JOIN metrics m ON cm.metric_id = m.id
                        JOIN metric_configs mc ON m.metric_config_id = mc.id
                        WHERE cm.channel = :channel
                        GROUP BY cm.metric_id, mc.name
                    ) cm_agg ON m.id = cm_agg.metric_id
                    SET m.value = CASE cm_agg.name
                        WHEN 'impressions' THEN cm_agg.total_impressions
                        WHEN 'clicks' THEN cm_agg.total_clicks
                        WHEN 'ctr' THEN IF(cm_agg.total_impressions > 0, cm_agg.total_clicks / cm_agg.total_impressions, 0)
                        WHEN 'position' THEN IF(cm_agg.total_impressions > 0, cm_agg.total_position_weighted / cm_agg.total_impressions, 0)
                        ELSE m.value
                    END
                    WHERE cm_agg.channel = :channel
                ";
            }
            $connection->executeStatement($sql, ['channel' => Channel::google_search_console->value]);

            $logger->info("Invalidating cache for " . count($entitiesToInvalidate['metric']) . " metrics, " . count($entitiesToInvalidate['channeledMetric']) . " channeled metrics, " . count($entitiesToInvalidate['query']) . " queries");
            foreach ($entitiesToInvalidate as $entity => $ids) {
                if (!empty($ids)) {
                    $cacheService->invalidateEntityCache(
                        entity: $entity,
                        ids: array_unique($ids),
                        channel: Channel::google_search_console->getName()
                    );
                }
            }

            $manager->commit();
            $logger->info("Process completed");
        } catch (ORMException | \Doctrine\DBAL\Exception $e) {
            $manager->rollback();
            $logger->error("Database error in process: " . $e->getMessage());
            throw $e;
        } catch (Exception $e) {
            $manager->rollback();
            $logger->error("Error in process: " . $e->getMessage());
            throw $e;
        }

        return new Response(json_encode(['Metrics processed']));
    }

    /**
     * Initializes repositories for Metric, ChanneledMetric, and ChanneledMetricDimension.
     *
     * @param EntityManager $manager
     * @return array
     * @throws NotSupported
     */
    private static function initializeRepositories(EntityManager $manager): array
    {
        return [
            'metric' => $manager->getRepository(Metric::class),
            'channeledMetric' => $manager->getRepository(ChanneledMetric::class),
            'query' => $manager->getRepository(Query::class),
        ];
    }

    /**
     * Gets or creates a Metric entity based on unique constraints.
     *
     * @param object $metric
     * @param MetricRepository $repository
     * @param QueryRepository $queryRepository
     * @param LoggerInterface $logger
     * @param array $queryCache
     * @param array $metricCache
     * @param Page|null $pageEntity
     * @param EntityManager|null $em
     * @return Metric
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws Exception
     */
    private static function getOrCreateMetric(
        object $metric,
        MetricRepository $repository,
        QueryRepository $queryRepository,
        LoggerInterface $logger,
        array &$queryCache = [],
        array &$metricCache = [],
        ?Page $pageEntity = null,
        ?EntityManager $em = null
    ): Metric {
        // Log the metric details
        $queryString = is_string($metric->query) ? strtolower(trim($metric->query)) : ($metric->query instanceof Query ? strtolower(trim($metric->query->getQuery())) : 'none');
        $logger->info("Entering getOrCreateMetric: metricName=$metric->name, query=$queryString, metricDate={$metric->metricDate->format('Y-m-d')}");

        // Validate metric page
        if ($em && $metric->page instanceof Page && $metric->page !== $pageEntity) {
            $logger->warning("Metric page mismatch: query=$queryString, name=$metric->name");
            $metric->page = $pageEntity;
        }

        // Validate metric value
        if (!isset($metric->value)) {
            $logger->warning("Metric value is NULL or unset for name=$metric->name, query=$queryString, defaulting to 0.0");
            $metric->value = 0.0;
        }

        $retryCount = 0;
        $maxRetries = 3;
        while ($retryCount < $maxRetries) {
            try {
                // Check if the query is already cached
                list($queryEntity, $queryString, $queryCache) = self::checkIfTheQueryIsAlreadyCached(
                    $metric,
                    $queryString,
                    $logger,
                    $queryCache,
                    $queryRepository,
                    $em
                );

                // Normalize dimensions
                $normalizedDimensions = self::getNormalizedDimensions($metric);

                // Log the attempt to find or create the metric
                $logger->info("Attempting to find Metric with criteria: " . json_encode([
                        'channel' => $metric->channel,
                        'name' => $metric->name,
                        'period' => $metric->period,
                        'metricDate' => $metric->metricDate->format('Y-m-d'),
                        'pageId' => $pageEntity?->getId(),
                        'queryId' => $queryEntity ? $queryEntity->getId() : null,
                        'countryId' => $metric->country ? $metric->country->getId() : null,
                        'deviceId' => $metric->device ? $metric->device->getId() : null
                    ], JSON_UNESCAPED_UNICODE));

                // Create a unique key for the metric
                $metricKey = md5(json_encode([
                    'channel' => $metric->channel,
                    'name' => $metric->name,
                    'period' => $metric->period,
                    'metricDate' => $metric->metricDate->format('Y-m-d'),
                    'queryId' => $queryEntity ? $queryEntity->getId() : null,
                    'pageId' => $pageEntity?->getId(),
                    'countryId' => $metric->country ? $metric->country->getId() : null,
                    'deviceId' => $metric->device ? $metric->device->getId() : null,
                    'queryString' => $queryString,
                    'dimensions' => $normalizedDimensions
                ], JSON_UNESCAPED_UNICODE));
                // Log the metric key for debugging
                if (isset($metricCache[$metricKey])) {
                    $logger->info("Metric found in cache: ID=" . $metricCache[$metricKey]->getId() . ", name=$metric->name, query=$queryString");
                    return $metricCache[$metricKey];
                }

                // Check if the metric already exists in the database
                $criteria = [
                    'channel' => $metric->channel,
                    'name' => $metric->name,
                    'period' => $metric->period,
                    'metricDate' => $metric->metricDate,
                    'page' => $pageEntity instanceof Page && $pageEntity->getId() ? $pageEntity : null,
                    'query' => $queryEntity,
                    'country' => $metric->country,
                    'device' => $metric->device,
                    'account' => null,
                    'channeledAccount' => null,
                    'campaign' => null,
                    'channeledCampaign' => null,
                    'channeledAdGroup' => null,
                    'channeledAd' => null,
                    'post' => null,
                    'product' => null,
                    'customer' => null,
                    'order' => null
                ];
                /** @var Metric|null $metricEntity */
                $metricEntity = $repository->findOneBy($criteria);
                if ($metricEntity) {
                    $logger->info("Existing Metric found in database: ID=" . $metricEntity->getId() . ", name=$metric->name, query=$queryString");
                    if ($em && !$em->contains($metricEntity)) {
                        $metricId = $metricEntity->getId();
                        $logger->warning("Metric entity detached: ID=" . $metricId . ", name=$metric->name");
                        $metricEntity = $em->find(Metric::class, $metricId);
                        if (!$metricEntity) {
                            $logger->error("Failed to reattach Metric: ID=" . $metricId . ", name=$metric->name");
                            throw new Exception("Failed to reattach Metric ID=" . $metricId);
                        }
                    }
                    $metricCache[$metricKey] = $metricEntity;
                    return $metricEntity;
                }

                // If not found, create a new Metric entity
                $logger->info("Creating new Metric for $metric->name, query=$queryString");
                $metric->query = $queryEntity;
                try {
                    /** @var Metric $metricEntity */
                    $metricEntity = $repository->create(
                        (object) [
                            'channel' => $metric->channel,
                            'name' => $metric->name,
                            'period' => $metric->period,
                            'metricDate' => $metric->metricDate,
                            'query' => $queryEntity,
                            'page' => $pageEntity instanceof Page && $pageEntity->getId() ? $pageEntity : null,
                            'country' => $metric->country,
                            'device' => $metric->device,
                            'value' => $metric->value ?: 0.0, // Ensure non-NULL
                            'metadata' => $metric->metadata ?? []
                        ],
                        true
                    );
                    if (!$metricEntity->getId()) {
                        $logger->error("Metric entity created but has no ID: name=$metric->name, query=$queryString");
                    }
                    if ($em && !$em->contains($metricEntity)) {
                        $metricId = $metricEntity->getId();
                        $logger->warning("Metric entity detached after creation: ID=" . $metricId . ", name=$metric->name");
                        $metricEntity = $em->find(Metric::class, $metricId);
                        if (!$metricEntity) {
                            $logger->error("Failed to reattach Metric after creation: ID=" . $metricId . ", name=$metric->name");
                            throw new Exception("Failed to reattach Metric ID=" . $metricId);
                        }
                    }
                    $logger->info("Created new Metric: id={$metricEntity->getId()}, queryId=" . ($queryEntity ? $queryEntity->getId() : 'none'));
                    $metricCache[$metricKey] = $metricEntity;
                    return $metricEntity;
                } catch (ORMException $e) {
                    if (str_contains($e->getMessage(), 'SQLSTATE[23000]')) {
                        $logger->warning("Duplicate metric for $metric->name, query=$queryString, retrying lookup");
                        /** @var Metric|null $metricEntity */
                        $metricEntity = $repository->findOneBy($criteria);
                        if ($metricEntity) {
                            $logger->info("Existing Metric found on retry: id={$metricEntity->getId()}");
                            if ($em && !$em->contains($metricEntity)) {
                                $metricId = $metricEntity->getId();
                                $logger->warning("Metric entity detached on retry: ID=" . $metricId . ", name=$metric->name");
                                $metricEntity = $em->find(Metric::class, $metricId);
                                if (!$metricEntity) {
                                    $logger->error("Failed to reattach Metric on retry: ID=" . $metricId . ", name=$metric->name");
                                    throw new Exception("Failed to reattach Metric ID=" . $metricId);
                                }
                            }
                            $metricCache[$metricKey] = $metricEntity;
                            return $metricEntity;
                        }
                        $logger->error("Failed to find or create Metric for $metric->name, query=$queryString: " . $e->getMessage());
                        throw new Exception("Failed to find or create Metric for $metric->name, query=$queryString");
                    }
                    $logger->error("Database error creating Metric for $metric->name, query=$queryString: " . $e->getMessage());
                    throw $e;
                }
            } catch (OptimisticLockException $e) {
                if ($retryCount < $maxRetries - 1) {
                    $retryCount++;
                    usleep(100000 * $retryCount);
                    $logger->error("getOrCreateMetric retry $retryCount/$maxRetries due to OptimisticLockException: " . $e->getMessage());
                    continue;
                }
                $logger->error("getOrCreateMetric failed after $maxRetries retries due to OptimisticLockException: " . $e->getMessage());
                throw $e;
            } catch (ORMException $e) {
                if (str_contains($e->getMessage(), 'SQLSTATE[23000]') && $retryCount < $maxRetries - 1) {
                    $retryCount++;
                    usleep(100000 * $retryCount);
                    $logger->error("getOrCreateMetric retry $retryCount/$maxRetries due to duplicate key: " . $e->getMessage());
                    continue;
                }
                $logger->error("getOrCreateMetric failed after $maxRetries retries due to ORMException: " . $e->getMessage());
                throw $e;
            } catch (Exception $e) {
                $logger->error("getOrCreateMetric error for query: $queryString: " . $e->getMessage());
                throw $e;
            }
        }
        $logger->error("getOrCreateMetric failed after $maxRetries retries for query: $queryString");
        throw new Exception("getOrCreateMetric failed after $maxRetries retries for query: $queryString");
    }

    /**
     * Gets or creates a ChanneledMetric entity based on platformId and channel.
     *
     * @param Metric $metricEntity
     * @param object $channeledMetric
     * @param EntityManager $manager
     * @param ChanneledMetricRepository $repository
     * @param LoggerInterface $logger
     * @param array $channeledMetricCache
     * @param array $dimensionCache
     * @return ChanneledMetric
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws Exception
     */
    private static function getOrCreateChanneledMetric(
        Metric $metricEntity,
        object $channeledMetric,
        EntityManager $manager,
        ChanneledMetricRepository $repository,
        LoggerInterface $logger,
        array &$channeledMetricCache = [],
        array &$dimensionCache = []
    ): ChanneledMetric {
        // Validate channeledMetric properties
        $logger->info("Entering getOrCreateChanneledMetric: platformId=" . ($channeledMetric->platformId ?? 'null') . ", metricId={$metricEntity->getId()}");
        $channeledMetric->metric = $metricEntity;

        // Ensure platformCreatedAt is a DateTime
        $platformCreatedAt = $channeledMetric->platformCreatedAt instanceof DateTime
            ? $channeledMetric->platformCreatedAt
            : new DateTime($channeledMetric->platformCreatedAt ?? 'now');

        // Compute cache key
        $cacheKey = md5(json_encode([
            'platformId' => $channeledMetric->platformId ?? 'none', // Fallback for null
            'channel' => $channeledMetric->channel ?? 8, // Default to GSC channel
            'metricId' => $metricEntity->getId(),
            'platformCreatedAt' => $platformCreatedAt->format('Y-m-d')
        ], JSON_UNESCAPED_UNICODE));
        $logger->info("Computed cacheKey: $cacheKey");

        // Check if channeled metric is already cached
        $dimensionsToPersist = [];
        $retryCount = 0;
        $maxRetries = 3;
        while ($retryCount < $maxRetries) {
            try {
                $logger->info("Attempting to find ChanneledMetric: platformId=" . ($channeledMetric->platformId ?? 'null') . ", channel=" . ($channeledMetric->channel ?? 'null') . ", metricId={$metricEntity->getId()}, platformCreatedAt={$platformCreatedAt->format('Y-m-d')}");

                // Attempt to find existing channeled metric
                list($channeledMetricCache, $channeledMetricEntity) = self::processChanneledMetric(
                    $channeledMetricCache,
                    $cacheKey,
                    $logger,
                    $channeledMetric,
                    $metricEntity,
                    $platformCreatedAt,
                    $repository,
                    $manager
                );

                // Process dimensions
                list($dimensionCache) = self::processDimensions(
                    $channeledMetric,
                    $logger,
                    $channeledMetricEntity,
                    $dimensionCache,
                    $manager
                );

                $logger->info("Exiting getOrCreateChanneledMetric: channeledMetricId={$channeledMetricEntity->getId()}");
                return $channeledMetricEntity;
            } catch (OptimisticLockException $e) {
                if ($retryCount < $maxRetries - 1) {
                    $retryCount++;
                    usleep(100000 * $retryCount);
                    $logger->error("getOrCreateChanneledMetric retry $retryCount/$maxRetries due to OptimisticLockException: " . $e->getMessage());
                    continue;
                }
                $logger->error("getOrCreateChanneledMetric failed after $maxRetries retries due to OptimisticLockException: " . $e->getMessage());
                throw $e;
            } catch (ORMException $e) {
                if (str_contains($e->getMessage(), 'SQLSTATE[23000]') && $retryCount < $maxRetries - 1) {
                    $retryCount++;
                    usleep(100000 * $retryCount);
                    $logger->error("getOrCreateChanneledMetric retry $retryCount/$maxRetries due to duplicate key: " . $e->getMessage());
                    continue;
                }
                $logger->error("getOrCreateChanneledMetric failed after $maxRetries retries due to ORMException: " . $e->getMessage());
                throw $e;
            } catch (Exception $e) {
                $logger->error("getOrCreateChanneledMetric error: " . $e->getMessage());
                throw $e;
            }
        }
        $logger->error("getOrCreateChanneledMetric failed after $maxRetries retries");
        throw new Exception("getOrCreateChanneledMetric failed after $maxRetries retries");
    }

    /**
     * @param string|null $startDate
     * @param $lastChanneledMetric
     * @param bool|string $resume
     * @param string|null $endDate
     * @param LoggerInterface $logger
     * @return array
     */
    protected static function determineDateRange(
        ?string $startDate,
        $lastChanneledMetric,
        bool|string $resume,
        ?string $endDate,
        LoggerInterface $logger
    ): array {
        $origin = Carbon::parse("2000-01-01");
        $now = Carbon::now();
        $min = $startDate ? Carbon::parse($startDate) : (
            $lastChanneledMetric && filter_var($resume, FILTER_VALIDATE_BOOLEAN)
            ? Carbon::parse($lastChanneledMetric['platformCreatedAt'])
            : $origin
        );
        $max = $endDate ? Carbon::parse($endDate) : null;
        $from = $origin->format('Y-m-d');
        if ($min->lte($now) && $min->gte($origin) && (!$max || $min->lte($max))) {
            $from = $min->format('Y-m-d');
        }
        $to = $max && $max->lte($now) ? $max->format('Y-m-d') : $now->format('Y-m-d');
        $logger->info("Date range: from=$from, to=$to");
        return array($from, $to);
    }

    /**
     * @param object $channeledMetric
     * @param LoggerInterface $logger
     * @param mixed $channeledMetricEntity
     * @param array $dimensionCache
     * @param EntityManager $manager
     * @return array
     * @throws ORMException
     */
    protected static function processDimensions(
        object $channeledMetric,
        LoggerInterface $logger,
        mixed $channeledMetricEntity,
        array $dimensionCache,
        EntityManager $manager
    ): array {
        // Process dimensions using normalized system
        if (isset($channeledMetric->dimensions) && !empty($channeledMetric->dimensions)) {
            try {
                $dimManager = new \Classes\DimensionManager($manager);
                $dimensionSet = $dimManager->resolveDimensionSet((array) $channeledMetric->dimensions);
                $channeledMetricEntity->setDimensionSet($dimensionSet);
                $manager->persist($channeledMetricEntity);
                $logger->info("Assigned DimensionSet (hash: {$dimensionSet->getHash()}) to ChanneledMetric ID={$channeledMetricEntity->getId()}");
            } catch (\Exception $e) {
                $logger->error("Error resolving DimensionSet for ChanneledMetric ID={$channeledMetricEntity->getId()}: " . $e->getMessage());
                // We don't throw here to follow the "No Block" policy if possible, 
                // but DimensionSet is quite important. However, let's keep it robust.
            }
        }

        return array($dimensionCache);
    }

    /**
     * @param array $channeledMetricCache
     * @param string $cacheKey
     * @param LoggerInterface $logger
     * @param object $channeledMetric
     * @param Metric $metricEntity
     * @param DateTime $platformCreatedAt
     * @param ChanneledMetricRepository $repository
     * @param EntityManager $manager
     * @return array
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     * @throws Exception
     */
    protected static function processChanneledMetric(
        array $channeledMetricCache,
        string $cacheKey,
        LoggerInterface $logger,
        object $channeledMetric,
        Metric $metricEntity,
        DateTime $platformCreatedAt,
        ChanneledMetricRepository $repository,
        EntityManager $manager
    ): array {
        // Check cache first
        if (isset($channeledMetricCache[$cacheKey])) {
            $logger->info("ChanneledMetric found in cache: id=" . $channeledMetricCache[$cacheKey]->getId() . ", platformId=" . ($channeledMetric->platformId ?? 'null'));
            $channeledMetricEntity = $channeledMetricCache[$cacheKey];
        } else {
            // Query database
            if ($channeledMetricEntity = $repository->findOneBy([
                'platformId' => $channeledMetric->platformId ?? null,
                'channel' => $channeledMetric->channel ?? 8,
                'metric' => $metricEntity,
                'platformCreatedAt' => $platformCreatedAt
            ])) {
                // Update existing entity
                $channeledMetricData = $channeledMetricEntity->getData() ?? [];
                $newData = (array)($channeledMetric->data ?? []);
                $updatedData = [
                    'impressions' => max($channeledMetricData['impressions'] ?? 0, $newData['impressions'] ?? 0),
                    'clicks' => max($channeledMetricData['clicks'] ?? 0, $newData['clicks'] ?? 0),
                    'position_weighted' => max(
                        $channeledMetricData['position_weighted'] ?? 0,
                        $newData['position_weighted'] ?? 0
                    ),
                    'ctr' => max($channeledMetricData['ctr'] ?? 0, $newData['ctr'] ?? 0)
                ];

                $channeledMetricEntity->addData($updatedData);
                $channeledMetricEntity->addPlatformCreatedAt($platformCreatedAt);
                $channeledMetricEntity->addUpdatedAt(new DateTime());
                $manager->persist($channeledMetricEntity);

                // Check if entity is managed
                if (!$manager->contains($channeledMetricEntity)) {
                    $cmId = $channeledMetricEntity->getId();
                    $logger->error("ChanneledMetric entity detached after update: ID=" . $cmId . ", platformId=" . ($channeledMetric->platformId ?? 'null'));
                    $channeledMetricEntity = $manager->find(ChanneledMetric::class, $cmId);
                    if (!$channeledMetricEntity) {
                        $logger->error("Failed to reattach ChanneledMetric: ID=" . $cmId . ", platformId=" . ($channeledMetric->platformId ?? 'null'));
                        throw new Exception("Failed to reattach ChanneledMetric ID=" . $cmId);
                    }
                    // Reapply updates
                    $channeledMetricEntity->addData($updatedData);
                    $channeledMetricEntity->addPlatformCreatedAt($platformCreatedAt);
                    $channeledMetricEntity->addUpdatedAt(new DateTime());
                    $manager->persist($channeledMetricEntity);
                }
                $channeledMetricCache[$cacheKey] = $channeledMetricEntity; // Cache the updated entity
                $logger->info("Updated existing ChanneledMetric: id=" . $channeledMetricEntity->getId() . ", platformId=" . ($channeledMetric->platformId ?? 'null') . ", data=" . json_encode($updatedData));
            } else {
                // Create new entity
                $channeledMetric->data = (array)($channeledMetric->data ?? []);
                $channeledMetricEntity = $repository->create($channeledMetric, true);
                if (!$channeledMetricEntity->getId()) {
                    $logger->error("ChanneledMetric entity created but has no ID: platformId=" . ($channeledMetric->platformId ?? 'null'));
                }
                if (!$manager->contains($channeledMetricEntity)) {
                    $cmId = $channeledMetricEntity->getId();
                    $logger->error("ChanneledMetric entity detached after creation: ID=" . $cmId . ", platformId=" . ($channeledMetric->platformId ?? 'null'));
                    $channeledMetricEntity = $manager->find(ChanneledMetric::class, $cmId);
                    if (!$channeledMetricEntity) {
                        $logger->error("Failed to reattach ChanneledMetric after creation: ID=" . $cmId . ", platformId=" . ($channeledMetric->platformId ?? 'null'));
                        throw new Exception("Failed to reattach ChanneledMetric ID=" . $cmId);
                    }
                }
                $manager->persist($channeledMetricEntity); // Ensure persisted
                $channeledMetricCache[$cacheKey] = $channeledMetricEntity;
                $logger->info("Created new ChanneledMetric: id={$channeledMetricEntity->getId()}, platformId=" . ($channeledMetric->platformId ?? 'null') . ", data=" . json_encode($channeledMetric->data));
            }
        }
        return array($channeledMetricCache, $channeledMetricEntity);
    }

    /**
     * @param object $metric
     * @param mixed $queryString
     * @param LoggerInterface $logger
     * @param array $queryCache
     * @param QueryRepository $queryRepository
     * @param EntityManager|null $em
     * @return array
     * @throws ORMException
     * @throws Exception
     */
    protected static function checkIfTheQueryIsAlreadyCached(
        object $metric,
        mixed $queryString,
        LoggerInterface $logger,
        array $queryCache,
        QueryRepository $queryRepository,
        ?EntityManager $em
    ): array {
        $queryEntity = null;
        if ($metric->channel === Channel::google_search_console->value && isset($metric->query)) {
            if (!is_string($queryString) || empty(trim($queryString))) {
                $logger->warning("Invalid query: " . print_r($queryString, true));
                $queryString = 'unknown';
            }
            $queryKey = md5($queryString);
            if (!isset($queryCache[$queryKey])) {
                if (!$queryEntity = $queryRepository->findOneBy(['query' => $queryString])) {
                    try {
                        $queryEntity = $queryRepository->create((object)['query' => $queryString], true);
                        if ($em) {
                            $em->persist($queryEntity);
                            $logger->info("Persisted new Query: '$queryString', ID=" . $queryEntity->getId());
                        }
                    } catch (ORMException $e) {
                        if (str_contains($e->getMessage(), 'SQLSTATE[23000]')) {
                            $logger->warning("Duplicate query '$queryString', retrying lookup");
                            $queryEntity = $queryRepository->findOneBy(['query' => $queryString]);
                            if (!$queryEntity) {
                                $logger->error("Failed to find or create Query for '$queryString': " . $e->getMessage());
                                throw new Exception("Failed to find or create Query for '$queryString'");
                            }
                        } else {
                            $logger->error("Database error creating Query for '$queryString': " . $e->getMessage());
                            throw $e;
                        }
                    }
                }
                $queryCache[$queryKey] = $queryEntity;
            } else {
                $queryEntity = $queryCache[$queryKey];
                $logger->info("Query found in cache: '$queryString', ID=" . $queryEntity->getId());
            }
        }
        return array($queryEntity, $queryString, $queryCache);
    }

    /**
     * @param object $metric
     * @return array|string[]
     */
    protected static function getNormalizedDimensions(object $metric): array
    {
        $dimensions = isset($metric->dimensions) ? array_column(
            (array)$metric->dimensions,
            'dimensionValue',
            'dimensionKey'
        ) : [];
        $normalizedDimensions = array_map(function ($value) {
            return is_string($value) ? strtolower(trim($value)) : ($value ?? 'unknown');
        }, $dimensions);
        // Ensure date dimension matches metricDate
        if (isset($metric->metricDate)) {
            $normalizedDimensions['date'] = $metric->metricDate->format('Y-m-d');
        }
        return $normalizedDimensions;
    }

    /**
     * @param object|null $filters
     * @param mixed $site
     * @return array
     */
    protected static function getDimensionFilterGroups(?object $filters, mixed $site): array
    {
        $includeKeywords = $filters->includeKeywords ?? ($site['include_keywords'] ?? null);
        $excludeKeywords = $filters->excludeKeywords ?? ($site['exclude_keywords'] ?? null);
        $includeCountries = $filters->includeCountries ?? ($site['include_countries'] ?? null);
        $excludeCountries = $filters->excludeCountries ?? ($site['exclude_countries'] ?? null);
        $includePages = $filters->includePages ?? ($site['include_pages'] ?? null);
        $excludePages = $filters->excludePages ?? ($site['exclude_pages'] ?? null);
        $dimensionFilterGroups = [];
        if ($includeKeywords) {
            $dimensionFilterGroups[] = [
                'filters' => array_map(fn ($kw) => [
                    'dimension' => Dimension::QUERY,
                    'operator' => Operator::CONTAINS,
                    'expression' => $kw
                ], $includeKeywords),
                'groupType' => GroupType::AND->value
            ];
        } elseif ($excludeKeywords) {
            $dimensionFilterGroups[] = [
                'filters' => array_map(fn ($kw) => [
                    'dimension' => Dimension::QUERY,
                    'operator' => Operator::NOT_CONTAINS,
                    'expression' => $kw
                ], $excludeKeywords),
                'groupType' => GroupType::AND->value
            ];
        }
        if ($includeCountries) {
            $dimensionFilterGroups[] = [
                'filters' => array_map(fn ($country) => [
                    'dimension' => Dimension::COUNTRY,
                    'operator' => Operator::EQUALS,
                    'expression' => $country
                ], $includeCountries),
                'groupType' => GroupType::AND->value
            ];
        } elseif ($excludeCountries) {
            $dimensionFilterGroups[] = [
                'filters' => array_map(fn ($country) => [
                    'dimension' => Dimension::COUNTRY,
                    'operator' => Operator::NOT_EQUALS,
                    'expression' => $country
                ], $excludeCountries),
                'groupType' => GroupType::AND->value
            ];
        }
        if ($includePages) {
            $dimensionFilterGroups[] = [
                'filters' => array_map(fn ($page) => [
                    'dimension' => Dimension::PAGE,
                    'operator' => Operator::CONTAINS,
                    'expression' => $page
                ], $includePages),
                'groupType' => GroupType::AND->value
            ];
        } elseif ($excludePages) {
            $dimensionFilterGroups[] = [
                'filters' => array_map(fn ($page) => [
                    'dimension' => Dimension::PAGE,
                    'operator' => Operator::NOT_CONTAINS,
                    'expression' => $page
                ], $excludePages),
                'groupType' => GroupType::AND->value
            ];
        }
        return $dimensionFilterGroups;
    }

    private static function processCampaignsBulk(
        FacebookGraphApi $api,
        EntityManager $manager,
        ChanneledAccount $channeledAccountEntity,
        LoggerInterface $logger,
        ?string $startDate,
        ?string $endDate,
        array $channeledCampaignMap,
        array $campaignMap,
        ?int $jobId = null,
        $cacheInclude = null,
        $cacheExclude = null
    ): array {
        $campaignPlatformIds = array_values($channeledCampaignMap['mapReverse']);
        if (empty($campaignPlatformIds)) {
            return ['metrics' => 0, 'rows' => 0];
        }

        $additionalParams = [];
        if ($startDate && $endDate) {
            $additionalParams['time_range'] = json_encode(['since' => $startDate, 'until' => $endDate]);
        }

        try {
            $campaignPlatformIdChunks = array_chunk($campaignPlatformIds, 50);
            $allRows = [];

            foreach ($campaignPlatformIdChunks as $chunk) {
                $maxRetries = 3;
                $retryCount = 0;
                $fetched = false;
                $rows = ['data' => []];

                while ($retryCount < $maxRetries && !$fetched) {
                    try {
                        $rows = $api->getCampaignInsightsFromAdAccount(
                            adAccountId: $channeledAccountEntity->getPlatformId(),
                            campaignIds: $chunk,
                            limit: 100,
                            metricBreakdown: [MetricBreakdown::AGE, MetricBreakdown::GENDER],
                            additionalParams: $additionalParams,
                            metricSet: MetricSet::KEY,
                        );
                        $fetched = true;
                    } catch (Exception $e) {
                        $retryCount++;
                        if ($retryCount >= $maxRetries) {
                            if (str_contains($e->getMessage(), 'request limit reached')) {
                                throw new FacebookRateLimitException($e->getMessage());
                            }
                            throw $e;
                        }
                        $logger->warning("Retry $retryCount/$maxRetries for campaign insights bulk AdAccount " . $channeledAccountEntity->getPlatformId() . ": " . $e->getMessage());
                        usleep(200000 * $retryCount);
                    }
                }

                if (isset($rows['data']) && is_array($rows['data'])) {
                    $allRows = array_merge($allRows, $rows['data']);
                }
            }

            $logger->info("Fetched " . count($allRows) . " bulk rows for campaigns in Ad Account " . $channeledAccountEntity->getPlatformId());

            if (count($allRows) === 0) {
                $logger->info("No bulk rows found for campaigns in Ad Account " . $channeledAccountEntity->getPlatformId());
                return ['metrics' => 0, 'rows' => 0];
            }

            $groupedRows = [];
            foreach ($allRows as $row) {
                $groupedRows[$row['campaign_id']][] = $row;
            }

            $campaignRepository = $manager->getRepository(Campaign::class);
            $channeledCampaignRepository = $manager->getRepository(ChanneledCampaign::class);
            $globalAllMetrics = new ArrayCollection();

            foreach ($groupedRows as $campaignPlatformId => $campaignRows) {
                Helpers::checkJobStatus($jobId);
                $campaignEntity = $campaignRepository->findOneBy(['campaignId' => $campaignPlatformId]);
                $channeledCampaignEntity = $channeledCampaignRepository->findOneBy(['platformId' => $campaignPlatformId]);

                if (!$campaignEntity || !$channeledCampaignEntity) {
                    continue;
                }

                $campaignName = $campaignEntity->getName();
                if (!Helpers::matchesFilter((string)$campaignName, $cacheInclude, $cacheExclude) && !Helpers::matchesFilter((string)$campaignPlatformId, $cacheInclude, $cacheExclude)) {
                    continue;
                }

                $metrics = FacebookMarketingMetricConvert::campaignMetrics(
                    rows: $campaignRows,
                    logger: $logger,
                    channeledAccountEntity: $channeledAccountEntity,
                    campaignEntity: $campaignEntity,
                    channeledCampaignEntity: $channeledCampaignEntity,
                    metricSet: MetricSet::KEY,
                );

                foreach ($metrics as $metric) {
                    $metric->channeledAccount = $channeledAccountEntity;
                    $metric->campaign = $campaignEntity;
                    $metric->channeledCampaign = $channeledCampaignEntity;
                    $globalAllMetrics->add($metric);
                }
            }

            if (count($globalAllMetrics) > 0) {
                try {
                    $manager->getConnection()->beginTransaction();
                    $metricConfigMap = MetricsProcessor::processMetricConfigs(
                        metrics: $globalAllMetrics,
                        manager: $manager,
                        channeledAccountMap: ['map' => [$channeledAccountEntity->getPlatformId() => $channeledAccountEntity->getId()], 'mapReverse' => [$channeledAccountEntity->getId() => $channeledAccountEntity->getPlatformId()]],
                        campaignMap: $campaignMap,
                        channeledCampaignMap: $channeledCampaignMap,
                    );
                    $metricMap = MetricsProcessor::processMetrics(
                        metrics: $globalAllMetrics,
                        manager: $manager,
                        metricConfigMap: $metricConfigMap,
                    );
                    $channeledMetricMap = MetricsProcessor::processChanneledMetrics(
                        metrics: $globalAllMetrics,
                        manager: $manager,
                        metricMap: $metricMap,
                        logger: $logger,
                    );
                    $manager->getConnection()->commit();
                } catch (Exception $e) {
                    if ($manager->getConnection()->isTransactionActive()) {
                        $manager->getConnection()->rollback();
                    }
                    throw $e;
                }
            }
            $logger->info("Completed bulk Meta ad account's campaign insights request");
            return ['metrics' => count($globalAllMetrics), 'rows' => count($allRows)];
        } catch (Exception $e) {
            $logger->error("Error during bulk Meta account's campaign insights request: " . $e->getMessage());
            throw $e;
        }
    }

    private static function processAdsetsBulk(
        FacebookGraphApi $api,
        EntityManager $manager,
        ChanneledAccount $channeledAccountEntity,
        LoggerInterface $logger,
        ?string $startDate,
        ?string $endDate,
        array $campaignMap,
        array $channeledCampaignMap,
        array $channeledAdGroupMap,
        ?int $jobId = null,
        $cacheInclude = null,
        $cacheExclude = null
    ): array {
        $adsetPlatformIds = array_keys($channeledAdGroupMap['mapCampaign']);
        if (empty($adsetPlatformIds)) {
            return ['metrics' => 0, 'rows' => 0];
        }

        $additionalParams = [];
        if ($startDate && $endDate) {
            $additionalParams['time_range'] = json_encode(['since' => $startDate, 'until' => $endDate]);
        }

        try {
            $adsetPlatformIdChunks = array_chunk($adsetPlatformIds, 50);
            $allRows = [];

            foreach ($adsetPlatformIdChunks as $chunk) {
                $maxRetries = 3;
                $retryCount = 0;
                $fetched = false;
                $rows = ['data' => []];

                while ($retryCount < $maxRetries && !$fetched) {
                    try {
                        $rows = $api->getAdsetInsightsFromAdAccount(
                            adAccountId: $channeledAccountEntity->getPlatformId(),
                            adsetIds: $chunk,
                            limit: 100,
                            metricBreakdown: [MetricBreakdown::AGE, MetricBreakdown::GENDER],
                            additionalParams: $additionalParams,
                            metricSet: MetricSet::KEY,
                        );
                        $fetched = true;
                    } catch (Exception $e) {
                        $retryCount++;
                        if ($retryCount >= $maxRetries) {
                            if (str_contains($e->getMessage(), 'request limit reached')) {
                                throw new FacebookRateLimitException($e->getMessage());
                            }
                            throw $e;
                        }
                        $logger->warning("Retry $retryCount/$maxRetries for adset insights bulk AdAccount " . $channeledAccountEntity->getPlatformId() . ": " . $e->getMessage());
                        usleep(200000 * $retryCount);
                    }
                }

                if (isset($rows['data']) && is_array($rows['data'])) {
                    $allRows = array_merge($allRows, $rows['data']);
                }
            }

            $logger->info("Fetched " . count($allRows) . " bulk rows for adsets in Ad Account " . $channeledAccountEntity->getPlatformId());

            if (count($allRows) === 0) {
                $logger->info("No bulk rows found for adsets in Ad Account " . $channeledAccountEntity->getPlatformId());
                return ['metrics' => 0, 'rows' => 0];
            }

            $groupedRows = [];
            foreach ($allRows as $row) {
                $groupedRows[$row['adset_id']][] = $row;
            }

            $campaignRepository = $manager->getRepository(Campaign::class);
            $channeledCampaignRepository = $manager->getRepository(ChanneledCampaign::class);
            $channeledAdGroupRepository = $manager->getRepository(ChanneledAdGroup::class);
            $globalAllMetrics = new ArrayCollection();

            foreach ($groupedRows as $adsetPlatformId => $adsetRows) {
                Helpers::checkJobStatus($jobId);
                $campaignId = $channeledAdGroupMap['mapCampaign'][$adsetPlatformId];
                $campaignEntity = $campaignRepository->findOneBy(['campaignId' => $campaignId]);
                $channeledCampaignEntity = $channeledCampaignRepository->findOneBy(['platformId' => $campaignId]);
                $channeledAdGroupEntity = $channeledAdGroupRepository->findOneBy(['platformId' => $adsetPlatformId]);

                if (!$campaignEntity || !$channeledCampaignEntity || !$channeledAdGroupEntity) {
                    continue;
                }

                $adsetName = $channeledAdGroupEntity->getName();
                if (!Helpers::matchesFilter((string)$adsetName, $cacheInclude, $cacheExclude) && !Helpers::matchesFilter((string)$adsetPlatformId, $cacheInclude, $cacheExclude)) {
                    continue;
                }

                $metrics = FacebookMarketingMetricConvert::adsetMetrics(
                    rows: $adsetRows,
                    logger: $logger,
                    channeledAccountEntity: $channeledAccountEntity,
                    campaignEntity: $campaignEntity,
                    channeledCampaignEntity: $channeledCampaignEntity,
                    channeledAdGroupEntity: $channeledAdGroupEntity,
                    metricSet: MetricSet::KEY,
                );

                foreach ($metrics as $metric) {
                    $metric->channeledAccount = $channeledAccountEntity;
                    $metric->campaign = $campaignEntity;
                    $metric->channeledCampaign = $channeledCampaignEntity;
                    $metric->channeledAdGroup = $channeledAdGroupEntity;
                    $globalAllMetrics->add($metric);
                }
            }

            if (count($globalAllMetrics) > 0) {
                try {
                    $manager->getConnection()->beginTransaction();
                    $metricConfigMap = MetricsProcessor::processMetricConfigs(
                        metrics: $globalAllMetrics,
                        manager: $manager,
                        channeledAccountMap: ['map' => [$channeledAccountEntity->getPlatformId() => $channeledAccountEntity->getId()], 'mapReverse' => [$channeledAccountEntity->getId() => $channeledAccountEntity->getPlatformId()]],
                        campaignMap: $campaignMap,
                        channeledCampaignMap: $channeledCampaignMap,
                        channeledAdGroupMap: $channeledAdGroupMap,
                    );
                    $metricMap = MetricsProcessor::processMetrics(
                        metrics: $globalAllMetrics,
                        manager: $manager,
                        metricConfigMap: $metricConfigMap,
                    );
                    $channeledMetricMap = MetricsProcessor::processChanneledMetrics(
                        metrics: $globalAllMetrics,
                        manager: $manager,
                        metricMap: $metricMap,
                        logger: $logger,
                    );
                    $manager->getConnection()->commit();
                } catch (Exception $e) {
                    if ($manager->getConnection()->isTransactionActive()) {
                        $manager->getConnection()->rollback();
                    }
                    throw $e;
                }
            }
            $logger->info("Completed bulk Meta ad account's adset insights request");
            return ['metrics' => count($globalAllMetrics), 'rows' => count($allRows)];
        } catch (Exception $e) {
            $logger->error("Error during bulk Meta account's adset insights request: " . $e->getMessage());
            throw $e;
        }
    }

    private static function processAdsBulk(
        FacebookGraphApi $api,
        EntityManager $manager,
        ChanneledAccount $channeledAccountEntity,
        LoggerInterface $logger,
        ?string $startDate,
        ?string $endDate,
        array $campaignMap,
        array $channeledCampaignMap,
        array $channeledAdGroupMap,
        array $channeledAdMap,
        ?int $jobId = null,
        $cacheInclude = null,
        $cacheExclude = null
    ): array {
        $adPlatformIds = array_keys($channeledAdMap['mapAdGroup']);
        if (empty($adPlatformIds)) {
            return ['metrics' => 0, 'rows' => 0];
        }

        $additionalParams = [];
        if ($startDate && $endDate) {
            $additionalParams['time_range'] = json_encode(['since' => $startDate, 'until' => $endDate]);
        }

        $adPlatformIdChunks = array_chunk($adPlatformIds, 50);
        $allRows = [];

        foreach ($adPlatformIdChunks as $chunk) {
            $maxRetries = 3;
            $retryCount = 0;
            $fetched = false;
            $rows = ['data' => []];

            while ($retryCount < $maxRetries && !$fetched) {
                try {
                    $rows = $api->getAdInsightsFromAdAccount(
                        adAccountId: $channeledAccountEntity->getPlatformId(),
                        adIds: $chunk,
                        limit: 100,
                        metricBreakdown: [MetricBreakdown::AGE, MetricBreakdown::GENDER],
                        additionalParams: $additionalParams,
                        metricSet: MetricSet::KEY,
                    );
                    $fetched = true;
                } catch (Exception $e) {
                    $retryCount++;
                    if ($retryCount >= $maxRetries) {
                        if (str_contains($e->getMessage(), 'request limit reached')) {
                            throw new FacebookRateLimitException($e->getMessage());
                        }
                        throw $e;
                    }
                    $logger->warning("Retry $retryCount/$maxRetries for ad insights bulk AdAccount " . $channeledAccountEntity->getPlatformId() . ": " . $e->getMessage());
                    usleep(200000 * $retryCount);
                }
            }

            if (isset($rows['data']) && is_array($rows['data'])) {
                $allRows = array_merge($allRows, $rows['data']);
            }
        }

        $logger->info("Fetched " . count($allRows) . " bulk rows for ads in Ad Account " . $channeledAccountEntity->getPlatformId());

        if (count($allRows) === 0) {
            $logger->info("No bulk rows found for ads in Ad Account " . $channeledAccountEntity->getPlatformId());
            return ['metrics' => 0, 'rows' => 0];
        }

        $groupedRows = [];
        foreach ($allRows as $row) {
            $groupedRows[$row['ad_id']][] = $row;
        }

        try {
            $campaignRepository = $manager->getRepository(Campaign::class);
            $channeledCampaignRepository = $manager->getRepository(ChanneledCampaign::class);
            $channeledAdGroupRepository = $manager->getRepository(ChanneledAdGroup::class);
            $channeledAdRepository = $manager->getRepository(ChanneledAd::class);
            $globalAllMetrics = new ArrayCollection();

            foreach ($groupedRows as $adPlatformId => $adRows) {
                Helpers::checkJobStatus($jobId);
                $adgroupId = $channeledAdMap['mapAdGroup'][$adPlatformId];
                $campaignId = $channeledAdGroupMap['mapCampaign'][$adgroupId];

                $campaignEntity = $campaignRepository->findOneBy(['campaignId' => $campaignId]);
                $channeledCampaignEntity = $channeledCampaignRepository->findOneBy(['platformId' => $campaignId]);
                $channeledAdGroupEntity = $channeledAdGroupRepository->findOneBy(['platformId' => $adgroupId]);
                $channeledAdEntity = $channeledAdRepository->findOneBy(['platformId' => $adPlatformId]);

                if (!$campaignEntity || !$channeledCampaignEntity || !$channeledAdGroupEntity || !$channeledAdEntity) {
                    continue;
                }

                $adName = $channeledAdEntity->getName();
                if (!Helpers::matchesFilter((string)$adName, $cacheInclude, $cacheExclude) && !Helpers::matchesFilter((string)$adPlatformId, $cacheInclude, $cacheExclude)) {
                    continue;
                }

                $metrics = FacebookMarketingMetricConvert::adMetrics(
                    rows: $adRows,
                    logger: $logger,
                    channeledAccountEntity: $channeledAccountEntity,
                    campaignEntity: $campaignEntity,
                    channeledCampaignEntity: $channeledCampaignEntity,
                    channeledAdGroupEntity: $channeledAdGroupEntity,
                    channeledAdEntity: $channeledAdEntity,
                    metricSet: MetricSet::KEY,
                );

                foreach ($metrics as $metric) {
                    $metric->channeledAccount = $channeledAccountEntity;
                    $metric->campaign = $campaignEntity;
                    $metric->channeledCampaign = $channeledCampaignEntity;
                    $metric->channeledAdGroup = $channeledAdGroupEntity;
                    $metric->channeledAd = $channeledAdEntity;
                    $globalAllMetrics->add($metric);
                }
            }

            if (count($globalAllMetrics) > 0) {
                $creativeMap = self::getCreativeMap($manager, $channeledAccountEntity);
                try {
                    $manager->getConnection()->beginTransaction();
                    $metricConfigMap = MetricsProcessor::processMetricConfigs(
                        metrics: $globalAllMetrics,
                        manager: $manager,
                        channeledAccountMap: ['map' => [$channeledAccountEntity->getPlatformId() => $channeledAccountEntity->getId()], 'mapReverse' => [$channeledAccountEntity->getId() => $channeledAccountEntity->getPlatformId()]],
                        campaignMap: $campaignMap,
                        channeledCampaignMap: $channeledCampaignMap,
                        channeledAdGroupMap: $channeledAdGroupMap,
                        channeledAdMap: $channeledAdMap,
                        creativeMap: $creativeMap,
                    );
                    $metricMap = MetricsProcessor::processMetrics(
                        metrics: $globalAllMetrics,
                        manager: $manager,
                        metricConfigMap: $metricConfigMap,
                    );
                    $channeledMetricMap = MetricsProcessor::processChanneledMetrics(
                        metrics: $globalAllMetrics,
                        manager: $manager,
                        metricMap: $metricMap,
                        logger: $logger,
                    );
                    $manager->getConnection()->commit();
                } catch (Exception $e) {
                    if ($manager->getConnection()->isTransactionActive()) {
                        $manager->getConnection()->rollback();
                    }
                    throw $e;
                }
            }
            $logger->info("Completed bulk Meta ad account's ad insights request");
            return ['metrics' => count($globalAllMetrics), 'rows' => count($allRows)];
        } catch (Exception $e) {
            $logger->error("Error during bulk Meta account's ad insights request: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param FacebookGraphApi $api
     * @param EntityManager $manager
     * @param ChanneledAccount $channeledAccountEntity
     * @param LoggerInterface $logger
     * @param string|null $startDate
     * @param string|null $endDate
     * @param int|null $jobId
     * @return bool
     * @throws Exception
     */
    private static function processCreativesBulk(
        FacebookGraphApi $api,
        EntityManager $manager,
        ChanneledAccount $channeledAccountEntity,
        LoggerInterface $logger,
        ?string $startDate = null,
        ?string $endDate = null,
        ?int $jobId = null,
        $cacheInclude = null,
        $cacheExclude = null
    ): array {
        $logger->info("Starting processCreativesBulk for ad account: " . $channeledAccountEntity->getPlatformId());
        try {
            $adAccountId = $channeledAccountEntity->getPlatformId();
            
            /** @var \Repositories\CreativeRepository $creativeRepository */
            $creativeRepository = $manager->getRepository(Creative::class);
            
            $qb = $manager->createQueryBuilder();
            $qb->select('DISTINCT c')
                ->from(Creative::class, 'c')
                ->join('c.channeledAds', 'ca')
                ->where('ca.channeledAccount = :channeledAccount')
                ->setParameter('channeledAccount', $channeledAccountEntity);
                
            $creatives = $qb->getQuery()->getResult();
            $logger->info("Found " . count($creatives) . " creatives for account in DB");
            $creativesMap = [];
            foreach ($creatives as $creative) {
                $creativesMap[$creative->getCreativeId()] = $creative;
            }

            $additionalParams = [
                'breakdowns' => 'ad_creative_id,ad_id,adset_id,campaign_id',
                'limit' => 100,
            ];
            if ($startDate) {
                $additionalParams['time_range'] = json_encode(['since' => $startDate, 'until' => $endDate]);
            }
            
            $maxRetries = 3;
            $retryCount = 0;
            $fetched = false;
            $insights = ['data' => []];

            while ($retryCount < $maxRetries && !$fetched) {
                try {
                    $insights = $api->getAdAccountInsights(
                        adAccountId: $adAccountId,
                        additionalParams: $additionalParams,
                        metricSet: MetricSet::FULL
                    );
                    $fetched = true;
                } catch (Exception $e) {
                    $retryCount++;
                    if ($retryCount >= $maxRetries) {
                        throw $e;
                    }
                    $logger->warning("Retry $retryCount/$maxRetries for creative insights AdAccount " . $adAccountId . ": " . $e->getMessage());
                    usleep(200000 * $retryCount);
                }
            }

            $groupedRows = [];
            foreach ($insights['data'] as $row) {
                if (isset($row['ad_creative_id'])) {
                    $groupedRows[$row['ad_creative_id']][] = $row;
                }
            }
            $logger->info("Fetched insights for " . count($groupedRows) . " unique creatives");

            $totalMetricsCount = 0;
            foreach ($groupedRows as $creativePlatformId => $rows) {
                Helpers::checkJobStatus($jobId);
                
                $creative = $creativesMap[$creativePlatformId] ?? null;
                if (!$creative) {
                    continue;
                }

                $creativeName = $creative->getName();
                if (!Helpers::matchesFilter($creativeName, $cacheInclude, $cacheExclude) && !Helpers::matchesFilter($creativePlatformId, $cacheInclude, $cacheExclude)) {
                    continue;
                }

                $metrics = FacebookMarketingMetricConvert::creativeMetrics(
                    rows: $rows,
                    logger: $logger,
                    channeledAccountEntity: $channeledAccountEntity,
                    creativeEntity: $creative,
                    metricSet: MetricSet::FULL
                );
                
                if ($metrics->count() > 0) {
                    try {
                        $manager->getConnection()->beginTransaction();
                        $metricConfigMap = MetricsProcessor::processMetricConfigs(
                            metrics: $metrics,
                            manager: $manager,
                            channeledAccountMap: [
                                'map' => [$channeledAccountEntity->getPlatformId() => $channeledAccountEntity->getId()],
                                'mapReverse' => [$channeledAccountEntity->getId() => $channeledAccountEntity->getPlatformId()]
                            ],
                            creativeMap: [
                                'map' => [$creative->getCreativeId() => $creative->getId()],
                                'mapReverse' => [$creative->getId() => $creative->getCreativeId()]
                            ]
                        );
                        $metricMap = MetricsProcessor::processMetrics(
                            metrics: $metrics,
                            manager: $manager,
                            metricConfigMap: $metricConfigMap,
                        );
                        MetricsProcessor::processChanneledMetrics(
                            metrics: $metrics,
                            manager: $manager,
                            metricMap: $metricMap,
                            logger: $logger,
                        );
                        $manager->getConnection()->commit();
                    } catch (Exception $e) {
                        if ($manager->getConnection()->isTransactionActive()) {
                            $manager->getConnection()->rollback();
                        }
                        throw $e;
                    }
                    $totalMetricsCount += $metrics->count();
                }
            }
            $logger->info("Completed bulk Meta account's creative insights request: $totalMetricsCount metrics");
            return ['metrics' => $totalMetricsCount, 'rows' => count($insights['data'])];
        } catch (Exception $e) {
            $logger->error("Error during bulk Meta account's creative insights request: " . $e->getMessage());
            throw $e;
        }
    }
    /**
     * Helper to get entity-specific or global Facebook filter.
     *
     * @param array $config
     * @param string|null $entityKey
     * @param string $filterType 'cache_include' or 'cache_exclude'
     * @return string|null
     */
    public static function getFacebookFilter(array $config, string $entityKey = '', string $filterType = 'cache_include'): ?string
    {
        // MetricRequests::validateFacebookConfig merges everything into one flat array.
        // We look for the entity config directly in the flattened array.
        $fbConfig = $config[$entityKey] ?? [];

        // To satisfy "other entities shouldn't get filtered if you don't define a string for them", 
        // we skip global fallback if entityKey is present.
        if ($entityKey) {
            return $fbConfig[$filterType] ?? null;
        }

        // Fallback to top-level if no entity key provided (for backward compatibility)
        return $config[$filterType] ?? null;
    }

    /**
     * Helper to get retention range from config.
     *
     * @param array $config
     * @param string $channel 'facebook' or 'google_search_console'
     * @param string $default
     * @return Carbon
     */
    public static function getRetentionRange(array $config, string $channel, string $default): Carbon
    {
        // Since config is flattened in validateFacebookConfig, we look for the key directly.
        $range = $config['cache_history_range'] ?? $default;

        try {
            $range = trim($range);
            if (!str_starts_with($range, '-') && !str_starts_with($range, 'last')) {
                $range = '-' . $range;
            }
            return Carbon::now()->modify($range)->startOfDay();
        } catch (Exception $e) {
            $default = trim($default);
            if (!str_starts_with($default, '-') && !str_starts_with($default, 'last')) {
                $default = '-' . $default;
            }
            return Carbon::now()->modify($default)->startOfDay();
        }
    }
}
