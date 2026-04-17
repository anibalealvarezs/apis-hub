<?php

namespace Core\Services;

use DateTime;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

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
     * @param string $channel
     * @param string|array|null $startDateOrConfig
     * @param string|null $endDateStr
     * @param array $config
     * @param LoggerInterface|null $logger
     * @param string|null $instanceName
     * @return Response
     * @throws \Throwable
     */
    public function execute(
        string $channel,
        string|array|null $startDateOrConfig = null,
        string|null $endDateStr = null,
        array $config = [],
        ?LoggerInterface $logger = null,
        ?string $instanceName = null
    ): Response {
        if ($logger) {
            $this->logger = $logger;
        } elseif (! $this->logger) {
            $this->logger = Helpers::setLogger("sync-{$channel}.log");
        }

        try {
            $this->logger?->info("DEBUG: SyncService::execute - ENTRY", ['channel' => $channel]);

            $startDateStr = null;
            if (is_array($startDateOrConfig)) {
                $config = $startDateOrConfig;
            } else {
                $startDateStr = $startDateOrConfig;
            }

            // 1. Get official driver via Factory
            $this->logger?->info("DEBUG: SyncService::execute - RESOLVING DRIVER via Factory");
            $driver = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::get($channel, $this->logger, $config);
            $this->logger?->info("DEBUG: SyncService::execute - DRIVER RESOLVED", ['class' => get_class($driver)]);

            // 2. Build final configuration
            $validatedConfig = \Classes\DriverInitializer::validateConfig($channel, $this->logger);
            $finalConfig = array_merge($validatedConfig, $config);

            // 3. Inject production dependencies
            $finalConfig['manager'] = Helpers::getManager();
            $manager = $finalConfig['manager'];
            $this->logger?->info("DEBUG: SyncService::execute - Manager injected. ID: " . spl_object_id($manager) . " | Open: " . ($manager->isOpen() ? 'YES' : 'NO'));
            $finalConfig['seeder'] = new \Classes\ProductionEntityMapper($manager);

            // 4. Define and set Data Processor
            $dataProcessor = function ($data, $mixed = null) use ($manager) {
                $logger = ($mixed instanceof LoggerInterface) ? $mixed : $this->logger;
                $type = is_string($mixed) ? $mixed : null;

                if ($data instanceof \Doctrine\Common\Collections\ArrayCollection) {
                    return \Classes\Requests\MetricRequests::persist($data, $logger);
                }

                if ($data instanceof \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity && $type) {
                    $collection = new \Doctrine\Common\Collections\ArrayCollection([$data]);
                    switch ($type) {
                        case 'campaign':
                            \Classes\MarketingProcessor::processCampaigns($collection, $manager);
                            break;
                        case 'ad_group':
                            \Classes\MarketingProcessor::processAdGroups($collection, $manager);
                            break;
                        case 'ad':
                            \Classes\MarketingProcessor::processAds($collection, $manager);
                            break;
                        case 'creative':
                            \Classes\MarketingProcessor::processCreatives($collection, $manager);
                            break;
                        case 'page':
                            \Classes\SocialProcessor::processPages($collection, $manager);
                            break;
                        case 'post':
                        case 'ig_media':
                            \Classes\SocialProcessor::processPosts($collection, $manager);
                            break;
                    }
                    return null;
                }
                return ['metrics' => 0, 'rows' => 0, 'duplicates' => 0];
            };

            if (method_exists($driver, 'setDataProcessor')) {
                $driver->setDataProcessor($dataProcessor);
            }

            // 5. Date normalization
            $startDate = new DateTime($startDateStr ?? $finalConfig['startDate'] ?? $finalConfig['start_date'] ?? '-30 days');
            $endDate = new DateTime($endDateStr ?? $finalConfig['endDate'] ?? $finalConfig['end_date'] ?? 'now');

            // 5. Logging and Execution
            $sanitizedConfig = $finalConfig;
            array_walk_recursive($sanitizedConfig, function (&$value, $key) {
                if (preg_match('/(secret|token|pass|key)/i', (string)$key)) {
                    $value = '********';
                }
            });

            $this->logger->info("SyncService: Executing sync for channel '{$channel}'", [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'instance' => $instanceName,
                'config' => $sanitizedConfig,
            ]);

            $this->logger?->info("DEBUG: SyncService::execute - INVOKING driver->sync");
            
            $identityMapper = function (string $type, array $params) use ($finalConfig, $channel) {
                static $cache = [];
                $manager = $finalConfig['manager'] ?? Helpers::getManager();
                $repoMap = [
                    'channeled_accounts' => \Entities\Analytics\Channeled\ChanneledAccount::class,
                    'pages' => \Entities\Analytics\Page::class,
                    'channeled_campaigns' => \Entities\Analytics\Channeled\ChanneledCampaign::class,
                    'channeled_ad_groups' => \Entities\Analytics\Channeled\ChanneledAdGroup::class,
                    'channeled_ads' => \Entities\Analytics\Channeled\ChanneledAd::class,
                    'posts' => \Entities\Analytics\Post::class,
                ];

                if (!isset($repoMap[$type])) {
                    return null;
                }

                $cacheKey = $type . serialize($params);
                if (isset($cache[$cacheKey])) {
                    return $cache[$cacheKey];
                }

                $repository = $manager->getRepository($repoMap[$type]);
                $result = null;

                switch ($type) {
                    case 'pages':
                        $lookupField = 'canonicalId';
                        $searchValues = (array)($params['canonical_ids'] ?? []);
                        $isUrlLookup = false;
                        $canonicalMap = [];

                        if (isset($params['urls'])) {
                            $isUrlLookup = true;
                            $urls = (array)$params['urls'];
                            $canonicalMap = array_combine($urls, array_map(fn($u) => Helpers::getCanonicalPageId($u, null, 'website'), $urls));
                            $searchValues = array_values($canonicalMap);
                        } elseif (isset($params['platform_ids'])) {
                            $lookupField = 'platformId';
                            $searchValues = (array)$params['platform_ids'];
                        }

                        if (empty($searchValues)) break;

                        $pages = $repository->findBy([$lookupField => array_unique($searchValues)]);
                        $entityMap = [];
                        foreach ($pages as $p) {
                            $val = $p->{$lookupField == 'canonicalId' ? 'getCanonicalId' : 'getPlatformId'}();
                            $entityMap[(string)$val] = $p;
                        }

                        if ($isUrlLookup) {
                            foreach ($canonicalMap as $url => $cId) {
                                if (isset($entityMap[$cId])) $result[$url] = $entityMap[$cId];
                            }
                        } else {
                            $result = $entityMap;
                        }
                        break;

                    case 'channeled_accounts':
                    case 'channeled_campaigns':
                    case 'channeled_ad_groups':
                    case 'channeled_ads':
                    case 'posts':
                    default:
                        $idField = ($type === 'posts' ? 'postId' : 'platformId');
                        $getter = 'get'.ucfirst($idField);
                        $searchValues = (array)($params['platform_ids'] ?? []);
                        
                        $criteria = [];
                        if (!empty($searchValues)) $criteria[$idField] = array_unique($searchValues);
                        if (in_array($type, ['channeled_accounts', 'channeled_campaigns', 'channeled_ad_groups', 'channeled_ads'])) {
                            $enum = \Anibalealvarezs\ApiSkeleton\Enums\Channel::tryFromName($channel);
                            if ($enum) {
                                $criteria['channel'] = $enum->value;
                            }
                        }
                        if ($type === 'posts' && isset($params['page_id'])) $criteria['page'] = $params['page_id'];
                        if ($type === 'posts' && isset($params['channeled_account_id'])) $criteria['channeledAccount'] = $params['channeled_account_id'];

                        if (empty($criteria)) break;

                        $entities = $repository->findBy($criteria);
                        $result = [];
                        foreach ($entities as $e) {
                            $result[(string)$e->$getter()] = $e;
                        }
                        break;
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
                } catch (\Exception $e) {
                    $lastResult = false;
                }

                $lastCheck = time();

                return $lastResult;
            } : null;

            $result = $driver->sync($startDate, $endDate, $finalConfig, $shouldContinue, $identityMapper);
            $this->logger?->info("DEBUG: SyncService::execute - driver->sync RETURNED");

            return new Response(json_encode([
                'success' => true,
                'message' => 'Sync completed successfully',
                'data' => (array)$result
            ]), Response::HTTP_OK, ['Content-Type' => 'application/json']);

        } catch (\Throwable $e) {
            $this->logger->error("SyncService Error [{$channel}]: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
