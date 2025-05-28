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
use Classes\Overrides\GoogleApi\SearchConsoleApi\SearchConsoleApi;
use Classes\Overrides\KlaviyoApi\KlaviyoApi;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Exception\NotSupported;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\Mapping\MappingException;
use Entities\Analytics\Channeled\ChanneledMetric;
use Entities\Analytics\Channeled\ChanneledMetricDimension;
use Entities\Analytics\Country;
use Entities\Analytics\Device;
use Entities\Analytics\Metric;
use Entities\Analytics\Page;
use Entities\Analytics\Query;
use Enums\Channel;
use Enums\Period;
use Enums\Country as CountryEnum;
use Enums\Device as DeviceEnum;
use GuzzleHttp\Exception\GuzzleException;
use Helpers\Helpers;
use Interfaces\RequestInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Services\CacheService;
use Symfony\Component\HttpFoundation\Response;
use Exception;
use ValueError;

class MetricRequests implements RequestInterface
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
     * @throws GuzzleException
     * @throws NotSupported
     * @throws Exception
     */
    public static function getListFromGoogleSearchConsole(
        string $startDate = null,
        string $endDate = null,
        object $filters = null,
        string|bool $resume = true,
        LoggerInterface $logger = null
    ): Response {
        if (!$logger) {
            $logger = new Logger('gsc');
            $logger->pushHandler(new StreamHandler('logs/gsc.log', Level::Info));
        }

        $logger->info("Starting getListFromGoogleSearchConsole: startDate=" . $startDate . ", endDate=" . $endDate . ", resume=" . $resume);
        $manager = Helpers::getManager();
        try {
            // Validate configuration
            $config = Helpers::getChannelsConfig()['google'] ?? null;
            if (!$config) {
                throw new Exception("Missing 'google' configuration in channels config");
            }
            $scConfig = Helpers::getChannelsConfig()['google_search_console'] ?? null;
            if (!$scConfig) {
                throw new Exception("Missing 'google_search_console' configuration in channels config");
            }
            $bypassTargetKeywords = $scConfig['bypass_target_keywords'] ?? false;
            $rowLimit = $scConfig['rowLimit'] ?? 25000;
            $logger->info("Config: bypassTargetKeywords=" . ($bypassTargetKeywords ? 'true' : 'false') . ", rowLimit=$rowLimit");
            $logger->info("Loaded GSC config: sites=" . count($scConfig['sites']));

            // Initialize API with retry for transient errors
            $maxApiRetries = 3;
            $apiRetryCount = 0;
            $api = null;
            while ($apiRetryCount < $maxApiRetries) {
                try {
                    $api = new SearchConsoleApi(
                        redirectUrl: $config['redirect_uri'] ?? '',
                        clientId: $config['client_id'] ?? '',
                        clientSecret: $config['client_secret'] ?? '',
                        refreshToken: $config['refresh_token'] ?? '',
                        userId: $config['user_id'] ?? '',
                        scopes: [$scConfig['scope'] ?? ''],
                        token: $scConfig['token'] ?? ''
                    );
                    $logger->info("Initialized SearchConsoleApi");
                    break;
                } catch (Exception $e) {
                    $apiRetryCount++;
                    if ($apiRetryCount >= $maxApiRetries) {
                        throw new Exception("Failed to initialize SearchConsoleApi after $maxApiRetries retries: " . $e->getMessage());
                    }
                    $logger->warning("SearchConsoleApi initialization failed, retry $apiRetryCount/$maxApiRetries: " . $e->getMessage());
                    usleep(100000 * $apiRetryCount);
                }
            }

            $channeledMetricRepository = $manager->getRepository(ChanneledMetric::class);
            $pageRepository = $manager->getRepository(Page::class);
            $countryRepository = $manager->getRepository(Country::class);
            $deviceRepository = $manager->getRepository(Device::class);
            $metricNames = $filters->metricNames ?? ($scConfig['metrics'] ?? ['clicks', 'impressions', 'ctr', 'position']);
            $dimensions = $filters->dimensions ?? ['date', 'query', 'page', 'country', 'device'];
            $batchSize = 500;

            $logger->info("Initialized repositories, dimensions=" . implode(',', $dimensions) . ", metricNames=" . json_encode($metricNames) . ", batchSize=" . $batchSize);
            $logger->warning("Note: 'searchAppearance' is not included in dimensions due to GSC API restrictions; defaulting to 'WEB' in ChanneledMetricDimension");

            // Start single transaction
            $manager->getConnection()->beginTransaction();
            $logger->info("Started transaction");

            $createdPages = [];
            $counter = 0;
            $totalMetrics = 0;
            $totalRows = 0;
            $totalDuplicates = 0;
            foreach ($scConfig['sites'] as $site) {
                if ($counter >= 1) {
                    $logger->info("Stopping after first site for testing");
                    break; // Limit to one site for testing
                }
                $siteUrl = $site['url'];
                $siteKey = str_replace(['https://', 'sc-domain:', '/'], '', $siteUrl);
                $title = $site['title'] ?? $siteUrl;
                $hostname = $site['hostname'] ?? parse_url($siteUrl, PHP_URL_HOST) ?? str_replace('sc-domain:', '', $siteUrl);

                $normalizedSiteUrl = rtrim($siteUrl, '/');
                $logger->info("Processing site: " . $siteUrl . ", normalized: " . $normalizedSiteUrl . ", siteKey=" . $siteKey);

                $pageEntity = $pageRepository->findOneBy(['url' => $normalizedSiteUrl]);
                if (!$pageEntity) {
                    $logger->error("Page entity not found for URL=" . $normalizedSiteUrl . ". Run app:initialize-entities command.");
                    throw new Exception("Page entity not found for URL=" . $normalizedSiteUrl);
                }
                $logger->info("Found Page: ID=" . $pageEntity->getId() . ", URL=" . $normalizedSiteUrl);

                // Update title/hostname if changed (optional, for consistency)
                if ($pageEntity->getTitle() !== $title || $pageEntity->getHostname() !== $hostname) {
                    $pageEntity->addTitle($title)
                        ->addHostname($hostname)
                        ->addUpdatedAt(new DateTime());
                    $manager->persist($pageEntity);
                    $logger->info("Updated Page entity: ID=" . $pageEntity->getId() . ", URL=" . $normalizedSiteUrl);
                }

                $lastChanneledMetric = $channeledMetricRepository->getLastByPlatformCreatedAtForSite(
                    Channel::google_search_console->value,
                    $siteKey
                );
                $logger->info("Last channeled metric: " . ($lastChanneledMetric ? json_encode($lastChanneledMetric) : 'none'));

                $origin = Carbon::parse("2000-01-01");
                $now = Carbon::now();
                $min = $startDate ? Carbon::parse($startDate) : (
                $lastChanneledMetric && filter_var($resume, FILTER_VALIDATE_BOOLEAN)
                    ? Carbon::parse($lastChanneledMetric['platformCreatedAt'])
                    : $origin
                );
                $max = $endDate ? Carbon::parse($endDate) : null;
                $from = $origin->format('Y-m-d');
                if ($min->lte($now) && $min->gte($origin) && (!$max || $min->lt($max))) {
                    $from = $min->format('Y-m-d');
                }
                $to = $max->lte($now) ? $max->format('Y-m-d') : $now->format('Y-m-d');
                $logger->info("Date range: from=" . $from . ", to=" . $to);

                $targetKeywords = $site['target_keywords'] ?? [];
                $targetCountries = $site['target_countries'] ?? [];
                $dimensionFilterGroups = self::getDimensionFilterGroups($filters, $site);
                $logger->info("Target keywords: " . implode(',', $targetKeywords) . ", countries: " . implode(',', $targetCountries));

                $batch = new ArrayCollection();
                $loopCount = 0;
                $entitiesToInvalidate = ['metric' => [], 'channeledMetric' => [], 'query' => []];
                // Initialize caches once per site
                $queryCache = [];
                $metricCache = [];
                $channeledMetricCache = [];
                $dimensionCache = [];
                $startTime = microtime(true);

                // Split into daily requests
                $period = Carbon::parse($from)->toPeriod($to, '1 day');
                foreach ($period as $day) {
                    $dayStr = $day->format('Y-m-d');
                    $logger->info("Processing GSC data for site " . $siteUrl . ", date " . $dayStr);

                    try {
                        $api->getAllSearchQueryResultsAndProcess(
                            siteUrl: $siteUrl,
                            startDate: $dayStr,
                            endDate: $dayStr,
                            rowLimit: $rowLimit,
                            dimensions: $dimensions,
                            dimensionFilterGroups: $dimensionFilterGroups,
                            callback: function ($rows) use ($siteUrl, $siteKey, $metricNames, &$batch, $batchSize, $targetKeywords, $targetCountries, &$loopCount, &$totalMetrics, &$totalRows, &$entitiesToInvalidate, &$queryCache, &$metricCache, &$channeledMetricCache, &$dimensionCache, $logger, $manager, $pageEntity, $countryRepository, $deviceRepository, &$totalDuplicates) {
                                try {
                                    $loopStart = microtime(true);
                                    $loopCount++;
                                    $totalRows += count($rows);
                                    $logger->info("Processing API callback loop " . $loopCount . ", rows=" . count($rows) . ", totalRows=" . $totalRows);
                                    $logger->debug("Raw API rows: " . json_encode(array_slice($rows, 0, 5))); // Log first 5 rows

                                    $pageMetrics = GoogleSearchConsoleConvert::metrics($rows, $siteUrl, $siteKey, $targetKeywords, $targetCountries, $logger, $pageEntity, $manager);
                                    $logger->info("Converted " . count($rows) . " rows to " . count($pageMetrics) . " metrics, first metric: " . (count($pageMetrics) > 0 ? json_encode(['name' => $pageMetrics[0]->name, 'query' => is_string($pageMetrics[0]->query) ? $pageMetrics[0]->query : ($pageMetrics[0]->query instanceof Query ? $pageMetrics[0]->query->getQuery() : 'none')]) : 'none'));

                                    // Check for duplicate metrics in pageMetrics
                                    $metricKeys = [];
                                    $queuedMetrics = 0;
                                    $filteredMetrics = 0;
                                    $skippedMetrics = 0;
                                    foreach ($pageMetrics as $index => $metric) {
                                        $queryStr = is_string($metric->query) ? $metric->query : ($metric->query instanceof Query ? $metric->query->getQuery() : 'none');
                                        $metricKey = md5(json_encode([
                                            'channel' => $metric->channel,
                                            'name' => $metric->name,
                                            'period' => $metric->period,
                                            'metricDate' => $metric->metricDate->format('Y-m-d'),
                                            'query' => $queryStr,
                                            'pageId' => $pageEntity?->getId(),
                                            'pageUrl' => $dimensions['page'] ?? 'none',
                                            'countryCode' => $metric->countryCode,
                                            'deviceType' => $metric->deviceType,
                                            'dimensions' => $dimensions ?? [],
                                        ], JSON_UNESCAPED_UNICODE));
                                        if (in_array($metricKey, $metricKeys)) {
                                            $logger->warning("Duplicate metric at index=$index: name={$metric->name}, query=$queryStr, date={$metric->metricDate->format('Y-m-d')}");
                                            $skippedMetrics++;
                                            $totalDuplicates++;
                                            continue;
                                        }
                                        $metricKeys[] = $metricKey;

                                        if (!$metricNames || in_array($metric->name, $metricNames)) {
                                            $countryCode = $metric->countryCode;
                                            try {
                                                $countryEnum = CountryEnum::from($countryCode);
                                            } catch (ValueError $e) {
                                                $countryEnum = CountryEnum::OTH;
                                                $logger->info("Country set to 'OTH' for query=$queryStr, original=$countryCode");
                                            }
                                            $countryEntity = $countryRepository->findOneBy(['code' => $countryEnum]);
                                            if (!$countryEntity) {
                                                $countryEntity = $countryRepository->findOneBy(['code' => CountryEnum::OTH]);
                                                if (!$countryEntity) {
                                                    $logger->error("Country OTH not found");
                                                    return;
                                                }
                                                $logger->info("Country set to 'OTH' for query=$queryStr, original=$countryCode");
                                            }
                                            $metric->country = $countryEntity;

                                            $deviceType = $metric->deviceType;
                                            try {
                                                $deviceEnum = DeviceEnum::from($deviceType);
                                            } catch (ValueError $e) {
                                                $deviceEnum = DeviceEnum::OTHER;
                                                $logger->warning("Invalid deviceType '$deviceType', defaulting to OTHER");
                                            }
                                            $deviceEntity = $deviceRepository->findOneBy(['type' => $deviceEnum]);
                                            if (!$deviceEntity) {
                                                $logger->error("Device $deviceType not found");
                                                return;
                                            }
                                            $metric->device = $deviceEntity;

                                            $batch->add($metric);
                                            $queuedMetrics++;
                                            $totalMetrics++;
                                            $logger->info("Queued metric: query=$queryStr, name=$metric->name");
                                        } else {
                                            $logger->warning("Skipped metric: name=$metric->name, not in " . json_encode($metricNames));
                                            $filteredMetrics++;
                                        }
                                    }
                                    $logger->info("Processed rows: metrics=" . count($pageMetrics) . ", queued=$queuedMetrics, filtered=$filteredMetrics, duplicates=$totalDuplicates, skipped=$skippedMetrics");

                                    // Process batch if full
                                    if ($batch->count() >= $batchSize) {
                                        $batchStart = microtime(true);
                                        $logger->info("Processing batch " . $loopCount . " for site " . $siteUrl . ", " . $batch->count() . " metrics");
                                        try {
                                            $manager->persist($pageEntity);
                                            /* foreach ($batch as $metric) {
                                                $manager->persist($metric);
                                            } */
                                            self::processBatch(
                                                $batch->toArray(),
                                                $manager,
                                                self::initializeRepositories($manager),
                                                $entitiesToInvalidate,
                                                $queryCache,
                                                $metricCache,
                                                $channeledMetricCache,
                                                $dimensionCache,
                                                $logger,
                                                $pageEntity
                                            );
                                            $manager->flush();
                                            $logger->info("Flushed batch: inserts=" . count($manager->getUnitOfWork()->getScheduledEntityInsertions()) . ", updates=" . count($manager->getUnitOfWork()->getScheduledEntityUpdates()));
                                            $batchTime = microtime(true) - $batchStart;
                                            $logger->info("Processed batch " . $loopCount . " for site " . $siteUrl . ", took " . $batchTime . " seconds");
                                            $batch->clear();
                                            gc_collect_cycles();
                                        } catch (ORMException $e) {
                                            $logger->error("Error processing batch " . $loopCount . " for site " . $siteUrl . ": " . $e->getMessage());
                                            throw $e;
                                        }
                                    }

                                    $loopTime = microtime(true) - $loopStart;
                                    $logger->info("Loop " . $loopCount . ": Converted " . count($rows) . " rows to " . count($pageMetrics) . " metrics, queued " . $queuedMetrics . " metrics, filtered " . $filteredMetrics . " metrics for site " . $siteUrl . ", took " . $loopTime . " seconds");
                                } catch (Exception $e) {
                                    $logger->error("Error in API callback loop " . $loopCount . " for site " . $siteUrl . ": " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
                                    throw $e;
                                }
                            }
                        );
                        $logger->info("Completed API query for date=$dayStr, duplicates=$totalDuplicates");

                        // Final batch after daily loop:
                        // Process any remaining metrics in the batch
                        if ($batch->count() > 0) {
                            $batchStart = microtime(true);
                            $logger->info("Processing final batch for site " . $siteUrl . ", date " . $dayStr . ", " . $batch->count() . " metrics");
                            try {
                                $manager->persist($pageEntity);
                                /* foreach ($batch as $metric) {
                                    $manager->persist($metric);
                                } */
                                self::processBatch(
                                    $batch->toArray(),
                                    $manager,
                                    self::initializeRepositories($manager),
                                    $entitiesToInvalidate,
                                    $queryCache,
                                    $metricCache,
                                    $channeledMetricCache,
                                    $dimensionCache,
                                    $logger,
                                    $pageEntity
                                );
                                $manager->flush();
                                $logger->info("Flushed final batch: inserts=" . count($manager->getUnitOfWork()->getScheduledEntityInsertions()) . ", updates=" . count($manager->getUnitOfWork()->getScheduledEntityUpdates()));
                                $batchTime = microtime(true) - $batchStart;
                                $batch->clear();
                                $logger->info("Processed final batch for site " . $siteUrl . ", date " . $dayStr . ", took " . $batchTime . " seconds");
                                gc_collect_cycles();
                            } catch (ORMException $e) {
                                $logger->error("Error processing final batch for site " . $siteUrl . ", date " . $dayStr . ": " . $e->getMessage());
                                throw $e;
                            }
                        }

                        // Update metrics values for this day
                        $logger->info("Updating metrics values for site " . $siteUrl . ", date " . $dayStr);
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
                            $logger->info("Updated metrics values for site " . $siteUrl . ", date " . $dayStr);
                        } catch (\Doctrine\DBAL\Exception $e) {
                            $logger->error("Error updating metrics values for site " . $siteUrl . ", date " . $dayStr . ": " . $e->getMessage());
                            throw $e;
                        }
                    } catch (GuzzleException $e) {
                        $logger->error("GSC API error for site " . $siteUrl . ", date " . $dayStr . ": " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
                        throw $e;
                    } catch (Exception $e) {
                        $logger->error("Error during GSC API query for site " . $siteUrl . ", date " . $dayStr . ": " . $e->getMessage() . ", trace: " . $e->getTraceAsString());
                        throw $e;
                    }
                }

                $counter++;
            }

            // Flush and commit all changes
            if ($createdPages || $totalMetrics > 0) {
                $logger->info("Flushing " . count($createdPages) . " new Page entities and " . $totalMetrics . " metrics");
                $manager->flush();
                $manager->getConnection()->commit();
                $logger->info("Committed transaction");
            } else {
                $logger->info("No changes to flush, rolling back transaction");
                $manager->getConnection()->rollback();
            }
            $logger->info("Completed: metrics=$totalMetrics, rows=$totalRows, duplicates=$totalDuplicates");

            $logger->info("Invalidating cache for " . count($entitiesToInvalidate['metric']) . " metrics, " . count($entitiesToInvalidate['channeledMetric']) . " channeled metrics, " . count($entitiesToInvalidate['query']) . " queries");
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

            $totalTime = microtime(true) - $startTime;
            $logger->info("Fetched and processed " . $totalMetrics . " metrics from " . $totalRows . " rows for all sites from " . $from . " to " . $to . ", took " . $totalTime . " seconds");

            return new Response(json_encode(['Metrics retrieved']));
        } catch (Exception $e) {
            // Safely handle transaction rollback
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
     * @throws OptimisticLockException
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

                $skippedMetrics = 0;
                $metricsCount = 0;
                $channeledMetricsCount = 0;
                $dimensionsCount = 0;
                $entitiesToPersist = ['metrics' => [], 'channeledMetrics' => [], 'dimensions' => []];

                foreach ($batch as $index => $metric) {
                    if (empty($metric->name)) {
                        $logger->warning("Skipping metric at index $index: missing name");
                        $skippedMetrics++;
                        continue;
                    }

                    try {
                        $queryString = is_string($metric->query) ? $metric->query : ($metric->query instanceof Query ? $metric->query->getQuery() : 'none');
                        if ($metric->page instanceof Page && $metric->page !== $pageEntity) {
                            $logger->warning("Metric page mismatch at index $index: query=$queryString, name=$metric->name");
                            $metric->page = $pageEntity;
                        }

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
                        if (!$manager->contains($metricEntity)) {
                            $logger->warning("Metric entity detached: ID=" . $metricEntity->getId() . ", name=$metric->name");
                            $metricEntity = $manager->find(Metric::class, $metricEntity->getId());
                            if (!$metricEntity) {
                                $logger->error("Failed to reattach Metric entity: ID=" . $metricEntity->getId());
                                throw new RuntimeException("Failed to reattach Metric entity");
                            }
                        }
                        $manager->persist($metricEntity);
                        $entitiesToPersist['metrics'][] = $metricEntity;
                        $metricsCount++;
                        $logger->info("Metric " . ($metricEntity->getId() ? "found" : "created") . ": ID=" . $metricEntity->getId() . ", name=$metric->name");

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
                        if (!$manager->contains($channeledMetricEntity)) {
                            $logger->warning("ChanneledMetric entity detached: ID=" . $channeledMetricEntity->getId());
                            $channeledMetricEntity = $manager->find(ChanneledMetric::class, $channeledMetricEntity->getId());
                            if (!$channeledMetricEntity) {
                                $logger->error("Failed to reattach ChanneledMetric entity: ID=" . $channeledMetricEntity->getId());
                                throw new RuntimeException("Failed to reattach ChanneledMetric entity");
                            }
                        }
                        $manager->persist($channeledMetricEntity);
                        $entitiesToPersist['channeledMetrics'][] = $channeledMetricEntity;
                        $channeledMetricsCount++;
                        $logger->info("ChanneledMetric " . ($channeledMetricEntity->getId() ? "found" : "created") . ": ID=" . $channeledMetricEntity->getId());

                        // Count dimensions for logging (persisted in getOrCreateChanneledMetric)
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
                        $skippedMetrics++;
                        continue;
                    } catch (Exception $e) {
                        $logger->error("Error processing metric at index $index, query=$queryString: " . $e->getMessage());
                        $skippedMetrics++;
                        continue;
                    }
                }

                $logger->info("Entities queued for persistence: metrics=$metricsCount, channeledMetrics=$channeledMetricsCount, dimensions=$dimensionsCount, skipped=$skippedMetrics");

                try {
                    $uow = $manager->getUnitOfWork();
                    $metricManaged = $metricsCount > 0 ? ($manager->contains($entitiesToPersist['metrics'][0] ?? $metricEntity) ? 'yes' : 'no') : 'none';
                    $channeledMetricManaged = $channeledMetricsCount > 0 ? ($manager->contains($entitiesToPersist['channeledMetrics'][0] ?? $channeledMetricEntity) ? 'yes' : 'no') : 'none';
                    $logger->info("Entity management before flush: Metric managed=$metricManaged, ChanneledMetric managed=$channeledMetricManaged");
                    $scheduledInserts = count($uow->getScheduledEntityInsertions());
                    $scheduledUpdates = count($uow->getScheduledEntityUpdates());
                    $logger->info("Scheduled before flush: inserts=$scheduledInserts, updates=$scheduledUpdates");

                    $manager->flush();
                    $logger->info("Flushed batch: inserts=$scheduledInserts, updates=$scheduledUpdates");
                    $logger->info("Transaction active after flush: " . ($manager->getConnection()->isTransactionActive() ? 'yes' : 'no'));
                    $logger->info("Committed batch with $metricsCount metrics");
                } catch (ORMException $e) {
                    $logger->error("Flush failed in processBatch: metrics=$metricsCount, channeledMetrics=$channeledMetricsCount, dimensions=$dimensionsCount, error=" . $e->getMessage());
                    throw $e;
                }

                break;
            } catch (OptimisticLockException $e) {
                if ($retryCount < $maxRetries - 1) {
                    $retryCount++;
                    usleep(100000 * $retryCount);
                    $logger->error("processBatch retry $retryCount/$maxRetries due to OptimisticLockException: " . $e->getMessage());
                    continue;
                }
                $logger->error("processBatch failed after $maxRetries retries due to OptimisticLockException: " . $e->getMessage());
                throw $e;
            } catch (ORMException $e) {
                if ($retryCount < $maxRetries - 1) {
                    $retryCount++;
                    usleep(100000 * $retryCount);
                    $logger->error("processBatch retry $retryCount/$maxRetries due to ORMException: " . $e->getMessage());
                    continue;
                }
                $logger->error("processBatch failed after $maxRetries retries due to ORMException: " . $e->getMessage());
                throw $e;
            } catch (Exception $e) {
                if ($retryCount < $maxRetries - 1) {
                    $retryCount++;
                    usleep(100000 * $retryCount);
                    $logger->error("processBatch retry $retryCount/$maxRetries due to: " . $e->getMessage());
                    continue;
                }
                $logger->error("processBatch failed after $maxRetries retries: " . $e->getMessage());
                throw $e;
            }
        }
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
        $queryString = is_string($metric->query) ? strtolower(trim($metric->query)) : ($metric->query instanceof Query ? strtolower(trim($metric->query->getQuery())) : 'none');
        $logger->info("Entering getOrCreateMetric: metricName={$metric->name}, query={$queryString}, metricDate={$metric->metricDate->format('Y-m-d')}");

        if ($em && $metric->page instanceof Page && $metric->page !== $pageEntity) {
            $logger->warning("Metric page mismatch: query=$queryString, name=$metric->name");
            $metric->page = $pageEntity;
        }

        // Validate metric value
        if (!isset($metric->value)) {
            $logger->warning("Metric value is NULL or unset for name={$metric->name}, query={$queryString}, defaulting to 0.0");
            $metric->value = 0.0;
        }

        $retryCount = 0;
        $maxRetries = 3;
        while ($retryCount < $maxRetries) {
            try {
                $queryEntity = null;
                if ($metric->channel === Channel::google_search_console->value && isset($metric->query)) {
                    if (!is_string($queryString) || empty(trim($queryString))) {
                        $logger->warning("Invalid query: " . print_r($queryString, true));
                        $queryString = 'unknown';
                    }
                    $queryKey = md5($queryString);
                    if (!isset($queryCache[$queryKey])) {
                        $queryEntity = $queryRepository->findOneBy(['query' => $queryString]);
                        if (!$queryEntity) {
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

                $dimensions = isset($metric->dimensions) ? array_column((array)$metric->dimensions ?? [], 'dimensionValue', 'dimensionKey') : [];
                $normalizedDimensions = array_map(function ($value) {
                    return is_string($value) ? strtolower(trim($value)) : ($value ?? 'unknown');
                }, $dimensions);
                // Ensure date dimension matches metricDate
                if (isset($metric->metricDate)) {
                    $normalizedDimensions['date'] = $metric->metricDate->format('Y-m-d');
                }

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

                if (isset($metricCache[$metricKey])) {
                    $logger->info("Metric found in cache: ID=" . $metricCache[$metricKey]->getId() . ", name=$metric->name, query=$queryString");
                    return $metricCache[$metricKey];
                }

                // Use findOneBy with exact metric_unique fields
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

                $metricEntity = $repository->findOneBy($criteria);

                if ($metricEntity) {
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

                $logger->info("Creating new Metric for $metric->name, query=$queryString");
                $metric->query = $queryEntity;
                try {
                    $cleanMetric = (object) [
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
                    ];
                    $metricEntity = $repository->create($cleanMetric, true);
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

        $dimensionsToPersist = [];
        $retryCount = 0;
        $maxRetries = 3;

        while ($retryCount < $maxRetries) {
            try {
                $logger->info("Attempting to find ChanneledMetric: platformId=" . ($channeledMetric->platformId ?? 'null') . ", channel=" . ($channeledMetric->channel ?? 'null') . ", metricId={$metricEntity->getId()}, platformCreatedAt={$platformCreatedAt->format('Y-m-d')}");

                // Check cache first
                if (isset($channeledMetricCache[$cacheKey])) {
                    $logger->info("ChanneledMetric found in cache: id=" . $channeledMetricCache[$cacheKey]->getId() . ", platformId=" . ($channeledMetric->platformId ?? 'null'));
                    $channeledMetricEntity = $channeledMetricCache[$cacheKey];
                } else {
                    // Query database
                    $criteria = [
                        'platformId' => $channeledMetric->platformId ?? null,
                        'channel' => $channeledMetric->channel ?? 8,
                        'metric' => $metricEntity,
                        'platformCreatedAt' => $platformCreatedAt
                    ];
                    $existing = $repository->findOneBy($criteria);

                    if ($existing) {
                        // Update existing entity
                        $existingData = $existing->getData() ?? [];
                        $newData = (array) ($channeledMetric->data ?? []);
                        $updatedData = [
                            'impressions' => max($existingData['impressions'] ?? 0, $newData['impressions'] ?? 0),
                            'clicks' => max($existingData['clicks'] ?? 0, $newData['clicks'] ?? 0),
                            'position_weighted' => max($existingData['position_weighted'] ?? 0, $newData['position_weighted'] ?? 0),
                            'ctr' => max($existingData['ctr'] ?? 0, $newData['ctr'] ?? 0)
                        ];

                        $existing->addData($updatedData);
                        $existing->addPlatformCreatedAt($platformCreatedAt);
                        $existing->addUpdatedAt(new DateTime());
                        $manager->persist($existing);

                        // Check if entity is managed
                        if (!$manager->contains($existing)) {
                            $logger->error("ChanneledMetric entity detached after update: ID=" . $existing->getId() . ", platformId=" . ($channeledMetric->platformId ?? 'null'));
                            $existing = $manager->find(ChanneledMetric::class, $existing->getId());
                            if (!$existing) {
                                $logger->error("Failed to reattach ChanneledMetric: ID=" . $existing->getId() . ", platformId=" . ($channeledMetric->platformId ?? 'null'));
                                throw new Exception("Failed to reattach ChanneledMetric ID=" . $existing->getId());
                            }
                            // Reapply updates
                            $existing->addData($updatedData);
                            $existing->addPlatformCreatedAt($platformCreatedAt);
                            $existing->addUpdatedAt(new DateTime());
                            $manager->persist($existing);
                        }
                        $channeledMetricCache[$cacheKey] = $existing; // Cache the updated entity
                        $logger->info("Updated existing ChanneledMetric: id=" . $existing->getId() . ", platformId=" . ($channeledMetric->platformId ?? 'null') . ", data=" . json_encode($updatedData));
                        $channeledMetricEntity = $existing;
                    } else {
                        // Create new entity
                        $channeledMetric->data = (array) ($channeledMetric->data ?? []);
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
     * @throws OptimisticLockException
     * @throws ORMException
     */
    public static function getOrCreateChanneledMetricDimension(
        ChanneledMetric $channeledMetricEntity,
        ?string $dimensionKey,
        ?string $dimensionValue,
        EntityRepository $repository
    ): ChanneledMetricDimension {
        $existing = $repository->findOneByKeyValueAndMetric(
            $dimensionKey,
            $dimensionValue,
            $channeledMetricEntity
        );

        if ($existing) {
            return $existing;
        }

        return $repository->create(
            data: (object)[
                'dimensionKey' => $dimensionKey,
                'dimensionValue' => $dimensionValue,
                'channeledMetric' => $channeledMetricEntity,
            ],
            returnEntity: true
        );
    }

    /**
     * Finalizes relationships and persists entities.
     *
     * @param Metric $metricEntity
     * @param ChanneledMetric $channeledMetricEntity
     * @param EntityManager $manager
     * @return void
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private static function finalizeMetricRelationships(
        Metric $metricEntity,
        ChanneledMetric $channeledMetricEntity,
        EntityManager $manager
    ): void {
        $manager->persist($metricEntity);
        $manager->persist($channeledMetricEntity);
        $manager->flush();
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
}