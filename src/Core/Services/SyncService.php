<?php

    namespace Core\Services;

    use Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity;
    use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
    use Anibalealvarezs\ApiDriverCore\Enums\AssetCategory;
    use Classes\DriverInitializer;
    use Classes\MarketingProcessor;
    use Classes\ProductionEntityMapper;
    use Classes\Requests\MetricRequests;
    use Classes\SocialProcessor;
    use DateTime;
    use Doctrine\Common\Collections\ArrayCollection;
    use Entities\Analytics\Account;
    use Entities\Analytics\Channel;
    use Entities\Analytics\Channeled\ChanneledAccount;
    use Entities\Analytics\Channeled\ChanneledAd;
    use Entities\Analytics\Channeled\ChanneledAdGroup;
    use Entities\Analytics\Channeled\ChanneledCampaign;
    use Entities\Analytics\Page;
    use Entities\Analytics\Post;
    use Exception;
    use Helpers\Helpers;
    use Psr\Log\LoggerInterface;
    use RuntimeException;
    use Symfony\Component\HttpFoundation\Response;
    use Throwable;

    class SyncService
    {
        protected ?LoggerInterface $logger = null;

        /**
         * @param LoggerInterface|null $logger
         */
        public function __construct(?LoggerInterface $logger = null)
        {
            $this->logger = $logger;
        }

        /**
         * Executes the sync process for a given channel.
         *
         * @param Channel|string $channel
         * @param string|array|null $startDateOrConfig
         * @param string|null $endDateStr
         * @param array $config
         * @param LoggerInterface|null $logger
         * @param string|null $instanceName
         * @return Response
         * @throws Throwable
         */
        public function execute(
            object|string     $channel,
            string|array|null $startDateOrConfig = null,
            string|null       $endDateStr = null,
            array             $config = [],
            ?LoggerInterface  $logger = null,
            ?string           $instanceName = null
        ): Response
        {
            $channelName = is_object($channel) ? $channel->getName() : $channel;

            if ($logger) {
                $this->logger = $logger;
            } elseif (!$this->logger) {
                $this->logger = Helpers::setLogger("sync-$channelName.log");
            }

            try {
                $this->logger->info("DEBUG: SyncService::execute - ENTRY", ['channel' => $channelName]);

                $startDateStr = null;
                if (is_array($startDateOrConfig)) {
                    $config = $startDateOrConfig;
                } else {
                    $startDateStr = $startDateOrConfig;
                }

                // 1. Get official driver via Factory
                $this->logger->info("DEBUG: SyncService::execute - RESOLVING DRIVER via Factory");
                $driver = DriverFactory::get($channelName, $this->logger, $config);
                $this->logger->info("DEBUG: SyncService::execute - DRIVER RESOLVED", ['class' => get_class($driver)]);

                // 2. Build final configuration
                $baseConfig = DriverInitializer::validateConfig($channelName, $this->logger);
                $this->logger->info("DEBUG: SyncService::execute - Channel", [$channelName]);

                // Extract job-specific config, potentially nested under 'filters'
                $jobConfig = $config['filters'] ?? $config;

                // Merge base validated config with job-level overrides, then re-validate
                $mergedRaw = array_merge($baseConfig, (array)$jobConfig);
                $finalConfig = $driver->validateConfig($mergedRaw);

                // 3. Inject production dependencies
                $finalConfig['manager'] = Helpers::getManager();
                $manager = $finalConfig['manager'];
                $this->logger->info("DEBUG: SyncService::execute - Manager injected. ID: ".spl_object_id($manager)." | Open: ".($manager->isOpen() ? 'YES' : 'NO'));
                $finalConfig['seeder'] = new ProductionEntityMapper($manager);

                // 4. Define and set Data Processor
                $dataProcessor = function ($data, $mixed = null) use ($manager) {
                    $logger = ($mixed instanceof LoggerInterface) ? $mixed : $this->logger;
                    $type = is_string($mixed) ? $mixed : null;

                    if ($data instanceof ArrayCollection) {
                        if ($data->isEmpty()) {
                            return ['metrics' => 0, 'rows' => 0, 'duplicates' => 0];
                        }

                        $first = $data->first();
                        // If it's a collection of metrics (has metricConfigKey), use MetricRequests
                        if (isset($first->metricConfigKey)) {
                            return MetricRequests::persist($data, $logger);
                        }

                        // If it's a collection of entities, use appropriate processors
                        if ($first instanceof UniversalEntity && $type) {
                            $this->processUniversalEntity($type, $data, $manager);
                        }

                        return ['metrics' => 0, 'rows' => 0, 'duplicates' => 0];
                    }

                    if ($data instanceof UniversalEntity && $type) {
                        $collection = new ArrayCollection([$data]);
                        $this->processUniversalEntity($type, $collection, $manager);

                        return null;
                    }

                    return ['metrics' => 0, 'rows' => 0, 'duplicates' => 0];
                };

                if (method_exists($driver, 'setDataProcessor')) {
                    $driver->setDataProcessor($dataProcessor);
                }

                // 5. Date normalization
                $fallbackStart = $finalConfig['startDate'] ?? $finalConfig['start_date'] ?? '-30 days';
                $startDate = new DateTime($startDateStr ?? $fallbackStart);

                $fallbackEnd = $finalConfig['endDate'] ?? $finalConfig['end_date'] ?? $startDate->format('Y-m-d');
                $endDate = new DateTime($endDateStr ?? $fallbackEnd);

                // 5. Logging and Execution
                $sanitizedConfig = $finalConfig;
                array_walk_recursive($sanitizedConfig, function (&$value, $key) {
                    if (preg_match('/(secret|token|pass|key)/i', (string)$key)) {
                        $value = '********';
                    }
                });

                $this->logger->info("SyncService: Executing sync for channel '$channelName'", [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date'   => $endDate->format('Y-m-d'),
                    'instance'   => $instanceName,
                    'config'     => $sanitizedConfig,
                ]);

                $this->logger->info("DEBUG: SyncService::execute - INVOKING driver->sync");

                $identityMapper = function (string $type, array $params) use ($finalConfig, $channelName) {
                    static $cache = [];
                    $manager = $finalConfig['manager'] ?? Helpers::getManager();
                    $repoMap = [
                        'channeled_accounts'  => ChanneledAccount::class,
                        'pages'               => Page::class,
                        'channeled_campaigns' => ChanneledCampaign::class,
                        'channeled_ad_groups' => ChanneledAdGroup::class,
                        'channeled_ads'       => ChanneledAd::class,
                        'posts'               => Post::class,
                        'accounts'            => Account::class,
                    ];

                    if (!isset($repoMap[$type])) {
                        return null;
                    }

                    $cacheKey = $type.serialize($params);
                    if (isset($cache[$cacheKey])) {
                        return $cache[$cacheKey];
                    }

                    $repository = $manager->getRepository($repoMap[$type]);
                    $result = null;

                    $category = match ($type) {
                        'pages' => AssetCategory::PAGEABLE,
                        'channeled_accounts' => AssetCategory::IDENTITY,
                        'channeled_campaigns' => AssetCategory::CAMPAIGN,
                        'channeled_ad_groups' => AssetCategory::GROUPING,
                        'channeled_ads', 'posts' => AssetCategory::UNIT,
                        default => null
                    };

                    if (!$category) {
                        return null;
                    }

                    $driverClass = DriverFactory::getRegistry()[$channelName]['driver'] ?? null;
                    $context = $driverClass ? $driverClass::getContextForCategory($category) : '';

                    if ($type === 'pages') {
                        $lookupField = 'canonicalId';
                        $searchValues = (array)($params['canonical_ids'] ?? []);
                        $isUrlLookup = false;
                        $canonicalMap = [];

                        if (isset($params['urls'])) {
                            $isUrlLookup = true;
                            $urls = (array)$params['urls'];
                            $canonicalMap = array_combine($urls, array_map(function ($u) use ($driverClass, $category, $context, $channelName) {
                                if ($driverClass) {
                                    return $driverClass::getCanonicalId(['url' => $u], $category, $context);
                                }

                                throw new RuntimeException("Driver not found for channel: ".$channelName);
                            }, $urls));
                            $searchValues = array_values($canonicalMap);
                        } elseif (isset($params['platform_ids'])) {
                            $lookupField = 'platformId';
                            $ids = (array)$params['platform_ids'];
                            if ($driverClass) {
                                $searchValues = array_map(fn($id) => $driverClass::getPlatformId(['id' => $id], $category, $context), $ids);
                            } else {
                                $searchValues = $ids;
                            }
                        }

                        if (empty($searchValues)) {
                            return null;
                        }

                        $pages = $repository->findBy([$lookupField => array_unique($searchValues)]);
                        $entityMap = [];
                        foreach ($pages as $p) {
                            $val = $p->{$lookupField == 'canonicalId' ? 'getCanonicalId' : 'getPlatformId'}();
                            $entityMap[(string)$val] = $p;
                        }

                        if ($isUrlLookup) {
                            foreach ($canonicalMap as $url => $cId) {
                                if (isset($entityMap[$cId])) {
                                    $result[$url] = $entityMap[$cId];
                                }
                            }
                        } else {
                            $result = $entityMap;
                        }
                    } elseif ($type === 'accounts' && isset($params['names'])) {
                        $entities = $repository->findBy(['name' => $params['names']]);
                        $result = [];
                        foreach ($entities as $e) {
                            $result[(string)$e->getName()] = $e;
                        }
                    } else {
                        $idField = ($type === 'posts' ? 'postId' : 'platformId');
                        $getter = ($type === 'posts' ? 'getPostId' : 'getPlatformId');
                        $ids = (array)($params['platform_ids'] ?? []);

                        if ($driverClass) {
                            $searchValues = array_map(fn($id) => $driverClass::getPlatformId(['id' => $id], $category, $context), $ids);
                        } else {
                            $searchValues = $ids;
                        }

                        $criteria = [];
                        if (!empty($searchValues)) {
                            $criteria[$idField] = array_unique($searchValues);
                        }
                        if (in_array($type, ['channeled_accounts', 'channeled_campaigns', 'channeled_ad_groups', 'channeled_ads'])) {
                            $enum = Channel::tryFromName($channelName);
                            if ($enum) {
                                $criteria['channel'] = $enum->getId();
                            } else {
                                $this->logger->warning("SyncService::identityMapper - Channel '$channelName' not found in database channels table.");
                            }
                        }
                        $this->logger->info("SyncService::identityMapper - Lookup criteria for $type", ['criteria' => $criteria]);
                        if ($type === 'posts' && isset($params['page_id'])) {
                            $criteria['page'] = $params['page_id'];
                        }
                        if ($type === 'posts' && isset($params['channeled_account_id'])) {
                            $criteria['channeledAccount'] = $params['channeled_account_id'];
                        }

                        if (empty($criteria)) {
                            return [];
                        }

                        $entities = $repository->findBy($criteria);
                        $result = [];
                        foreach ($entities as $e) {
                            $result[(string)$e->$getter()] = $e;
                        }

                        return $cache[$cacheKey] = $result;
                    }

                    return $cache[$cacheKey] = $result;
                };

                $jobId = $finalConfig['jobId'] ?? null;
                $shouldContinue = $jobId ? function () use ($jobId) {
                    static $lastCheck = 0;
                    static $lastResult = true;

                    if (time() - $lastCheck < 5) {
                        return $lastResult;
                    }

                    try {
                        Helpers::checkJobStatus($jobId);
                        $lastResult = true;
                    } catch (Exception $e) {
                        $lastResult = false;
                    }

                    $lastCheck = time();

                    return $lastResult;
                } : null;

                $result = $driver->sync($startDate, $endDate, $finalConfig, $shouldContinue, $identityMapper);
                $this->logger->info("DEBUG: SyncService::execute - driver->sync RETURNED");

                // Handle cases where the driver returns a Response object on error (e.g., auth failure)
                $content = json_decode($result->getContent(), true);
                // Check for the specific auth failure signature from the driver
                if (isset($content['status'], $content['error_code']) && $content['status'] === 'error' && $content['error_code'] === 'auth_failure') {
                    if ($jobId) {
                        $this->logger->critical(
                            "Authentication failure detected from driver response for channel '$channelName'. Cancelling job #$jobId.",
                            ['response' => $content]
                        );
                        Helpers::cancelJob($jobId);
                    }

                    return $result; // Return the original error response
                }

                return new Response(json_encode([
                    'success' => true,
                    'message' => 'Sync completed successfully',
                    'data'    => (array)$result,
                ]), Response::HTTP_OK, ['Content-Type' => 'application/json']);

            } catch (Throwable $e) {
                // Handle non-retriable authentication exceptions that bubble up
                if (Helpers::isAuthenticationRevokedException($e)) {
                    $jobId = $finalConfig['jobId'] ?? $config['jobId'] ?? null;
                    if ($jobId) {
                        $this->logger->critical(
                            "A non-retriable authentication error occurred for channel '$channelName'. Cancelling job #$jobId.",
                            ['error' => $e->getMessage()]
                        );
                        Helpers::cancelJob($jobId);

                        // Return a structured error response instead of re-throwing
                        return new Response(json_encode([
                            'success' => false,
                            'message' => 'Authentication has been revoked. The job has been cancelled.',
                            'error'   => $e->getMessage(),
                        ]), Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'application/json']);
                    }
                }

                $this->logger->error("SyncService Error [$channelName]: ".$e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);

                throw $e;
            }
        }

        /**
         * @param string $type
         * @param ArrayCollection $collection
         * @param mixed $manager
         * @return void
         * @throws Exception
         */
        protected function processUniversalEntity(string $type, ArrayCollection $collection, mixed $manager): void
        {
            switch ($type) {
                case 'campaign':
                    MarketingProcessor::processCampaigns($collection, $manager);
                    break;
                case 'ad_group':
                    MarketingProcessor::processAdGroups($collection, $manager);
                    break;
                case 'ad':
                    MarketingProcessor::processAds($collection, $manager);
                    break;
                case 'creative':
                    MarketingProcessor::processCreatives($collection, $manager);
                    break;
                case 'page':
                    SocialProcessor::processPages($collection, $manager);
                    break;
                case 'post':
                case 'ig_media':
                    SocialProcessor::processPosts($collection, $manager);
                    break;
            }
        }
    }