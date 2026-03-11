<?php

declare(strict_types=1);

namespace Classes\Requests;

use Anibalealvarezs\FacebookGraphApi\Enums\MediaType;
use Anibalealvarezs\FacebookGraphApi\Enums\MetricBreakdown;
use Anibalealvarezs\FacebookGraphApi\Enums\MetricSet;
use Anibalealvarezs\FacebookGraphApi\FacebookGraphApi;
use Anibalealvarezs\GoogleApi\Services\SearchConsole\Enums\Dimension;
use Anibalealvarezs\GoogleApi\Services\SearchConsole\Enums\GroupType;
use Anibalealvarezs\GoogleApi\Services\SearchConsole\Enums\Operator;
use Anibalealvarezs\KlaviyoApi\Enums\AggregatedMeasurement;
use Carbon\Carbon;
use Classes\Conversions\FacebookGraphConvert;
use Classes\Conversions\GoogleSearchConsoleConvert;
use Classes\Conversions\KlaviyoConvert;
use Classes\MapGenerator;
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
use Entities\Analytics\Channeled\ChanneledMetric;
use Entities\Analytics\Channeled\ChanneledMetricDimension;
use Entities\Analytics\Country;
use Entities\Analytics\Device;
use Entities\Analytics\Metric;
use Entities\Analytics\Page;
use Entities\Analytics\Post;
use Entities\Analytics\Query;
use Enums\BillingEvent;
use Enums\CampaignBuyingType;
use Enums\CampaignObjective;
use Enums\CampaignStatus;
use Enums\Channel;
use Enums\Country as CountryEnum;
use Enums\Device as DeviceEnum;
use Enums\OptimizationGoal;
use Enums\Period;
use Enums\Account as AccountEnum;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\FacebookGraphHelpers;
use Helpers\GoogleSearchConsoleHelpers;
use Helpers\Helpers;
use Repositories\Channeled\ChanneledMetricRepository;
use Repositories\Channeled\ChanneledMetricDimensionRepository;
use Repositories\QueryRepository;
use Repositories\MetricRepository;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
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
            Channel::facebook,
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
    public static function getListFromFacebook(
        ?string $startDate = null,
        ?string $endDate = null,
        string|bool $resume = true,
        ?LoggerInterface $logger = null,
        ?int $jobId = null
    ): Response {
        if (!$logger) {
            $logger = new Logger('gsc');
            $logger->pushHandler(new StreamHandler('logs/gsc.log', Level::Info));
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

        $logger->info("Starting getListFromFacebook: startDate=$startDate, endDate=$endDate, resume=$resume");
        $manager = Helpers::getManager();
        try {
            // Validate configuration
            $config = self::validateFacebookConfig($logger);

            // Initialize API client
            $api = self::initializeFacebookGraphApi($config, $logger);

            // Initialize repositories and settings
            $pageRepository = $manager->getRepository(Page::class);
            $postRepository = $manager->getRepository(Post::class);
            $accountRepository = $manager->getRepository(Account::class);
            $channeledAccountRepository = $manager->getRepository(ChanneledAccount::class);
            $campaignRepository = $manager->getRepository(Campaign::class);
            $channeledCampaignRepository = $manager->getRepository(ChanneledCampaign::class);
            $channeledAdGroupRepository = $manager->getRepository(ChanneledAdGroup::class);
            $channeledAdRepository = $manager->getRepository(ChanneledAd::class);

            // Load global entities
            $accountEntity = $accountRepository->findOneBy(['name' => $config['facebook']['accounts_group_name']]);

            // Load pages and create a map
            /** @var Page[] $pages */
            $pages = $pageRepository->findAll();
            $pageMap = [
                'map' => [],
                'mapReverse' => [],
            ];
            foreach ($pages as $page) {
                $pageMap['map'][$page->getUrl()] = $page->getId();
                $pageMap['mapReverse'][$page->getId()] = $page->getUrl();
            }

            $totalMetrics = 0;
            $totalRows = 0;
            $totalDuplicates = 0;

            // Process everything
            foreach ($config['facebook']['pages'] as $page) {
                Helpers::checkJobStatus($jobId);

                if (!$page['enabled']) {
                    $logger->info("Skipping disabled page: " . $page['id']);
                    continue;
                }
                $pageEntity = $pageRepository->findOneBy(['platformId' => $page['id']]);
                if ($page['page_metrics']) {
                    $manager->getConnection()->beginTransaction();
                    try {
                        self::processFacebookPage(
                            page: $page,
                            startDate: $startDate,
                            endDate: $endDate,
                            api: $api,
                            manager: $manager,
                            pageRepository: $pageRepository,
                            logger: $logger,
                            pageMap: $pageMap,
                        );
                        $manager->getConnection()->commit();
                    } catch (Exception $e) {
                        $manager->getConnection()->rollBack();
                        $logger->error("Error processing page " . $page['id'] . ": " . $e->getMessage());
                    }
                } else {
                    $logger->info("Skipping page metrics for page: " . $page['id']);
                }
                if ($page['posts']) {
                    $postMap = self::fetchFacebookPagePosts(
                        page: $page,
                        api: $api,
                        manager: $manager,
                        logger: $logger,
                        pageEntity: $pageEntity,
                        accountEntity: $accountEntity,
                    );
                    if ($page['post_metrics']) {
                        $hasResults = true;
                        foreach ($postMap['map'] as $post) {
                            if (!$hasResults) {
                                break;
                            }
                            $hasResults = self::processFacebookPagePost(
                                postEntity: $postRepository->findOneBy(['id' => $post]),
                                pageEntity: $pageEntity,
                                api: $api,
                                manager: $manager,
                                logger: $logger,
                                postMap: $postMap,
                                pageMap: $pageMap,
                            );
                        }
                    } else {
                        $logger->info("Skipping post metrics for page: " . $page['id']);
                    }
                } else {
                    $logger->info("Skipping posts for page: " . $page['id']);
                }
                if ($page['ig_account'] && $page['ig_account_metrics']) {
                    self::processInstagramAccount(
                        page: $page,
                        api: $api,
                        manager: $manager,
                        accountEntity: $accountEntity,
                        pageEntity: $pageEntity,
                        logger: $logger,
                        pageMap: $pageMap,
                        startDate: $startDate,
                        endDate: $endDate,
                    );
                }
                $channeledAccountEntity = $channeledAccountRepository->findOneBy([
                    'platformId' => $page['ig_account'],
                    'account' => $accountEntity,
                ]);
                if ($page['ig_account_media']) {
                    $mediaMap = self::fetchInstagramAccountMedia(
                        page: $page,
                        api: $api,
                        manager: $manager,
                        logger: $logger,
                        pageEntity: $pageEntity,
                        accountEntity: $accountEntity,
                        channeledAccountEntity: $channeledAccountEntity,
                    );
                    if ($page['ig_account_media_metrics']) {
                        $hasResults = true;
                        foreach ($mediaMap['map'] as $media) {
                            if (($page['ig_account_media_stop_id'] && ($mediaMap['mapReverse'][$media] == $page['ig_account_media_stop_id'])) || !$hasResults) {
                                break;
                            }
                            $hasResults = self::processInstagramMedia(
                                pageEntity: $pageEntity,
                                postEntity: $postRepository->findOneBy(['id' => $media]),
                                accountEntity: $accountEntity,
                                channeledAccountEntity: $channeledAccountEntity,
                                api: $api,
                                manager: $manager,
                                logger: $logger,
                                mediaMap: $mediaMap,
                                pageMap: $pageMap,
                            );
                        }
                    } else {
                        $logger->info("Skipping Instagram media metrics for page: " . $page['id']);
                    }
                }
            }

            foreach ($config['facebook']['ad_accounts'] as $adAccount) {
                Helpers::checkJobStatus($jobId);

                $channeledAccountEntity = $channeledAccountRepository->findOneBy([
                    'platformId' => $adAccount['id'],
                    'account' => $accountEntity,
                ]);
                if ($adAccount['enabled']) {
                    $manager->getConnection()->beginTransaction();
                    try {
                        if ($adAccount['ad_account_metrics']) {
                            self::processAdAccount(
                                adAccount: $adAccount,
                                api: $api,
                                manager: $manager,
                                accountEntity: $accountEntity,
                                channeledAccountEntity: $channeledAccountEntity,
                                logger: $logger,
                                startDate: $startDate,
                                endDate: $endDate,
                            );
                        } else {
                            $logger->info("Skipping ad account metrics for ad account: " . $adAccount['id']);
                        }

                        if ($adAccount['campaigns']) {
                            $campaignsMultiMap = self::fetchAdAccountCampaigns(
                                api: $api,
                                manager: $manager,
                                logger: $logger,
                                channeledAccountEntity: $channeledAccountEntity,
                            );
                            $campaignMap = $campaignsMultiMap['campaignMap'];
                            $channeledCampaignMap = $campaignsMultiMap['channeledCampaignMap'];

                            if ($adAccount['campaign_metrics']) {
                                self::processCampaignsBulk(
                                    api: $api,
                                    manager: $manager,
                                    channeledAccountEntity: $channeledAccountEntity,
                                    logger: $logger,
                                    startDate: $startDate,
                                    endDate: $endDate,
                                    channeledCampaignMap: $channeledCampaignMap,
                                    campaignMap: $campaignMap,
                                    jobId: $jobId,
                                );
                            } else {
                                $logger->info("Skipping Campaign metrics for ad account: " . $adAccount['id']);
                            }

                            if ($adAccount['adsets']) {
                                $channeledAdGroupMap = self::fetchAdAccountAdsets(
                                    api: $api,
                                    manager: $manager,
                                    logger: $logger,
                                    channeledAccountEntity: $channeledAccountEntity,
                                    campaignMap: $campaignMap,
                                    channeledCampaignMap: $channeledCampaignMap,
                                );

                                if ($adAccount['adset_metrics']) {
                                    self::processAdsetsBulk(
                                        api: $api,
                                        manager: $manager,
                                        channeledAccountEntity: $channeledAccountEntity,
                                        logger: $logger,
                                        startDate: $startDate,
                                        endDate: $endDate,
                                        campaignMap: $campaignMap,
                                        channeledCampaignMap: $channeledCampaignMap,
                                        channeledAdGroupMap: $channeledAdGroupMap,
                                        jobId: $jobId,
                                    );
                                } else {
                                    $logger->info("Skipping Adset metrics for ad account: " . $adAccount['id']);
                                }

                                if ($adAccount['ads']) {
                                    $channeledAdMap = self::fetchAdAccountAds(
                                        api: $api,
                                        manager: $manager,
                                        logger: $logger,
                                        channeledAccountEntity: $channeledAccountEntity,
                                        channeledCampaignMap: $channeledCampaignMap,
                                        channeledAdGroupMap: $channeledAdGroupMap,
                                    );

                                    if ($adAccount['ad_metrics']) {
                                        self::processAdsBulk(
                                            api: $api,
                                            manager: $manager,
                                            channeledAccountEntity: $channeledAccountEntity,
                                            logger: $logger,
                                            startDate: $startDate,
                                            endDate: $endDate,
                                            campaignMap: $campaignMap,
                                            channeledCampaignMap: $channeledCampaignMap,
                                            channeledAdGroupMap: $channeledAdGroupMap,
                                            channeledAdMap: $channeledAdMap,
                                            jobId: $jobId,
                                        );
                                    } else {
                                        $logger->info("Skipping Ad metrics for ad account: " . $adAccount['id']);
                                    }
                                }
                            }
                        }
                        $manager->getConnection()->commit();
                    } catch (Exception $e) {
                        if ($manager->getConnection()->isTransactionActive()) {
                            $manager->getConnection()->rollBack();
                        }
                        $logger->error("Error processing ad account " . $adAccount['id'] . ": " . $e->getMessage() . " " . $e->getTraceAsString());
                    }
                }
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
        } catch (Exception $e) {
            try {
                if ($manager->getConnection()->isTransactionActive()) {
                    $manager->getConnection()->rollback();
                    $logger->info("Rolled back transaction");
                }
            } catch (Exception $rollbackException) {
                $logger->error("Error during transaction rollback: " . $rollbackException->getMessage());
            }
            $logger->error("Unexpected error in getListFromFacebook: " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
            throw $e;
        } catch (GuzzleException $e) {
            $logger->error("GuzzleException in getListFromFacebook: " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
            throw new Exception("GuzzleException in getListFromFacebook: " . $e->getMessage());
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
            $logger = new Logger('gsc');
            $logger->pushHandler(new StreamHandler('logs/gsc.log', Level::Info));
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
            $logger->warning("Note: 'searchAppearance' is not included in dimensions due to GSC API restrictions; defaulting to 'WEB' in ChanneledMetricDimension");

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
            foreach ($config['google_search_console']['sites'] as $site) {
                Helpers::checkJobStatus($jobId);

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
    private static function validateGoogleConfig(LoggerInterface $logger): array
    {
        return GoogleSearchConsoleHelpers::validateGoogleConfig($logger);
    }

    /**
     * Validates Google and GSC configurations.
     *
     * @param LoggerInterface $logger
     * @return array
     * @throws Exception
     */
    private static function validateFacebookConfig(LoggerInterface $logger): array
    {
        return FacebookGraphHelpers::validateFacebookConfig($logger);
    }

    /**
     * Initializes the SearchConsoleApi client with retry logic.
     *
     * @param array $config
     * @param LoggerInterface $logger
     * @return SearchConsoleApi
     * @throws Exception
     */
    private static function initializeSearchConsoleApi(array $config, LoggerInterface $logger): SearchConsoleApi
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

                $apiInstance = new SearchConsoleApi(
                    redirectUrl: $config['google_search_console']['redirect_uri'] ?? ($config['google']['redirect_uri'] ?? null),
                    clientId: $config['google_search_console']['client_id'] ?? ($config['google']['client_id'] ?? null),
                    clientSecret: $config['google_search_console']['client_secret'] ?? ($config['google']['client_secret'] ?? null),
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
    private static function initializeFacebookGraphApi(array $config, LoggerInterface $logger): FacebookGraphApi
    {
        $maxApiRetries = 3;
        $apiRetryCount = 0;
        while ($apiRetryCount < $maxApiRetries) {
            try {
                $apiInstance = new FacebookGraphApi(
                    userId: (string) $config['facebook']['user_id'],
                    appId: (string) $config['facebook']['app_id'],
                    appSecret: $config['facebook']['app_secret'],
                    redirectUrl: $config['facebook']['app_redirect_uri'],
                    userAccessToken: $config['facebook']['graph_user_access_token'] ?? '',
                    longLivedUserAccessToken: $config['facebook']['graph_long_lived_user_access_token'] ?? '',
                    appAccessToken: $config['facebook']['graph_app_access_token'] ?? '',
                    pageAccesstoken: $config['facebook']['graph_page_access_token'] ?? '',
                    longLivedPageAccesstoken: $config['facebook']['graph_long_lived_page_access_token'] ?? '',
                    clientAccesstoken: $config['facebook']['graph_client_access_token'] ?? '',
                    longLivedClientAccesstoken: $config['facebook']['graph_long_lived_client_access_token'] ?? '',
                    tokenPath: $config['facebook']['graph_token_path'] ?? '',
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
        $pageEntity = $pageRepository->findOneBy(['url' => $normalizedSiteUrl]);
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
            $rows = $api->getFacebookPageInsights(
                pageId: (string) $page['id'],
                since: $startDate ?: Carbon::today()->subMonths(3)->format('Y-m-d'),
                until: $endDate ?: Carbon::today()->format('Y-m-d'),
            );

            if (count($rows['data']) === 0) {
                $logger->info("No rows found for page " . $page['id']);
                return;
            }

            $metrics = FacebookGraphConvert::pageMetrics(
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
                MetricsProcessor::processChanneledMetricDimensions(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricMap: $metricMap,
                    channeledMetricMap: $channeledMetricMap,
                    logger: $logger,
                );

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
    ): void {

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

            $rows = $api->getAdAccountInsights(
                adAccountId: (string) $adAccount['id'],
                metricBreakdown: [MetricBreakdown::AGE, MetricBreakdown::GENDER],
                additionalParams: $additionalParams,
                metricSet: MetricSet::KEY,
            );

            if (count($rows['data']) === 0) {
                $logger->info("No rows found for page " . $adAccount['id']);
                return;
            }

            $metrics = FacebookGraphConvert::adAccountMetrics(
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
                return;
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
                MetricsProcessor::processChanneledMetricDimensions(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricMap: $metricMap,
                    channeledMetricMap: $channeledMetricMap,
                    logger: $logger,
                );
                $manager->getConnection()->commit();
            } catch (Exception $e) {
                if ($manager->getConnection()->isTransactionActive()) {
                    $manager->getConnection()->rollback();
                }
                throw $e;
            }

            $logger->info("Completed Meta ad account insights request");

            return;
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
    ): void {

        if (!$startDate) {
            $startDate = Carbon::today()->endOfDay()->subYears(2); // Default to 2 years ago at the end of the day
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
            'channel' => Channel::facebook->value,
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
                    $insights = $api->getDailyInstagramAccountTotalValueInsights(
                        instagramAccountId: (string) $page['ig_account'],
                        since: $startDate->format('Y-m-d'),
                        option: $option,
                    );
                    if (isset($insights['data']) && count($insights['data']) > 0) {
                        $rows['data'] = [
                            ...$rows['data'],
                            ...$insights['data']
                        ];
                    }
                    $option++;
                }
                $metrics = FacebookGraphConvert::igAccountMetrics(
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
                MetricsProcessor::processChanneledMetricDimensions(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricMap: $metricMap,
                    channeledMetricMap: $channeledMetricMap,
                    logger: $logger,
                );
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
            $insights = $api->getInstagramMediaInsights(
                mediaId: $postEntity->getPostId(),
                mediaType: MediaType::from($mediaMap['mapData'][$postEntity->getPostId()]),
            );

            if (count($insights['data']) === 0) {
                $logger->info("No insights found for post " . $postEntity->getPostId());
                return false;
            }

            $metrics = FacebookGraphConvert::igMediaMetrics(
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
                MetricsProcessor::processChanneledMetricDimensions(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricMap: $metricMap,
                    channeledMetricMap: $channeledMetricMap,
                    logger: $logger,
                );
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
            $rows = $api->getCampaignInsights(
                campaignId: $campaignPlatformId,
            );

            if (count($rows['data']) === 0) {
                $logger->info("No rows found for campaign " . $campaignPlatformId);
                return false;
            }

            $metrics = FacebookGraphConvert::campaignMetrics(
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
                MetricsProcessor::processChanneledMetricDimensions(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricMap: $metricMap,
                    channeledMetricMap: $channeledMetricMap,
                    logger: $logger,
                );
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
            $rows = $api->getAdsetInsights(
                adsetId: $adsetPlatformId,
            );

            // Helpers::dumpDebugJson($rows['data']);

            if (count($rows['data']) === 0) {
                $logger->info("No rows found for adset " . $adsetPlatformId);
                return false;
            }

            $metrics = FacebookGraphConvert::adsetMetrics(
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
                MetricsProcessor::processChanneledMetricDimensions(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricMap: $metricMap,
                    channeledMetricMap: $channeledMetricMap,
                    logger: $logger,
                );
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
            $rows = $api->getAdInsights(
                adId: $adPlatformId,
            );

            // Helpers::dumpDebugJson($rows['data']);

            if (count($rows['data']) === 0) {
                $logger->info("No rows found for ad " . $adPlatformId);
                return false;
            }

            $metrics = FacebookGraphConvert::adMetrics(
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
                MetricsProcessor::processChanneledMetricDimensions(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricMap: $metricMap,
                    channeledMetricMap: $channeledMetricMap,
                    logger: $logger,
                );
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
    private static function fetchFacebookPagePosts(
        array $page,
        FacebookGraphApi $api,
        EntityManager $manager,
        LoggerInterface $logger,
        Page $pageEntity,
        Account $accountEntity,
    ): array {
        $posts = $api->getFacebookPosts(
            pageId: (string) $page['id'],
        );

        // Re-fetch inserted metrics to get correct IDs
        $params = [];
        $conditions = [];

        $fields = ['postId', 'page_id', 'account_id'];

        foreach ($posts['data'] as $post) {
            $platformId = $post['id'];
            $subConditions = [];
            foreach ($fields as $field) {
                $subConditions[] = "$field = ?";
                $params[] = match($field) {
                    'postId' => $platformId,
                    'page_id' => $pageEntity->getId(),
                    'account_id' => $accountEntity->getId(),
                };
            }
            $conditions[] = '(' . implode(' AND ', $subConditions) . ')';
        }

        $sql = "SELECT id, " . implode(', ', $fields) . "
                            FROM posts
                            WHERE " . (empty($conditions) ? '1=0' : implode(' OR ', $conditions));

        $postMap = MapGenerator::getPostMap(
            manager: $manager,
            sql: $sql,
            params: $params,
        );
        $manager->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();

        // Get the list of metrics that need to be inserted
        $postsToInsert = [];
        foreach ($posts['data'] as $post) {
            $platformId = $post['id'];
            if (!isset($postMap[$platformId])) {
                $postsToInsert[] = [
                    'postId' => $platformId,
                    'page_id' => $pageEntity->getId(),
                    'account_id' => $accountEntity->getId(),
                    'data' => $post,
                    'key' => $platformId,
                ];
            }
        }

        if (!empty($postsToInsert)) {
            $insertParams = [];
            foreach ($postsToInsert as $row) {
                $insertParams[] = $row['postId'];
                $insertParams[] = $row['page_id'];
                $insertParams[] = $row['account_id'];
                $insertParams[] = json_encode($row['data']);
            }
            // $logger->info("Inserting " . count($metricsToInsert) . " new metrics");
            $manager->getConnection()->executeStatement(
                'INSERT INTO posts (postId, page_id, account_id, data)
                     VALUES ' . implode(', ', array_fill(0, count($postsToInsert), '(?, ?, ?, ?)')),
                $insertParams
            );

            // Re-fetch inserted metrics to get correct IDs
            $reFetchParams = [];
            $conditions = [];

            $fields = ['postId', 'page_id', 'account_id'];

            foreach ($posts['data'] as $post) {
                $platformId = $post['id'];
                $subConditions = [];
                foreach ($fields as $field) {
                    $subConditions[] = "$field = ?";
                    $reFetchParams[] = match($field) {
                        'postId' => $platformId,
                        'page_id' => $pageEntity->getId(),
                        'account_id' => $accountEntity->getId(),
                    };
                }
                $conditions[] = '(' . implode(' AND ', $subConditions) . ')';
            }

            $reFetchSql = "SELECT id, " . implode(', ', $fields) . "
                            FROM posts
                            WHERE " . (empty($conditions) ? '1=0' : implode(' OR ', $conditions));

            $newPosts = $manager->getConnection()->executeQuery($reFetchSql, $reFetchParams)->fetchAllAssociative();

            foreach ($newPosts as $newPost) {
                $postMap[$newPost['postId']] = $newPost['id'];
            }
        }

        return [
            'map' => $postMap,
            'mapReverse' => array_flip($postMap),
        ];
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
     * @param ChanneledAccount $channeledAccountEntity
     * @return array
     * @throws GuzzleException
     * @throws \Doctrine\DBAL\Exception
     * @throws Exception
     */
    private static function fetchInstagramAccountMedia(
        array $page,
        FacebookGraphApi $api,
        EntityManager $manager,
        LoggerInterface $logger,
        Page $pageEntity,
        Account $accountEntity,
        ChanneledAccount $channeledAccountEntity,
    ): array {
        $posts = $api->getInstagramMedia(
            igUserId: (string) $page['ig_account'],
        );

        // Re-fetch inserted metrics to get correct IDs
        $params = [];
        $conditions = [];

        $fields = ['postId', 'page_id', 'account_id', 'channeledAccount_id'];

        foreach ($posts['data'] as $post) {
            $platformId = $post['id'];
            $subConditions = [];
            foreach ($fields as $field) {
                $subConditions[] = "$field = ?";
                $params[] = match($field) {
                    'postId' => $platformId,
                    'page_id' => $pageEntity->getId(),
                    'account_id' => $accountEntity->getId(),
                    'channeledAccount_id' => $channeledAccountEntity->getId(),
                };
            }
            $conditions[] = '(' . implode(' AND ', $subConditions) . ')';
        }

        $sql = "SELECT id, " . implode(', ', $fields) . "
                            FROM posts
                            WHERE " . (empty($conditions) ? '1=0' : implode(' OR ', $conditions));

        $postMap = MapGenerator::getPostMap(
            manager: $manager,
            sql: $sql,
            params: $params,
        );

        // Get the list of metrics that need to be inserted
        $postsToInsert = [];
        foreach ($posts['data'] as $post) {
            $platformId = $post['id'];
            if (!isset($postMap[$platformId])) {
                $postsToInsert[] = [
                    'postId' => $platformId,
                    'page_id' => $pageEntity->getId(),
                    'account_id' => $accountEntity->getId(),
                    'channeledAccount_id' => $channeledAccountEntity->getId(),
                    'data' => $post,
                    'key' => $platformId,
                ];
            }
        }

        if (!empty($postsToInsert)) {
            $insertParams = [];
            foreach ($postsToInsert as $row) {
                $insertParams[] = $row['postId'];
                $insertParams[] = $row['page_id'];
                $insertParams[] = $row['account_id'];
                $insertParams[] = $row['channeledAccount_id'];
                $insertParams[] = json_encode($row['data']);
            }
            // $logger->info("Inserting " . count($metricsToInsert) . " new metrics");
            $manager->getConnection()->executeStatement(
                'INSERT INTO posts (postId, page_id, account_id, channeledAccount_id, data)
                     VALUES ' . implode(', ', array_fill(0, count($postsToInsert), '(?, ?, ?, ?, ?)')),
                $insertParams
            );

            // Re-fetch inserted metrics to get correct IDs
            $reFetchParams = [];
            $conditions = [];

            $fields = ['postId', 'page_id', 'account_id', 'channeledAccount_id'];

            foreach ($posts['data'] as $post) {
                $platformId = $post['id'];
                $subConditions = [];
                foreach ($fields as $field) {
                    $subConditions[] = "$field = ?";
                    $reFetchParams[] = match($field) {
                        'postId' => $platformId,
                        'page_id' => $pageEntity->getId(),
                        'account_id' => $accountEntity->getId(),
                        'channeledAccount_id' => $channeledAccountEntity->getId(),
                    };
                }
                $conditions[] = '(' . implode(' AND ', $subConditions) . ')';
            }

            $reFetchSql = "SELECT id, " . implode(', ', $fields) . "
                            FROM posts
                            WHERE " . (empty($conditions) ? '1=0' : implode(' OR ', $conditions));

            $newPosts = $manager->getConnection()->executeQuery($reFetchSql, $reFetchParams)->fetchAllAssociative();

            foreach ($newPosts as $newPost) {
                $postMap[$newPost['postId']] = $newPost['id'];
            }
        }

        return [
            'map' => $postMap,
            'mapData' => array_column($posts['data'], 'media_type', 'id'),
            'mapReverse' => array_flip($postMap),
        ];
    }

    /**
     * Processes a single site, including page lookup and data fetching.
     *
     * @param FacebookGraphApi $api
     * @param EntityManager $manager
     * @param LoggerInterface $logger
     * @param ChanneledAccount $channeledAccountEntity
     * @return array
     * @throws GuzzleException
     * @throws \Doctrine\DBAL\Exception
     * @throws Exception
     */
    private static function fetchAdAccountCampaigns(
        FacebookGraphApi $api,
        EntityManager $manager,
        LoggerInterface $logger,
        ChanneledAccount $channeledAccountEntity,
    ): array {

        $campaigns = $api->getCampaigns(
            adAccountId: $channeledAccountEntity->getPlatformId(),
        );

        // Re-fetch inserted metrics to get correct IDs
        $campaignParams = [];
        $campaignConditions = [];

        $campaignFields = ['campaignId', 'name'];

        foreach ($campaigns['data'] as $campaign) {
            $subConditions = [];
            foreach ($campaignFields as $field) {
                $subConditions[] = "$field = ?";
                $campaignParams[] = match($field) {
                    'campaignId' => $campaign['id'],
                    'name' => $campaign['name'],
                };
            }
            $campaignConditions[] = '(' . implode(' AND ', $subConditions) . ')';
        }

        $sqlCampaign = "SELECT id, " . implode(', ', $campaignFields) . "
                            FROM campaigns
                            WHERE " . (empty($campaignConditions) ? '1=0' : implode(' OR ', $campaignConditions));

        $campaignMap = MapGenerator::getCampaignMap(
            manager: $manager,
            sql: $sqlCampaign,
            params: $campaignParams,
        );

        // Get the list of campaigns that need to be inserted
        $campaignsToInsert = [];
        foreach ($campaigns['data'] as $campaign) {
            $platformId = $campaign['id'];
            if (!isset($campaignMap[$platformId])) {
                $campaignsToInsert[] = [
                    'campaignId' => $campaign['id'],
                    'name' => $campaign['name'],
                    'startDate' => Carbon::parse($campaign['start_time'])->toDateTimeString(),
                    'endDate' => Carbon::parse($campaign['stop_time'])->toDateTimeString(),
                    'objective' => CampaignObjective::from($campaign['objective'])->value,
                    'budget' => (float) ($campaign['lifetime_budget'] ?? 0),
                    'status' => CampaignStatus::from($campaign['status'])->value,
                    'buyingType' => CampaignBuyingType::from($campaign['buying_type'])->value,
                    'data' => json_encode($campaign),
                    'key' => $platformId,
                ];
            }
        }

        if (!empty($campaignsToInsert)) {
            $insertParams = [];
            foreach ($campaignsToInsert as $row) {
                $insertParams[] = $row['campaignId'];
                $insertParams[] = $row['name'];
                $insertParams[] = $row['startDate'];
                $insertParams[] = $row['endDate'];
            }
            // $logger->info("Inserting " . count($metricsToInsert) . " new metrics");
            $manager->getConnection()->executeStatement(
                'INSERT INTO campaigns (campaignId, name, startDate, endDate)
                     VALUES ' . implode(', ', array_fill(0, count($campaignsToInsert), '(?, ?, ?, ?)')),
                $insertParams
            );

            // Re-fetch inserted metrics to get correct IDs
            $reFetchCampaignParams = [];
            $campaignConditions = [];

            $campaignFields = ['campaignId', 'name'];

            foreach ($campaigns['data'] as $campaign) {
                $subConditions = [];
                foreach ($campaignFields as $field) {
                    $subConditions[] = "$field = ?";
                    $reFetchCampaignParams[] = match($field) {
                        'campaignId' => $campaign['id'],
                        'name' => $campaign['name'],
                    };
                }
                $campaignConditions[] = '(' . implode(' AND ', $subConditions) . ')';
            }

            $reFetchCampaignSql = "SELECT id, " . implode(', ', $campaignFields) . "
                            FROM campaigns
                            WHERE " . (empty($campaignConditions) ? '1=0' : implode(' OR ', $campaignConditions));

            $channeledCampaigns = $manager->getConnection()->executeQuery($reFetchCampaignSql, $reFetchCampaignParams)->fetchAllAssociative();

            foreach ($channeledCampaigns as $newCampaign) {
                $campaignMap[$newCampaign['campaignId']] = $newCampaign['id'];
            }
        }

        $campaignMap = [
            'map' => $campaignMap,
            'mapReverse' => array_flip($campaignMap),
        ];

        // Re-fetch inserted metrics to get correct IDs
        $channeledCampaignParams = [];
        $channeledCampaignConditions = [];

        $channeledCampaignFields = ['campaign_id', 'platformId', 'channeledAccount_id'];

        foreach ($campaigns['data'] as $campaign) {
            $subConditions = [];
            foreach ($channeledCampaignFields as $field) {
                $subConditions[] = "$field = ?";
                $channeledCampaignParams[] = match($field) {
                    'campaign_id' => $campaignMap['map'][$campaign['id']],
                    'platformId' => $campaign['id'],
                    'channeledAccount_id' => $channeledAccountEntity->getId(),
                };
            }
            $channeledCampaignConditions[] = '(' . implode(' AND ', $subConditions) . ')';
        }

        $sqlChanneledCampaign = "SELECT id, " . implode(', ', $channeledCampaignFields) . "
                            FROM channeled_campaigns
                            WHERE " . (empty($channeledCampaignConditions) ? '1=0' : implode(' OR ', $channeledCampaignConditions));

        $channeledCampaignMap = MapGenerator::getChanneledCampaignMap(
            manager: $manager,
            sql: $sqlChanneledCampaign,
            params: $channeledCampaignParams,
        );

        // Use the same list of campaigns to insert, since campaigns and channeled_campaigns go 1 - 1 for Meta
        if (!empty($campaignsToInsert)) {
            $insertParams = [];
            foreach ($campaignsToInsert as $row) {
                $insertParams[] = $campaignMap['map'][$row['campaignId']];
                $insertParams[] = $row['campaignId'];
                $insertParams[] = Channel::facebook->value;
                $insertParams[] = $row['startDate'];
                $insertParams[] = $row['objective'];
                $insertParams[] = $row['budget'];
                $insertParams[] = $row['status'];
                $insertParams[] = $row['buyingType'];
                $insertParams[] = $row['data'];
                $insertParams[] = $channeledAccountEntity->getId();
            }
            // $logger->info("Inserting " . count($metricsToInsert) . " new metrics");
            $manager->getConnection()->executeStatement(
                'INSERT INTO channeled_campaigns (campaign_id, platformId, channel, platformCreatedAt, objective, budget, status, buyingType, data, channeledAccount_id)
                     VALUES ' . implode(', ', array_fill(0, count($campaignsToInsert), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')),
                $insertParams
            );

            // Re-fetch inserted metrics to get correct IDs
            $fetchChanneledCampaignParams = [];
            $channeledCampaignConditions = [];

            $channeledCampaignFields = ['campaign_id', 'platformId', 'channeledAccount_id'];

            foreach ($campaigns['data'] as $campaign) {
                $subConditions = [];
                foreach ($channeledCampaignFields as $field) {
                    $subConditions[] = "$field = ?";
                    $fetchChanneledCampaignParams[] = match($field) {
                        'campaign_id' => $campaignMap['map'][$campaign['id']],
                        'platformId' => $campaign['id'],
                        'channeledAccount_id' => $channeledAccountEntity->getId(),
                    };
                }
                $channeledCampaignConditions[] = '(' . implode(' AND ', $subConditions) . ')';
            }

            $channeledCampaignSql = "SELECT id, " . implode(', ', $channeledCampaignFields) . "
                            FROM channeled_campaigns
                            WHERE " . (empty($channeledCampaignConditions) ? '1=0' : implode(' OR ', $channeledCampaignConditions));

            $channeledCampaigns = $manager->getConnection()->executeQuery($channeledCampaignSql, $fetchChanneledCampaignParams)->fetchAllAssociative();

            foreach ($channeledCampaigns as $channeledCampaign) {
                $channeledCampaignMap[$channeledCampaign['platformId']] = $channeledCampaign['id'];
            }
        }

        $channeledCampaignMap = [
            'map' => $channeledCampaignMap,
            'mapReverse' => array_flip($channeledCampaignMap),
        ];

        return [
            'campaignMap' => $campaignMap,
            'channeledCampaignMap' => $channeledCampaignMap,
        ];
    }

    /**
     * Processes a single site, including page lookup and data fetching.
     *
     * @param FacebookGraphApi $api
     * @param EntityManager $manager
     * @param LoggerInterface $logger
     * @param ChanneledAccount $channeledAccountEntity
     * @param array $campaignMap
     * @param array $channeledCampaignMap
     * @return array
     * @throws GuzzleException
     * @throws \Doctrine\DBAL\Exception
     * @throws Exception
     */
    private static function fetchAdAccountAdsets(
        FacebookGraphApi $api,
        EntityManager $manager,
        LoggerInterface $logger,
        ChanneledAccount $channeledAccountEntity,
        array $campaignMap,
        array $channeledCampaignMap,
    ): array {
        $adsets = $api->getAdsets(
            adAccountId: $channeledAccountEntity->getPlatformId(),
        );

        // Re-fetch inserted metrics to get correct IDs
        $adsetParams = [];
        $adsetConditions = [];

        $adsetFields = ['channeledAccount_id', 'campaign_id', 'platformId', 'channeledCampaign_id'];

        foreach ($adsets['data'] as $adset) {
            $subConditions = [];
            foreach ($adsetFields as $field) {
                $subConditions[] = "$field = ?";
                $adsetParams[] = match($field) {
                    'channeledAccount_id' => $channeledAccountEntity->getId(),
                    'campaign_id' => $campaignMap['map'][$adset['campaign_id']],
                    'platformId' => $adset['id'],
                    'channeledCampaign_id' => $channeledCampaignMap['map'][$adset['campaign_id']],
                };
            }
            $adsetConditions[] = '(' . implode(' AND ', $subConditions) . ')';
        }

        $sqlAdset = "SELECT id, " . implode(', ', $adsetFields) . "
                            FROM channeled_ad_groups
                            WHERE " . (empty($adsetConditions) ? '1=0' : implode(' OR ', $adsetConditions));

        /* Helpers::dumpDebugJson([
            'sql' => $sqlAdset,
            'params' => $adsetParams,
            'conditions' => $adsetConditions,
        ]); */

        // CHECK EMPTINESS

        $adsetMap = MapGenerator::getChanneledAdGroupMap(
            manager: $manager,
            sql: $sqlAdset,
            params: $adsetParams,
        );

        // Helpers::dumpDebugJson($adsetMap);

        // Get the list of adsets that need to be inserted
        $adsetsToInsert = [];
        foreach ($adsets['data'] as $adset) {
            $platformId = $adset['id'];
            if (!isset($adsetMap[$platformId])) {
                $adsetsToInsert[] = [
                    'channeledAccount_id' => $channeledAccountEntity->getId(),
                    'campaign_id' => $campaignMap['map'][$adset['campaign_id']],
                    'name' => $adset['name'],
                    'platformId' => $platformId,
                    'channel' => Channel::facebook->value,
                    'startDate' => Carbon::parse($adset['start_time'])->toDateTimeString(),
                    'endDate' => Carbon::parse($adset['end_time'])->toDateTimeString(),
                    'platformCreatedAt' => Carbon::parse($adset['created_time'])->toDateTimeString(),
                    'optimizationGoal' => OptimizationGoal::from($adset['optimization_goal'])->value,
                    'status' => CampaignStatus::from($adset['status'])->value,
                    'billingEvent' => BillingEvent::from($adset['billing_event'])->value,
                    'targeting' => json_encode($adset['targeting']),
                    'channeledCampaign_id' => $channeledCampaignMap['map'][$adset['campaign_id']],
                    'data' => json_encode($adset),
                    'key' => $platformId,
                ];
            }
        }

        /* Helpers::dumpDebugJson([
            'adsetsToInsert' => $adsetsToInsert,
        ]); */

        if (!empty($adsetsToInsert)) {
            $insertParams = [];
            foreach ($adsetsToInsert as $row) {
                $insertParams[] = $row['channeledAccount_id'];
                $insertParams[] = $row['campaign_id'];
                $insertParams[] = $row['name'];
                $insertParams[] = $row['platformId'];
                $insertParams[] = $row['channel'];
                $insertParams[] = $row['startDate'];
                $insertParams[] = $row['endDate'];
                $insertParams[] = $row['platformCreatedAt'];
                $insertParams[] = $row['optimizationGoal'];
                $insertParams[] = $row['status'];
                $insertParams[] = $row['billingEvent'];
                $insertParams[] = $row['targeting'];
                $insertParams[] = $row['channeledCampaign_id'];
                $insertParams[] = $row['data'];
            }
            // $logger->info("Inserting " . count($metricsToInsert) . " new metrics");
            $manager->getConnection()->executeStatement(
                'INSERT INTO channeled_ad_groups (channeledAccount_id, campaign_id, name, platformId, channel, startDate, endDate, platformCreatedAt, optimizationGoal, status, billingEvent, targeting, channeledCampaign_id, data)
                     VALUES ' . implode(', ', array_fill(0, count($adsetsToInsert), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')),
                $insertParams
            );

            // Re-fetch inserted metrics to get correct IDs
            $reFetchAdsetParams = [];
            $adsetConditions = [];

            $adsetFields = ['channeledAccount_id', 'campaign_id', 'platformId', 'channeledCampaign_id'];

            foreach ($adsets['data'] as $adset) {
                $subConditions = [];
                foreach ($adsetFields as $field) {
                    $subConditions[] = "$field = ?";
                    $reFetchAdsetParams[] = match($field) {
                        'channeledAccount_id' => $channeledAccountEntity->getId(),
                        'campaign_id' => $campaignMap['map'][$adset['campaign_id']],
                        'platformId' => $adset['id'],
                        'channeledCampaign_id' => $channeledCampaignMap['map'][$adset['campaign_id']],
                    };
                }
                $adsetConditions[] = '(' . implode(' AND ', $subConditions) . ')';
            }

            $reFetchAdsetSql = "SELECT id, " . implode(', ', $adsetFields) . "
                            FROM channeled_ad_groups
                            WHERE " . (empty($adsetConditions) ? '1=0' : implode(' OR ', $adsetConditions));

            $channeledAdGroups = $manager->getConnection()->executeQuery($reFetchAdsetSql, $reFetchAdsetParams)->fetchAllAssociative();

            /* Helpers::dumpDebugJson([
                'channeledAdGroups' => $channeledAdGroups,
            ]); */

            foreach ($channeledAdGroups as $newAdGroup) {
                $adsetMap[$newAdGroup['platformId']] = $newAdGroup['id'];
            }
        }

        return [
            'map' => $adsetMap,
            'mapCampaign' => array_column($adsets['data'], 'campaign_id', 'id'),
            'mapReverse' => array_flip($adsetMap),
        ];
    }

    /**
     * Processes a single site, including page lookup and data fetching.
     *
     * @param FacebookGraphApi $api
     * @param EntityManager $manager
     * @param LoggerInterface $logger
     * @param ChanneledAccount $channeledAccountEntity
     * @param array $channeledCampaignMap
     * @param array $channeledAdGroupMap
     * @return array
     * @throws GuzzleException
     * @throws \Doctrine\DBAL\Exception
     * @throws Exception
     */
    private static function fetchAdAccountAds(
        FacebookGraphApi $api,
        EntityManager $manager,
        LoggerInterface $logger,
        ChanneledAccount $channeledAccountEntity,
        array $channeledCampaignMap,
        array $channeledAdGroupMap,
    ): array {
        $ads = $api->getAds(
            adAccountId: $channeledAccountEntity->getPlatformId(),
        );

        // Re-fetch inserted metrics to get correct IDs
        $adParams = [];
        $adConditions = [];

        $adFields = ['platformId', 'channeledCampaign_id', 'channeledAdGroup_id'];

        foreach ($ads['data'] as $ad) {
            $subConditions = [];
            foreach ($adFields as $field) {
                if (!isset($channeledAdGroupMap['map'][$ad['adset_id']])) {
                    throw new Exception("Ad Set ID {$ad['adset_id']} not found in local mapping during Ad sync for account {$channeledAccountEntity->getPlatformId()}. Ad: {$ad['id']}");
                }
                $subConditions[] = "$field = ?";
                $adParams[] = match($field) {
                    'platformId' => $ad['id'],
                    'channeledCampaign_id' => $channeledCampaignMap['map'][$ad['campaign_id']],
                    'channeledAdGroup_id' => $channeledAdGroupMap['map'][$ad['adset_id']],
                };
            }
            $adConditions[] = '(' . implode(' AND ', $subConditions) . ')';
        }

        $sqlAd = "SELECT id, " . implode(', ', $adFields) . "
                            FROM channeled_ads
                            WHERE " . (empty($adConditions) ? '1=0' : implode(' OR ', $adConditions));

        $adMap = MapGenerator::getChanneledAdMap(
            manager: $manager,
            sql: $sqlAd,
            params: $adParams,
        );

        // Get the list of adsets that need to be inserted
        $adsToInsert = [];
        foreach ($ads['data'] as $ad) {
            $platformId = $ad['id'];
            if (!isset($adMap[$platformId])) {
                $adsToInsert[] = [
                    'name' => $ad['name'],
                    'platformId' => $platformId,
                    'channel' => Channel::facebook->value,
                    'platformCreatedAt' => Carbon::parse($ad['created_time'])->toDateTimeString(),
                    'status' => CampaignStatus::from($ad['status'])->value,
                    'channeledCampaign_id' => $channeledCampaignMap['map'][$ad['campaign_id']],
                    'channeledAdGroup_id' => $channeledAdGroupMap['map'][$ad['adset_id']],
                    'data' => json_encode($ad),
                    'key' => $platformId,
                ];
            }
        }

        if (!empty($adsToInsert)) {
            $insertParams = [];
            foreach ($adsToInsert as $row) {
                $insertParams[] = $row['name'];
                $insertParams[] = $row['platformId'];
                $insertParams[] = $row['channel'];
                $insertParams[] = $row['platformCreatedAt'];
                $insertParams[] = $row['status'];
                $insertParams[] = $row['channeledCampaign_id'];
                $insertParams[] = $row['channeledAdGroup_id'];
                $insertParams[] = $row['data'];
            }
            // $logger->info("Inserting " . count($metricsToInsert) . " new metrics");
            $manager->getConnection()->executeStatement(
                'INSERT INTO channeled_ads (name, platformId, channel, platformCreatedAt, status, channeledCampaign_id, channeledAdGroup_id, data)
                     VALUES ' . implode(', ', array_fill(0, count($adsToInsert), '(?, ?, ?, ?, ?, ?, ?, ?)')),
                $insertParams
            );

            // Re-fetch inserted metrics to get correct IDs
            $reFetchAdParams = [];
            $adConditions = [];

            $adFields = ['platformId', 'channeledCampaign_id', 'channeledAdGroup_id'];

            foreach ($ads['data'] as $ad) {
                $subConditions = [];
                foreach ($adFields as $field) {
                    $subConditions[] = "$field = ?";
                    $reFetchAdParams[] = match($field) {
                        'platformId' => $ad['id'],
                        'channeledCampaign_id' => $channeledCampaignMap['map'][$ad['campaign_id']],
                        'channeledAdGroup_id' => $channeledAdGroupMap['map'][$ad['adset_id']],
                    };
                }
                $adConditions[] = '(' . implode(' AND ', $subConditions) . ')';
            }

            $reFetchAdSql = "SELECT id, " . implode(', ', $adFields) . "
                            FROM channeled_ads
                            WHERE " . (empty($adConditions) ? '1=0' : implode(' OR ', $adConditions));

            $channeledAds = $manager->getConnection()->executeQuery($reFetchAdSql, $reFetchAdParams)->fetchAllAssociative();

            foreach ($channeledAds as $newAd) {
                $adMap[$newAd['platformId']] = $newAd['id'];
            }
        }

        return [
            'map' => $adMap,
            'mapCampaign' => array_column($ads['data'], 'campaign_id', 'id'),
            'mapAdGroup' => array_column($ads['data'], 'adset_id', 'id'),
            'mapReverse' => array_flip($adMap),
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
            $rows = $api->getFacebookPostInsights(
                postId: $postEntity->getPostId(),
            );

            if (count($rows['data']) === 0) {
                $logger->info("No rows found for post " . $postEntity->getPostId());
                return false;
            }

            $metrics = FacebookGraphConvert::pageMetrics(
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
                MetricsProcessor::processChanneledMetricDimensions(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricMap: $metricMap,
                    channeledMetricMap: $channeledMetricMap,
                    logger: $logger,
                );
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
                $rows = $api->getAllSearchQueryResults(
                    siteUrl: $siteUrl,
                    startDate: $dayStr,
                    endDate: $dayStr,
                    rowLimit: $rowLimit,
                    dimensions: $actualDimensionsSubset,
                    dimensionFilterGroups: $dimensionFilterGroups,
                );
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
                MetricsProcessor::processChanneledMetricDimensions(
                    metrics: $allMetrics,
                    manager: $manager,
                    metricMap: $metricMap,
                    channeledMetricMap: $channeledMetricMap,
                    logger: $logger,
                );
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
            $connection->executeStatement("
            UPDATE metrics m
            JOIN metric_configs mc ON mc.id = m.metricConfig_id
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
                JOIN metric_configs mc ON mc.id = m.metricConfig_id
                WHERE cm.channel = :channel
                AND cm.platformCreatedAt LIKE :date
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
        ", [
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
                dimensionRepository: $repos['channeledMetricDimension'],
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
            usleep($retryCount * 100000);
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
    public static function process(ArrayCollection $channeledCollection, LoggerInterface $logger = null): Response
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
            $logger = new Logger('gsc');
            $logger->pushHandler(new StreamHandler('logs/gsc.log', Level::Info));
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
                    self::processBatch($batch, $manager, $repos, $entitiesToInvalidate, $queryCache, $metricCache, $channeledMetricCache, $dimensionCache, $logger);
                    $batchTime = microtime(true) - $batchStart;
                    $logger->info("Completed batch " . $batchCount . ", took " . $batchTime . " seconds");
                    $batch = [];
                    $manager->flush();
                    $manager->clear(Metric::class);
                    $manager->clear(ChanneledMetric::class);
                    $manager->clear(ChanneledMetricDimension::class);
                    $manager->clear(Query::class);
                    gc_collect_cycles();
                }
            }

            if (!empty($batch)) {
                $batchCount++;
                $batchStart = microtime(true);
                $logger->info("Processing final batch " . $batchCount . " (" . count($batch) . " records)");
                self::processBatch($batch, $manager, $repos, $entitiesToInvalidate, $queryCache, $metricCache, $channeledMetricCache, $dimensionCache, $logger);
                $batchTime = microtime(true) - $batchStart;
                $logger->info("Completed final batch " . $batchCount . ", took " . $batchTime . " seconds");
                $manager->flush();
                $manager->clear(Metric::class);
                $manager->clear(ChanneledMetric::class);
                $manager->clear(ChanneledMetricDimension::class);
                $manager->clear(Query::class);
                gc_collect_cycles();
            }

            $logger->info("Updating metrics values");
            $connection = $manager->getConnection();
            $connection->executeStatement("
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
                    JOIN metric_configs mc ON m.metricConfig_id = mc.id
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
            ", ['channel' => Channel::google_search_console->value]);

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
            'channeledMetricDimension' => $manager->getRepository(ChanneledMetricDimension::class),
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
     * @param ChanneledMetricDimensionRepository $dimensionRepository
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
        ChanneledMetricDimensionRepository $dimensionRepository,
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
                    $dimensionRepository,
                    $dimensionsToPersist,
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
     * @param ChanneledMetricDimensionRepository $dimensionRepository
     * @param array $dimensionsToPersist
     * @param EntityManager $manager
     * @return array
     * @throws ORMException
     */
    protected static function processDimensions(
        object $channeledMetric,
        LoggerInterface $logger,
        mixed $channeledMetricEntity,
        array $dimensionCache,
        ChanneledMetricDimensionRepository $dimensionRepository,
        array $dimensionsToPersist,
        EntityManager $manager
    ): array {
        // Process dimensions
        if (isset($channeledMetric->dimensions)) {
            foreach ($channeledMetric->dimensions as $dimensionData) {
                $dimensionKey = $dimensionData['dimensionKey'] ?? null;
                $dimensionValue = $dimensionData['dimensionKey'] ?? null;
                if (!$dimensionKey || !$dimensionValue || in_array($dimensionKey, ['site', 'country', 'device'])) {
                    $logger->warning("Skipping invalid dimension: key=" . ($dimensionKey ?? 'null') . ", value=" . ($dimensionValue ?? 'null') . " for ChanneledMetric ID={$channeledMetricEntity->getId()}");
                    continue;
                }
                $dimCacheKey = md5($channeledMetricEntity->getId() . $dimensionKey . $dimensionValue);
                if (!isset($dimensionCache[$dimCacheKey])) {
                    $existingDimension = $dimensionRepository->findOneByKeyValueAndMetric(
                        $dimensionKey,
                        $dimensionValue,
                        $channeledMetricEntity
                    );
                    if ($existingDimension) {
                        $dimensionCache[$dimCacheKey] = $existingDimension;
                        $logger->info("Found ChanneledMetricDimension: key=$dimensionKey, value=$dimensionValue");
                    } else {
                        $dimension = new ChanneledMetricDimension();
                        $dimension->addDimensionKey($dimensionKey)
                            ->addDimensionValue($dimensionValue)
                            ->addChanneledMetric($channeledMetricEntity);
                        $dimensionsToPersist[] = $dimension;
                        $dimensionCache[$dimCacheKey] = $dimension;
                        $logger->info("Created ChanneledMetricDimension: key=$dimensionKey, value=$dimensionValue");
                    }
                }
            }
        }

        // Persist dimensions
        foreach ($dimensionsToPersist as $dimension) {
            try {
                $manager->persist($dimension);
            } catch (ORMException $e) {
                if (str_contains($e->getMessage(), 'SQLSTATE[23000]')) {
                    $logger->warning("Duplicate ChanneledMetricDimension: key=" . $dimension->getDimensionKey() . ", value=" . $dimension->getDimensionValue());
                    $existingDimension = $dimensionRepository->findOneByKeyValueAndMetric(
                        $dimension->getDimensionKey(),
                        $dimension->getDimensionValue(),
                        $channeledMetricEntity
                    );
                    if ($existingDimension) {
                        $dimCacheKey = md5($channeledMetricEntity->getId() . $dimension->getDimensionKey() . $dimension->getDimensionValue());
                        $dimensionCache[$dimCacheKey] = $existingDimension;
                        continue;
                    }
                }
                $logger->error("Error persisting ChanneledMetricDimension: key=" . $dimension->getDimensionKey() . ", value=" . $dimension->getDimensionValue() . ": " . $e->getMessage());
                throw $e;
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
        ?int $jobId = null
    ): bool {
        $campaignPlatformIds = array_values($channeledCampaignMap['mapReverse']);
        if (empty($campaignPlatformIds)) {
            return true;
        }

        $additionalParams = [];
        if ($startDate && $endDate) {
            $additionalParams['time_range'] = json_encode(['since' => $startDate, 'until' => $endDate]);
        }

        try {
            $campaignPlatformIdChunks = array_chunk($campaignPlatformIds, 50);
            $allRows = [];

            foreach ($campaignPlatformIdChunks as $chunk) {
                try {
                    $rows = $api->getCampaignInsightsFromAdAccount(
                        adAccountId: $channeledAccountEntity->getPlatformId(),
                        campaignIds: $chunk,
                        limit: 100,
                        metricBreakdown: null,
                        additionalParams: $additionalParams,
                        metricSet: MetricSet::KEY,
                    );
                    if (isset($rows['data']) && is_array($rows['data'])) {
                        $allRows = array_merge($allRows, $rows['data']);
                    }
                } catch (Exception $e) {
                    $logger->error("Error fetching campaign insights chunk: " . $e->getMessage());
                }
            }

            $logger->info("Fetched " . count($allRows) . " bulk rows for campaigns in Ad Account " . $channeledAccountEntity->getPlatformId());

            if (count($allRows) === 0) {
                $logger->info("No bulk rows found for campaigns in Ad Account " . $channeledAccountEntity->getPlatformId());
                return false;
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

                $metrics = FacebookGraphConvert::campaignMetrics(
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
                    MetricsProcessor::processChanneledMetricDimensions(
                        metrics: $globalAllMetrics,
                        manager: $manager,
                        metricMap: $metricMap,
                        channeledMetricMap: $channeledMetricMap,
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
            return true;
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
        ?int $jobId = null
    ): bool {
        $adsetPlatformIds = array_keys($channeledAdGroupMap['mapCampaign']);
        if (empty($adsetPlatformIds)) {
            return true;
        }

        $additionalParams = [];
        if ($startDate && $endDate) {
            $additionalParams['time_range'] = json_encode(['since' => $startDate, 'until' => $endDate]);
        }

        try {
            $adsetPlatformIdChunks = array_chunk($adsetPlatformIds, 50);
            $allRows = [];

            foreach ($adsetPlatformIdChunks as $chunk) {
                try {
                    $rows = $api->getAdsetInsightsFromAdAccount(
                        adAccountId: $channeledAccountEntity->getPlatformId(),
                        adsetIds: $chunk,
                        limit: 100,
                        metricBreakdown: [MetricBreakdown::AGE, MetricBreakdown::GENDER],
                        additionalParams: $additionalParams,
                        metricSet: MetricSet::KEY,
                    );
                    if (isset($rows['data']) && is_array($rows['data'])) {
                        $allRows = array_merge($allRows, $rows['data']);
                    }
                } catch (Exception $e) {
                    $logger->error("Error fetching adset insights chunk: " . $e->getMessage());
                }
            }

            $logger->info("Fetched " . count($allRows) . " bulk rows for adsets in Ad Account " . $channeledAccountEntity->getPlatformId());

            if (count($allRows) === 0) {
                $logger->info("No bulk rows found for adsets in Ad Account " . $channeledAccountEntity->getPlatformId());
                return false;
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

                $metrics = FacebookGraphConvert::adsetMetrics(
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
                    MetricsProcessor::processChanneledMetricDimensions(
                        metrics: $globalAllMetrics,
                        manager: $manager,
                        metricMap: $metricMap,
                        channeledMetricMap: $channeledMetricMap,
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
            return true;
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
        ?int $jobId = null
    ): bool {
        $adPlatformIds = array_keys($channeledAdMap['mapAdGroup']);
        if (empty($adPlatformIds)) {
            return true;
        }

        $additionalParams = [];
        if ($startDate && $endDate) {
            $additionalParams['time_range'] = json_encode(['since' => $startDate, 'until' => $endDate]);
        }

        $adPlatformIdChunks = array_chunk($adPlatformIds, 50);
        $allRows = [];

        foreach ($adPlatformIdChunks as $chunk) {
            try {
                $rows = $api->getAdInsightsFromAdAccount(
                    adAccountId: $channeledAccountEntity->getPlatformId(),
                    adIds: $chunk,
                    limit: 100,
                    metricBreakdown: [],
                    additionalParams: $additionalParams,
                    metricSet: MetricSet::KEY,
                );
                if (isset($rows['data']) && is_array($rows['data'])) {
                    $allRows = array_merge($allRows, $rows['data']);
                }
            } catch (Exception $e) {
                $logger->error("Error fetching ad insights chunk: " . $e->getMessage());
            }
        }

        $logger->info("Fetched " . count($allRows) . " bulk rows for ads in Ad Account " . $channeledAccountEntity->getPlatformId());

        if (count($allRows) === 0) {
            $logger->info("No bulk rows found for ads in Ad Account " . $channeledAccountEntity->getPlatformId());
            return false;
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

                $metrics = FacebookGraphConvert::adMetrics(
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
                    MetricsProcessor::processChanneledMetricDimensions(
                        metrics: $globalAllMetrics,
                        manager: $manager,
                        metricMap: $metricMap,
                        channeledMetricMap: $channeledMetricMap,
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
            return true;
        } catch (Exception $e) {
            $logger->error("Error during bulk Meta account's ad insights request: " . $e->getMessage());
            throw $e;
        }
    }
}
