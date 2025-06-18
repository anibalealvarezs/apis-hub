<?php

declare(strict_types=1);

namespace Classes\Requests;

use Anibalealvarezs\GoogleApi\Services\SearchConsole\Enums\Dimension;
use Anibalealvarezs\GoogleApi\Services\SearchConsole\Enums\GroupType;
use Anibalealvarezs\GoogleApi\Services\SearchConsole\Enums\Operator;
use Anibalealvarezs\KlaviyoApi\Enums\AggregatedMeasurement;
use Carbon\Carbon;
use Classes\Conversions\GoogleSearchConsoleConvert;
use Classes\Conversions\KlaviyoConvert;
use Classes\KeyGenerator;
use Classes\Overrides\GoogleApi\SearchConsoleApi\SearchConsoleApi;
use Classes\Overrides\KlaviyoApi\KlaviyoApi;
use DateTime;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\Persistence\Mapping\MappingException;
use Entities\Analytics\Channeled\ChanneledMetric;
use Entities\Analytics\Channeled\ChanneledMetricDimension;
use Entities\Analytics\Country;
use Entities\Analytics\Device;
use Entities\Analytics\Metric;
use Entities\Analytics\Page;
use Entities\Analytics\Query;
use Enums\Channel;
use Enums\Country as CountryEnum;
use Enums\Device as DeviceEnum;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
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
     * @return Channel[]
     */
    public static function supportedChannels(): array
    {
        return [
            Channel::shopify->value,
            Channel::klaviyo->value,
            Channel::facebook->value,
            Channel::bigcommerce->value,
            Channel::netsuite->value,
            Channel::amazon->value,
            Channel::instagram->value,
            Channel::google_analytics->value,
            Channel::google_search_console->value,
            Channel::pinterest->value,
            Channel::linkedin->value,
            Channel::x->value,
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
        string $createdAtMin = null,
        string $createdAtMax = null,
        array $fields = null,
        object $filters = null,
        string|bool $resume = true
    ): Response {
        $config = Helpers::getChannelsConfig()['klaviyo'];
        $klaviyoClient = new KlaviyoApi(
            apiKey: $config['klaviyo_api_key'],
        );

        $metricNames = $filters->metricNames ?? ($config['metrics'] ?? []);
        $metricIds = [];
        $klaviyoClient->getAllMetricsAndProcess(
            metricFields: ['id', 'name'],
            callback: function($metrics) use (&$metricIds, $metricNames) {
                foreach ($metrics as $metric) {
                    if (empty($metricNames) || in_array($metric['attributes']['name'], $metricNames)) {
                        $metricIds[] = $metric['id'];
                    }
                }
            }
        );

        $manager = Helpers::getManager();
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
                callback: function($aggregates) use ($metricId) {
                    self::process(KlaviyoConvert::metricAggregates($aggregates, $metricId));
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
    public static function getListFromShopify(object $filters = null, string|bool $resume = true): Response
    {
        /* Placeholder for ShopifyApi integration */
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromFacebook(object $filters = null, string|bool $resume = true): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromBigCommerce(object $filters = null, string|bool $resume = true): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromNetSuite(object $filters = null, string|bool $resume = true): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromAmazon(object $filters = null, string|bool $resume = true): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromInstagram(object $filters = null, string|bool $resume = true): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromGoogleAnalytics(object $filters = null, string|bool $resume = true): Response
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
        string $startDate = null,
        ?string $endDate = null,
        ?object $filters = null,
        string|bool $resume = true,
        ?LoggerInterface $logger = null
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
            $channeledMetricRepository = $manager->getRepository(ChanneledMetric::class);
            $pageRepository = $manager->getRepository(Page::class);
            $countryRepository = $manager->getRepository(Country::class);
            $deviceRepository = $manager->getRepository(Device::class);
            $metricNames = $filters->metricNames ?? ($config['google_search_console']['metrics'] ?? ['clicks', 'impressions', 'ctr', 'position']);
            // $dimensions = $filters->dimensions ?? ['date', 'query', 'page', 'country', 'device'];
            // Custom filter for dimensions disabled for GSC given the strict structure. Config dimensions used instead
            $batchSize = 100;

            $logger->info("Initialized repositories, dimensions=" . implode(',', GoogleSearchConsoleConvert::$allDimensions) . ", metricNames=" . json_encode($metricNames) . ", batchSize=$batchSize");
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
                if (!$site['enabled']) {
                    $logger->info("Skipping disabled site: " . $site['url']);
                    continue;
                }
                $result = self::processSite(
                    $site,
                    $startDate,
                    $endDate,
                    $resume,
                    $api,
                    $manager,
                    $channeledMetricRepository,
                    $pageRepository,
                    $countryRepository,
                    $deviceRepository,
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
    public static function getListFromPinterest(object $filters = null, string|bool $resume = true): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromLinkedIn(object $filters = null, string|bool $resume = true): Response
    {
        return new Response(json_encode([]));
    }

    /**
     * @param object|null $filters
     * @param string|bool $resume
     * @return Response
     */
    public static function getListFromX(object $filters = null, string|bool $resume = true): Response
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
        $config = Helpers::getChannelsConfig()['google'] ?? null;
        if (!$config) {
            $logger->error("Missing 'google' configuration in channels config");
            throw new Exception("Missing 'google' configuration in channels config");
        }
        $scConfig = Helpers::getChannelsConfig()['google_search_console'] ?? null;
        if (!$scConfig) {
            $logger->error("Missing 'google_search_console' configuration in channels config");
            throw new Exception("Missing 'google_search_console' configuration in channels config");
        }
        $logger->info("Loaded GSC config: sites=" . count($scConfig['sites']));
        return [
            'google' => $config,
            'google_search_console' => $scConfig,
        ];
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
                $apiInstance = new SearchConsoleApi(
                    redirectUrl: $config['google']['redirect_uri'] ?? null,
                    clientId: $config['google']['client_id'] ?? null,
                    clientSecret: $config['google']['client_secret'] ?? null,
                    refreshToken: $config['google']['refresh_token'] ?? null,
                    userId: $config['google']['user_id'] ?? null,
                    scopes: [$config['google_search_console']['scope'] ?? null],
                    token: $config['google_search_console']['token'] ?? null
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
     * Processes a single site, including page lookup and data fetching.
     *
     * @param array $site
     * @param string|null $startDate
     * @param string|null $endDate
     * @param string|bool $resume
     * @param SearchConsoleApi $api
     * @param EntityManager $manager
     * @param EntityRepository $channeledMetricRepository
     * @param EntityRepository $pageRepository
     * @param EntityRepository $countryRepository
     * @param EntityRepository $deviceRepository
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
    private static function processSite(
        array $site,
        ?string $startDate,
        ?string $endDate,
        string|bool $resume,
        SearchConsoleApi $api,
        EntityManager $manager,
        EntityRepository $channeledMetricRepository,
        EntityRepository $pageRepository,
        EntityRepository $countryRepository,
        EntityRepository $deviceRepository,
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
        $entitiesToInvalidate = ['metric' => [], 'channeledMetric' => [], 'query' => []];
        $queryCache = [];
        $metricCache = [];
        $channeledMetricCache = [];
        $dimensionCache = [];
        $startTime = microtime(true);
        $siteMetrics = 0;
        $siteRows = 0;
        $siteDuplicates = 0;
        $period = Carbon::parse($from)->toPeriod($to, '1 day');
        foreach ($period as $day) {
            $dayStr = $day->format('Y-m-d');
            $result = self::fetchDailyData(
                $dayStr,
                $site,
                $api,
                $manager,
                $pageEntity,
                $metricNames,
                $entitiesToInvalidate,
                $queryCache,
                $metricCache,
                $channeledMetricCache,
                $dimensionCache,
                $countryRepository,
                $deviceRepository,
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

            self::updateMetricsValues($manager, $siteUrl, $dayStr, $logger);
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
     * Fetches and processes data for a single day.
     *
     * @param string $dayStr
     * @param array $site
     * @param SearchConsoleApi $api
     * @param EntityManager $manager
     * @param Page $pageEntity
     * @param array $metricNames
     * @param array &$entitiesToInvalidate
     * @param array &$queryCache
     * @param array &$metricCache
     * @param array &$channeledMetricCache
     * @param array &$dimensionCache
     * @param EntityRepository $countryRepository
     * @param EntityRepository $deviceRepository
     * @param array $targetKeywords
     * @param array $targetCountries
     * @param array $dimensionFilterGroups
     * @param LoggerInterface $logger
     * @param array $deviceMap
     * @param array $countryMap
     * @param array $pageMap
     * @return array
     * @throws GuzzleException
     * @throws NotSupported
     * @throws ORMException
     * @throws \Doctrine\DBAL\Exception
     */
    private static function fetchDailyData(
        string $dayStr,
        array $site,
        SearchConsoleApi $api,
        EntityManager $manager,
        Page $pageEntity,
        array $metricNames,
        array &$entitiesToInvalidate,
        array &$queryCache,
        array &$metricCache,
        array &$channeledMetricCache,
        array &$dimensionCache,
        EntityRepository $countryRepository,
        EntityRepository $deviceRepository,
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
        $loopCount = 0;
        $totalMetrics = 0;
        $totalRows = 0;
        $totalDuplicates = 0;
        $allMetrics = new ArrayCollection();
        $allRows = [];
        $subsetRows = [];

        try {
            $dimensionsSubsets = self::getAllSubsets(GoogleSearchConsoleConvert::$optionalDimensions);
            // $dimensionsSubsets = [GoogleSearchConsoleConvert::$optionalDimensions];
            foreach ($dimensionsSubsets as $dimensionsSubset) {
                $actualDimensionsSubset = array_merge(array_diff(GoogleSearchConsoleConvert::$allDimensions, GoogleSearchConsoleConvert::$optionalDimensions), $dimensionsSubset);
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

            foreach($subsetRows as $rows) {
                foreach ($rows as $row) {
                    foreach($row as $key => $element) {
                        if (is_string($element)) {
                            continue;
                        }
                        $element['subset'] = $rows['subset'];
                        $allRows[] = $element;
                    }
                }
            }

            $childrenSums = self::computeChildrenSum($allRows);

            /* $sums = [];
            foreach ($allRows as $i => $row) {
                $sums[] = "Record $i has children sum = " . (is_array($childrenSums[$i]) ? json_encode($childrenSums[$i]) : $childrenSums[$i]);
            } */

            $differences = self::calculateDifferences($allRows, $childrenSums);

            $allocatedDifferences = self::allocatePositiveDifferences($differences, GoogleSearchConsoleConvert::$allDimensions);

            $allocateFinalDifference = self::addGlobalRemainderSynthetic($allocatedDifferences, GoogleSearchConsoleConvert::$allDimensions);

            $negativeDifferencesProcessed = self::flagOrScaleNegativeDifferences($allocateFinalDifference, true);

            $scaleAdjusted = self::adjustScaledPositions($negativeDifferencesProcessed);

            $finalRecords = array_values(array_filter($scaleAdjusted, function($record) {
                // Keep if:
                // - full subset (all 5 dims)
                // - or synthetic record
                return (count($record['subset']) === 5) || (!empty($record['synthetic']));
            }));

            $finalRecords = GoogleSearchConsoleConvert::fillWithNullsAndFilter($finalRecords, $targetKeywords, $targetCountries);

            // Helpers::dumpDebugJson($finalRecords);

            $metrics = GoogleSearchConsoleConvert::metrics($finalRecords, $siteUrl, $siteKey, $logger, $pageEntity, $manager);
            // $logger->info("Converted " . count($rows) . " rows to " . count($pageMetrics) . " metrics, first metric: " . (count($pageMetrics) > 0 ? json_encode(['name' => $pageMetrics[0]->name, 'query' => is_string($pageMetrics[0]->query) ? $pageMetrics[0]->query : ($pageMetrics[0]->query instanceof Query ? $pageMetrics[0]->query->getQuery() : 'none')]) : 'none'));

            // Adjust pageMetrics according to configurations
            foreach ($metrics as &$metric) {
                if ($metricNames && !in_array($metric->name, $metricNames)) {
                    $logger->warning("Skipped metric: =$metric->name, not in allowed names: " . json_encode($metricNames));
                    continue;
                }

                $countryEnum = CountryEnum::tryFrom($metric->countryCode) ?? CountryEnum::UNK;
                $metric->country = $countryMap['map'][$countryEnum->value];

                $deviceEnum = DeviceEnum::from($metric->deviceType) ?? DeviceEnum::UNKNOWN;
                $metric->device = $deviceMap['map'][$deviceEnum->value];

                $allMetrics->add($metric);
            }

            try {
                $manager->getConnection()->beginTransaction();

                // Map metrics
                $metricMap = self::processMetrics(
                    $allMetrics,
                    $manager,
                    $countryMap,
                    $deviceMap,
                    $pageMap,
                );

                // Map channeled metrics
                $channeledMetricMap = self::processChanneledMetrics(
                    $allMetrics,
                    $manager,
                    $metricMap,
                    $logger,
                );

                // Map dimensions
                self::processChanneledMetricDimensions(
                    $allMetrics,
                    $manager,
                    $metricMap,
                    $channeledMetricMap,
                    $logger,
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

    protected static function computeChildrenSum(array $records): array {
        $n = count($records);

        // Initialize sums array with zeros for each metric
        $childrenSums = array_fill(0, $n, ['impressions' => 0, 'clicks' => 0]);

        // Sum children metrics
        for ($i = 0; $i < $n; $i++) {
            $parentSubset = $records[$i]['subset'];
            $parentDims = $records[$i]['keys'];

            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) continue;

                $childSubset = $records[$j]['subset'];
                $childDims = $records[$j]['keys'];

                if (self::isParentOf($parentSubset, $parentDims, $childSubset, $childDims)) {
                    $childrenSums[$i]['impressions'] += $records[$j]['impressions'] ?? 0;
                    $childrenSums[$i]['clicks'] += $records[$j]['clicks'] ?? 0;
                }
            }
        }

        return $childrenSums;
    }

    protected static function calculateDifferences(array $records, array $childrenSums): array {
        foreach ($records as $index => &$record) {
            $ownImpressions = $record['impressions'] ?? 0;
            $ownClicks = $record['clicks'] ?? 0;

            $childrenImpressions = $childrenSums[$index]['impressions'] ?? 0;
            $childrenClicks = $childrenSums[$index]['clicks'] ?? 0;

            $record['impressions_difference'] = $ownImpressions - $childrenImpressions;
            $record['clicks_difference'] = $ownClicks - $childrenClicks;
        }
        unset($record); // prevent accidental reference reuse
        return $records;
    }

    protected static function allocatePositiveDifferences(array $records, array $dimensionNames, string $missingLabel = 'unknown'): array {
        $extendedRecords = $records;

        foreach ($records as $record) {
            $impressionDiff = $record['impressions_difference'] ?? 0;
            $clicksDiff = $record['clicks_difference'] ?? 0;

            if ($impressionDiff > 0 || $clicksDiff > 0) {
                $newKeys = $record['keys'];
                $subset = $record['subset'];

                // Find the first missing dimension
                $missingDimension = null;
                foreach ($dimensionNames as $dim) {
                    if (!in_array($dim, $subset)) {
                        $missingDimension = $dim;
                        break;
                    }
                }

                if ($missingDimension !== null) {
                    $newKeys[] = $missingLabel;
                    $newSubset = [...$subset, $missingDimension];

                    // Create synthetic record
                    $syntheticRecord = [
                        'keys' => $newKeys,
                        'clicks' => $clicksDiff,
                        'impressions' => $impressionDiff,
                        'ctr' => ($impressionDiff > 0) ? $clicksDiff / $impressionDiff : 0,
                        'position' => null,
                        'subset' => $newSubset,
                        'impressions_difference' => 0,
                        'clicks_difference' => 0,
                        'synthetic' => true,
                    ];

                    $extendedRecords[] = $syntheticRecord;
                }
            }
        }

        return $extendedRecords;
    }

    protected static function addGlobalRemainderSynthetic(array $records, array $dimensionNames, array $parentSubset = ['date', 'page']): array
    {
        $extendedRecords = $records;

        $allImpressions = 0;
        $allClicks = 0;
        $allPositionWeightedSum = 0;
        $allPositionCount = 0;

        $fiveDImpressions = 0;
        $fiveDClicks = 0;
        $fiveDPositionWeightedSum = 0;
        $fiveDPositionCount = 0;

        $partialImpressions = 0;
        $partialClicks = 0;
        $partialPositionWeightedSum = 0;
        $partialPositionCount = 0;

        // Step 1: Sum metrics by category
        foreach ($records as $rec) {
            $subset = $rec['subset'] ?? [];
            $impr = $rec['impressions'] ?? 0;
            $clicks = $rec['clicks'] ?? 0;
            $pos = $rec['position'] ?? null;
            $posWeighted = ($pos !== null) ? ($pos * $impr) : 0;

            // Parent subset totals ("All")
            if (array_values($subset) == $parentSubset) {
                $allImpressions += $impr;
                $allClicks += $clicks;
                if ($pos !== null) {
                    $allPositionWeightedSum += $posWeighted;
                    $allPositionCount += $impr;
                }
            }

            // Fully attributed 5D records (exclude synthetic)
            if (count($subset) === count($dimensionNames) && empty($rec['synthetic'])) {
                $fiveDImpressions += $impr;
                $fiveDClicks += $clicks;
                if ($pos !== null) {
                    $fiveDPositionWeightedSum += $posWeighted;
                    $fiveDPositionCount += $impr;
                }
            }

            // Partial synthetic records
            if (!empty($rec['synthetic'])) {
                $partialImpressions += $impr;
                $partialClicks += $clicks;
                if ($pos !== null) {
                    $partialPositionWeightedSum += $posWeighted;
                    $partialPositionCount += $impr;
                }
            }
        }

        // Step 2: Calculate remaining values
        $remainingImpressions = $allImpressions - $fiveDImpressions - $partialImpressions;
        $remainingClicks = $allClicks - $fiveDClicks - $partialClicks;

        // Weighted position average for remaining
        // Compute weighted position of "All" minus weighted positions already accounted for
        $allPositionAvg = ($allPositionCount > 0) ? ($allPositionWeightedSum / $allPositionCount) : null;

        // For position, approximate remaining weighted sum and count
        $remainingPositionWeightedSum = $allPositionWeightedSum - $fiveDPositionWeightedSum - $partialPositionWeightedSum;
        $remainingPositionCount = $allPositionCount - $fiveDPositionCount - $partialPositionCount;

        $remainingPosition = ($remainingPositionCount > 0) ? ($remainingPositionWeightedSum / $remainingPositionCount) : $allPositionAvg;

        // CTR = clicks / impressions (avoid division by zero)
        $remainingCtr = ($remainingImpressions > 0) ? ($remainingClicks / $remainingImpressions) : 0;

        if ($remainingImpressions > 0 || $remainingClicks > 0) {
            // Compose keys for parent subset, fill missing with $missingLabel
            $keys = [];
            foreach ($dimensionNames as $dim) {
                $missingLabel = match($dim) {
                    'country' => 'UNK',
                    default => 'unknown',
                };
                if (in_array($dim, $parentSubset)) {
                    // Find record with this subset and take its key for that dim, or fallback to missingLabel
                    $foundKey = $missingLabel;
                    foreach ($records as $rec) {
                        if (($rec['subset'] ?? []) === $parentSubset) {
                            $index = array_search($dim, $parentSubset);
                            $foundKey = $rec['keys'][$index] ?? $missingLabel;
                            break;
                        }
                    }
                    $keys[] = $foundKey;
                } else {
                    $keys[] = $missingLabel;
                }
            }

            $syntheticRecord = [
                'keys' => $keys,
                'subset' => $dimensionNames,
                'impressions' => $remainingImpressions,
                'clicks' => $remainingClicks,
                'ctr' => $remainingCtr,
                'position' => $remainingPosition,
                'synthetic' => true,
                'note' => 'final synthetic to reconcile unmatched parent metrics',
                'impressions_difference' => 0,
                'clicks_difference' => 0,
            ];

            $extendedRecords[] = $syntheticRecord;
        }

        return $extendedRecords;
    }

    protected static function flagOrScaleNegativeDifferences(array $records, bool $scaleNegative = false): array {
        foreach ($records as &$record) {

            $impressionDiff = $record['impressions_difference'] ?? 0;
            $clicksDiff = $record['clicks_difference'] ?? 0;

            $childrenImpressions = $record['children_sum']['impressions'] ?? 0;
            $childrenClicks = $record['children_sum']['clicks'] ?? 0;

            $impressions = $record['impressions'] ?? 0;
            $clicks = $record['clicks'] ?? 0;

            // If either metric has a negative difference, treat the record
            if ($impressionDiff < 0 || $clicksDiff < 0) {
                if ($scaleNegative) {
                    $scaleFactorImpr = $childrenImpressions > 0 ? $impressions / $childrenImpressions : 0;
                    $scaleFactorClicks = $childrenClicks > 0 ? $clicks / $childrenClicks : 0;

                    $record['original_impressions'] = $impressions;
                    $record['original_clicks'] = $clicks;
                    $record['original_differences'] = [
                        'impressions' => $impressionDiff,
                        'clicks' => $clicksDiff
                    ];

                    $record['impressions'] = round($childrenImpressions * $scaleFactorImpr);
                    $record['clicks'] = round($childrenClicks * $scaleFactorClicks);
                    $record['scaled'] = true;
                    $record['note'] = 'scaled down to match parent metrics';

                    // Recalculate CTR
                    $record['ctr'] = $record['impressions'] > 0 ? $record['clicks'] / $record['impressions'] : 0;
                } else {
                    $record['flagged'] = true;
                    $record['note'] = 'exceeds parent; likely misattributed';
                }
            }
        }

        return $records;
    }

    protected static function adjustScaledPositions(array $records): array {
        $n = count($records);

        // Helper: same as before


        for ($i = 0; $i < $n; $i++) {
            if (!($records[$i]['scaled'] ?? false)) continue;

            $parentSubset = $records[$i]['subset'];
            $parentDims = $records[$i]['keys'];

            $weightedSum = 0;
            $totalImpressions = 0;

            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) continue;

                $childSubset = $records[$j]['subset'];
                $childDims = $records[$j]['keys'];

                if (self::isParentOf($parentSubset, $parentDims, $childSubset, $childDims)) {
                    $impressions = $records[$j]['impressions'] ?? 0;
                    $position = $records[$j]['position'] ?? null;

                    if ($impressions > 0 && $position !== null) {
                        $weightedSum += $impressions * $position;
                        $totalImpressions += $impressions;
                    }
                }
            }

            if ($totalImpressions > 0) {
                $records[$i]['original_position'] = $records[$i]['position'] ?? null;
                $records[$i]['position'] = round($weightedSum / $totalImpressions, 2);
            }
        }

        return $records;
    }

    protected static function isParentOf(array $parentSubset, array $parentDims, array $childSubset, array $childDims): bool {
        if (count($childSubset) <= count($parentSubset)) {
            return false;
        }
        $childSubsetIndex = array_flip($childSubset);
        $parentIndexInChild = [];

        foreach ($parentSubset as $dimName) {
            if (!isset($childSubsetIndex[$dimName])) {
                return false;
            }
            $parentIndexInChild[] = $childSubsetIndex[$dimName];
        }

        $prevIdx = -1;
        foreach ($parentIndexInChild as $i => $childIdx) {
            if ($childIdx <= $prevIdx) {
                return false;
            }
            $prevIdx = $childIdx;
            if ($parentDims[$i] !== $childDims[$childIdx]) {
                return false;
            }
        }
        return true;
    }

    public static function getAllSubsets($elements): array
    {
        $subsets = [[]]; // Incluye el conjunto vacío
        foreach ($elements as $element) {
            $newSubsets = [];
            foreach ($subsets as $subset) {
                $newSubsets[] = $subset; // Copia el subconjunto existente
                $newSubsets[] = array_merge($subset, [$element]); // Añade el elemento
            }
            $subsets = $newSubsets;
        }
        return $subsets;
    }

    /**
     * Updates aggregated metric values for a specific day.
     *
     * @param EntityManager $manager
     * @param string $siteUrl
     * @param string $dayStr
     * @param LoggerInterface $logger
     * @throws \Doctrine\DBAL\Exception
     */
    private static function updateMetricsValues(EntityManager $manager, string $siteUrl, string $dayStr, LoggerInterface $logger): void
    {
        $logger->info("Updating metrics values for site $siteUrl, date $dayStr");
        try {
            $connection = $manager->getConnection();
            $connection->executeStatement("
            UPDATE metrics m
            JOIN (
                SELECT 
                    cm.metric_id,
                    m.name,
                    COALESCE(SUM(JSON_EXTRACT(cm.data, '$.impressions')), 0) as total_impressions,
                    COALESCE(SUM(JSON_EXTRACT(cm.data, '$.clicks')), 0) as total_clicks,
                    COALESCE(SUM(JSON_EXTRACT(cm.data, '$.position_weighted')), 0) as total_position_weighted,
                    COALESCE(SUM(JSON_EXTRACT(cm.data, '$.ctr')), 0) as total_ctr
                FROM channeled_metrics cm
                JOIN metrics m ON cm.metric_id = m.id
                WHERE cm.channel = :channel
                AND cm.platformCreatedAt LIKE :date
                GROUP BY cm.metric_id, m.name
            ) cm_agg ON m.id = cm_agg.metric_id
            SET m.value = CASE cm_agg.name
                WHEN 'impressions' THEN COALESCE(cm_agg.total_impressions, 0)
                WHEN 'clicks' THEN COALESCE(cm_agg.total_clicks, 0)
                WHEN 'ctr' THEN COALESCE(cm_agg.total_ctr, 0)
                WHEN 'position' THEN IF(cm_agg.total_impressions > 0, cm_agg.total_position_weighted / cm_agg.total_impressions, 0)
                ELSE COALESCE(m.value, 0)
            END
            WHERE m.channel = :channel
        ", [
                'channel' => Channel::google_search_console->value,
                'date' => $dayStr . '%'
            ]);
            $logger->info("Updated metrics values for site $siteUrl, date $dayStr");
        } catch (\Doctrine\DBAL\Exception $e) {
            $logger->error("Error updating metrics values for site $siteUrl, date $dayStr: " . $e->getMessage());
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
        ?string $endDate
    ): Response {
        $logger->info("Completed: metrics=$totalMetrics, rows=$totalRows, duplicates=$totalDuplicates");

        $entitiesToInvalidate = ['metric' => [], 'channeledMetric' => [], 'query' => []];
        $cacheService = CacheService::getInstance(Helpers::getRedisClient());
        foreach ($entitiesToInvalidate as $entity => $ids) {
            if (!empty($ids)) {
                $cacheService->invalidateEntityCache(
                    entity: $entity,
                    ids: array_unique($ids),
                    channel: Channel::google_search_console->getName()
                );
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
                $logger->warning("Page entity detached: ID=" . $pageEntity->getId() . ", URL=" . $pageEntity->getUrl());
                $pageEntity = $manager->find(Page::class, $pageEntity->getId());
                if (!$pageEntity) {
                    $logger->error("Failed to reattach Page entity: ID=" . $pageEntity->getId());
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
                $logger->warning("Metric entity detached: ID=" . $metricEntity->getId() . ", name={$metric->name}");
                $metricEntity = $manager->find(Metric::class, $metricEntity->getId());
                if (!$metricEntity) {
                    $logger->error("Failed to reattach Metric entity: ID=" . $metricEntity->getId());
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
                $logger->warning("ChanneledMetric entity detached: ID=" . $channeledMetricEntity->getId());
                $channeledMetricEntity = $manager->find(ChanneledMetric::class, $channeledMetricEntity->getId());
                if (!$channeledMetricEntity) {
                    $logger->error("Failed to reattach ChanneledMetric entity: ID=" . $channeledMetricEntity->getId());
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
                    if (isset($dimension->dimensionKey, $dimension->dimensionValue)) {
                        $dimCacheKey = md5($channeledMetricEntity->getId() . $dimension->dimensionKey . $dimension->dimensionValue);
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
            if ($metricEntity->getQuery()) {
                $query = $metricEntity->getQuery();
                if (!$manager->contains($query)) {
                    $logger->warning("Query entity detached: ID=" . $query->getId());
                    $query = $manager->find(Query::class, $query->getId());
                    if (!$query) {
                        $logger->error("Failed to reattach Query entity: ID=" . $query->getId());
                        throw new RuntimeException("Failed to reattach Query entity");
                    }
                    $metricEntity->setQuery($query);
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
                        m.name,
                        SUM(JSON_EXTRACT(cm.data, '$.impressions')) as total_impressions,
                        SUM(JSON_EXTRACT(cm.data, '$.clicks')) as total_clicks,
                        SUM(JSON_EXTRACT(cm.data, '$.position_weighted')) as total_position_weighted
                    FROM channeled_metrics cm
                    JOIN metrics m ON cm.metric_id = m.id
                    WHERE cm.channel = :channel
                    GROUP BY cm.metric_id, m.name
                ) cm_agg ON m.id = cm_agg.metric_id
                SET m.value = CASE cm_agg.name
                    WHEN 'impressions' THEN cm_agg.total_impressions
                    WHEN 'clicks' THEN cm_agg.total_clicks
                    WHEN 'ctr' THEN IF(cm_agg.total_impressions > 0, cm_agg.total_clicks / cm_agg.total_impressions, 0)
                    WHEN 'position' THEN IF(cm_agg.total_impressions > 0, cm_agg.total_position_weighted / cm_agg.total_impressions, 0)
                    ELSE m.value
                END
                WHERE m.channel = :channel
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
     * @param EntityRepository $repository
     * @param EntityRepository $queryRepository
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
        EntityRepository $repository,
        EntityRepository $queryRepository,
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
                    'campaign' => null,
                    'channeledCampaign' => null,
                    'channeledAdGroup' => null,
                    'channeledAd' => null,
                    'post' => null,
                    'product' => null,
                    'customer' => null,
                    'order' => null
                ];
                if ($metricEntity = $repository->findOneBy($criteria)) {
                    $logger->info("Existing Metric found in database: ID=" . $metricEntity->getId() . ", name=$metric->name, query=$queryString");
                    if ($em && !$em->contains($metricEntity)) {
                        $logger->warning("Metric entity detached: ID=" . $metricEntity->getId() . ", name=$metric->name");
                        $metricEntity = $em->find(Metric::class, $metricEntity->getId());
                        if (!$metricEntity) {
                            $logger->error("Failed to reattach Metric: ID=" . $metricEntity->getId() . ", name=$metric->name");
                            throw new Exception("Failed to reattach Metric ID=" . $metricEntity->getId());
                        }
                    }
                    $metricCache[$metricKey] = $metricEntity;
                    return $metricEntity;
                }

                // If not found, create a new Metric entity
                $logger->info("Creating new Metric for $metric->name, query=$queryString");
                $metric->query = $queryEntity;
                try {
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
                        ]
                    , true);
                    if (!$metricEntity->getId()) {
                        $logger->error("Metric entity created but has no ID: name=$metric->name, query=$queryString");
                    }
                    if ($em && !$em->contains($metricEntity)) {
                        $logger->warning("Metric entity detached after creation: ID=" . $metricEntity->getId() . ", name=$metric->name");
                        $metricEntity = $em->find(Metric::class, $metricEntity->getId());
                        if (!$metricEntity) {
                            $logger->error("Failed to reattach Metric after creation: ID=" . $metricEntity->getId() . ", name=$metric->name");
                            throw new Exception("Failed to reattach Metric ID=" . $metricEntity->getId());
                        }
                    }
                    $logger->info("Created new Metric: id={$metricEntity->getId()}, queryId=" . ($queryEntity ? $queryEntity->getId() : 'none'));
                    $metricCache[$metricKey] = $metricEntity;
                    return $metricEntity;
                } catch (ORMException $e) {
                    if (str_contains($e->getMessage(), 'SQLSTATE[23000]')) {
                        $logger->warning("Duplicate metric for $metric->name, query=$queryString, retrying lookup");
                        $metricEntity = $repository->findOneBy($criteria);
                        if ($metricEntity) {
                            $logger->info("Existing Metric found on retry: id={$metricEntity->getId()}");
                            if ($em && !$em->contains($metricEntity)) {
                                $logger->warning("Metric entity detached on retry: ID=" . $metricEntity->getId() . ", name=$metric->name");
                                $metricEntity = $em->find(Metric::class, $metricEntity->getId());
                                if (!$metricEntity) {
                                    $logger->error("Failed to reattach Metric on retry: ID=" . $metricEntity->getId() . ", name=$metric->name");
                                    throw new Exception("Failed to reattach Metric ID=" . $metricEntity->getId());
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
     * @param EntityRepository $repository
     * @param EntityRepository $dimensionRepository
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
        EntityRepository $repository,
        EntityRepository $dimensionRepository,
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
                list($channeledMetricCache, $channeledMetricEntity) = self::processChanneledMetric($channeledMetricCache,
                    $cacheKey, $logger, $channeledMetric, $metricEntity, $platformCreatedAt, $repository, $manager);

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
     * @param EntityRepository $dimensionRepository
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
        EntityRepository $dimensionRepository,
        array $dimensionsToPersist,
        EntityManager $manager
    ): array {
        // Process dimensions
        if (isset($channeledMetric->dimensions)) {
            foreach ($channeledMetric->dimensions as $dimensionData) {
                $dimensionKey = $dimensionData->dimensionKey ?? null;
                $dimensionValue = $dimensionData->dimensionValue ?? null;
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
     * @param EntityRepository $repository
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
        EntityRepository $repository,
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
                    'position_weighted' => max($channeledMetricData['position_weighted'] ?? 0,
                        $newData['position_weighted'] ?? 0),
                    'ctr' => max($channeledMetricData['ctr'] ?? 0, $newData['ctr'] ?? 0)
                ];

                $channeledMetricEntity->addData($updatedData);
                $channeledMetricEntity->addPlatformCreatedAt($platformCreatedAt);
                $channeledMetricEntity->addUpdatedAt(new DateTime());
                $manager->persist($channeledMetricEntity);

                // Check if entity is managed
                if (!$manager->contains($channeledMetricEntity)) {
                    $logger->error("ChanneledMetric entity detached after update: ID=" . $channeledMetricEntity->getId() . ", platformId=" . ($channeledMetric->platformId ?? 'null'));
                    $channeledMetricEntity = $manager->find(ChanneledMetric::class, $channeledMetricEntity->getId());
                    if (!$channeledMetricEntity) {
                        $logger->error("Failed to reattach ChanneledMetric: ID=" . $channeledMetricEntity->getId() . ", platformId=" . ($channeledMetric->platformId ?? 'null'));
                        throw new Exception("Failed to reattach ChanneledMetric ID=" . $channeledMetricEntity->getId());
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
                    $logger->error("ChanneledMetric entity detached after creation: ID=" . $channeledMetricEntity->getId() . ", platformId=" . ($channeledMetric->platformId ?? 'null'));
                    $channeledMetricEntity = $manager->find(ChanneledMetric::class, $channeledMetricEntity->getId());
                    if (!$channeledMetricEntity) {
                        $logger->error("Failed to reattach ChanneledMetric after creation: ID=" . $channeledMetricEntity->getId() . ", platformId=" . ($channeledMetric->platformId ?? 'null'));
                        throw new Exception("Failed to reattach ChanneledMetric ID=" . $channeledMetricEntity->getId());
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
     * @param EntityRepository $queryRepository
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
        EntityRepository $queryRepository,
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
        $dimensions = isset($metric->dimensions) ? array_column((array)$metric->dimensions ?? [], 'dimensionValue',
            'dimensionKey') : [];
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
                'filters' => array_map(fn($kw) => [
                    'dimension' => Dimension::QUERY,
                    'operator' => Operator::CONTAINS,
                    'expression' => $kw
                ], $includeKeywords),
                'groupType' => GroupType::AND->value
            ];
        } elseif ($excludeKeywords) {
            $dimensionFilterGroups[] = [
                'filters' => array_map(fn($kw) => [
                    'dimension' => Dimension::QUERY,
                    'operator' => Operator::NOT_CONTAINS,
                    'expression' => $kw
                ], $excludeKeywords),
                'groupType' => GroupType::AND->value
            ];
        }
        if ($includeCountries) {
            $dimensionFilterGroups[] = [
                'filters' => array_map(fn($country) => [
                    'dimension' => Dimension::COUNTRY,
                    'operator' => Operator::EQUALS,
                    'expression' => $country
                ], $includeCountries),
                'groupType' => GroupType::AND->value
            ];
        } elseif ($excludeCountries) {
            $dimensionFilterGroups[] = [
                'filters' => array_map(fn($country) => [
                    'dimension' => Dimension::COUNTRY,
                    'operator' => Operator::NOT_EQUALS,
                    'expression' => $country
                ], $excludeCountries),
                'groupType' => GroupType::AND->value
            ];
        }
        if ($includePages) {
            $dimensionFilterGroups[] = [
                'filters' => array_map(fn($page) => [
                    'dimension' => Dimension::PAGE,
                    'operator' => Operator::CONTAINS,
                    'expression' => $page
                ], $includePages),
                'groupType' => GroupType::AND->value
            ];
        } elseif ($excludePages) {
            $dimensionFilterGroups[] = [
                'filters' => array_map(fn($page) => [
                    'dimension' => Dimension::PAGE,
                    'operator' => Operator::NOT_CONTAINS,
                    'expression' => $page
                ], $excludePages),
                'groupType' => GroupType::AND->value
            ];
        }
        return $dimensionFilterGroups;
    }

    /**
     * @param ArrayCollection $metrics
     * @param EntityManager $manager
     * @return array
     */
    protected static function processQueries(ArrayCollection $metrics, EntityManager $manager): array
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
        } catch (\Doctrine\DBAL\Exception $e) {
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
            } catch (\Doctrine\DBAL\Exception $e) {
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
        } catch (\Doctrine\DBAL\Exception $e) {
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
    protected static function processCampaigns(ArrayCollection $metrics, EntityManager $manager): array
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
    protected static function processChanneledCampaigns(ArrayCollection $metrics, EntityManager $manager): array
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
    protected static function processChanneledAdGroups(ArrayCollection $metrics, EntityManager $manager): array
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
    protected static function processChanneledAds(ArrayCollection $metrics, EntityManager $manager): array
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
    protected static function processPosts(ArrayCollection $metrics, EntityManager $manager): array
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
    protected static function processProducts(ArrayCollection $metrics, EntityManager $manager): array
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
    protected static function processCustomers(ArrayCollection $metrics, EntityManager $manager): array
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
    protected static function processOrders(ArrayCollection $metrics, EntityManager $manager): array
    {
        return [
            'map' => [],
            'mapReverse' => [],
        ];
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    protected static function processMetrics(
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
            'channel', 'name', 'period', 'metricDate', 'campaign_id', 'channeledCampaign_id', 'channeledAdGroup_id',
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
        $metricMap = self::getMetricMap(
            manager: $manager,
            sql: $sql,
            params: $selectParams,
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
                'INSERT INTO metrics (channel, name, period, metricDate, campaign_id, channeledCampaign_id, channeledAdGroup_id,
                            channeledAd_id, query_id, page_id, post_id, product_id, customer_id, order_id, country_id, device_id, value, metadata)
                     VALUES ' . implode(', ', array_fill(0, count($metricsToInsert), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')),
                        $insertParams
                    );

            // Re-fetch inserted metrics to get correct IDs
            $reFetchParams = [];
            $conditions = [];

            $fields = [
                'channel', 'name', 'period', 'metricDate',
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
     * @throws \Doctrine\DBAL\Exception
     */
    protected static function processChanneledMetrics(
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
        $channeledMetricMap = self::getChanneledMetricMap(
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
        $channeledMetricMap = self::getChanneledMetricMap(
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
     * @throws NotSupported
     * @throws ORMException
     * @throws \Doctrine\DBAL\Exception
     */
    protected static function processChanneledMetricDimensions(
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
        $dimensionMap = self::getDimensionMap(
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

            // Refetch dimensions to get their IDs
            $selectParams = [];
            $selectPlaceholders = [];
            $nullConditions = [];

            foreach ($dimensionsToInsert as $d) {
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
        }

        $manager->getConnection()
            ->executeQuery($sql, $selectParams)
            ->fetchAllAssociative();
    }

    /**
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
     * @throws \Doctrine\DBAL\Exception
     */
    protected static function getMetricMap(
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
     * @throws \Doctrine\DBAL\Exception
     * @throws Exception
     */
    protected static function getChanneledMetricMap(
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
     * @throws \Doctrine\DBAL\Exception
     */
    protected static function getDimensionMap(
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
}