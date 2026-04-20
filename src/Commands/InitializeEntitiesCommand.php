<?php

declare(strict_types=1);

namespace Commands;

use Classes\DriverInitializer;
use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Entities\Analytics\Country;
use Entities\Analytics\Device;
use Anibalealvarezs\ApiSkeleton\Enums\Country as CountryEnum;
use Anibalealvarezs\ApiSkeleton\Enums\Device as DeviceEnum;
use Exception;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;

#[AsCommand(
    name: 'app:initialize-entities',
    description: 'Initializes Core and Channel-specific entities in the database'
)]
class InitializeEntitiesCommand extends Command
{
    protected EntityManagerInterface $entityManager;
    private ?LoggerInterface $logger = null;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger = new ConsoleLogger($output);
        $this->logger->info("Starting dynamic entity initialization...");

        try {
            // 1. Initialize Core Entities
            $this->initializeCoreEntities();

            // 2. Initialize Channel-specific Entities via Drivers
            $this->initializeChannelEntities();

            $this->logger->info("Initialization complete.");

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->logger?->error("Initialization failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function initializeCoreEntities(): void
    {
        $this->logger->info("Initializing Core Entities (Countries, Devices)...");

        $countryRepository = $this->entityManager->getRepository(Country::class);
        $added = 0;
        foreach (CountryEnum::cases() as $countryEnum) {
            $country = $countryRepository->getByCode($countryEnum->value);
            if (!$country) {
                $country = new Country();
                $country->addCode($countryEnum)
                    ->addName($countryEnum->getFullName());
                $this->entityManager->persist($country);
                $added++;
            }
        }
        $this->entityManager->flush();
        $this->logger->info("  - Countries: $added added.");

        $deviceRepository = $this->entityManager->getRepository(Device::class);
        $added = 0;
        foreach (DeviceEnum::cases() as $deviceCase) {
            $device = $deviceRepository->getByType($deviceCase->value);
            if (!$device) {
                $device = new Device();
                $device->addType($deviceCase);
                $this->entityManager->persist($device);
                $added++;
            }
        }

        $this->entityManager->flush();
        $this->logger->info("  - Devices: $added added.");
        $this->logger->info("Core entities initialization complete.");
    }

    protected function initializeChannelEntities(): void
    {
        $registry = DriverFactory::getRegistry();
        $channelsConfig = Helpers::getChannelsConfig();
        
        foreach ($registry as $channel => $driverCfg) {
            // Determine if specifically enabled for this channel
            $chanConfig = $channelsConfig[$channel] ?? [];
            
            $driverClass = $driverCfg['driver'] ?? null;
            if (!$driverClass || !class_exists($driverClass)) {
                $this->logger->error("Driver class '$driverClass' not found for channel: $channel");
                continue;
            }

            // Merge common configurations if specified by the driver
            $commonKey = $driverClass::getCommonConfigKey();
            if ($commonKey && isset($channelsConfig[$commonKey])) {
                $chanConfig = array_replace_recursive($channelsConfig[$commonKey], $chanConfig);
            }

            if (!($chanConfig['enabled'] ?? false)) {
                $this->logger->info("Channel '$channel' is disabled. Skipping.");
                continue;
            }

            $this->logger->info("Initializing entities for channel: $channel...");

            try {
                $driver = DriverFactory::get($channel, $this->logger);

                $dataProcessor = function ($collection, $logger) {
                    $stats = ['rows' => 0, 'metrics' => 0, 'duplicates' => 0];
                    if ($collection instanceof \Doctrine\Common\Collections\ArrayCollection) {
                        foreach ($collection as $entity) {
                            if ($entity instanceof \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity) {
                                // For now, we manually map UniversalEntity to Doctrine entities in this command
                                // but we could make it more generic.
                                // Actually, for initialization, we mostly deal with Pages and ChanneledAccounts
                                \Classes\SocialProcessor::processUniversalEntity($entity, $this->entityManager);
                                $stats['rows']++;
                            } else {
                                $this->entityManager->persist($entity);
                                $stats['rows']++;
                            }
                        }
                        $this->entityManager->flush();
                    }
                    return $stats;
                };

                /** @var \Entities\Analytics\Channel $channelEntity */
                $channelEntity = $this->entityManager->getRepository(\Entities\Analytics\Channel::class)->findOneBy(['name' => $channel]);
                if (!$channelEntity) {
                    $this->logger->error("  - ERROR: Channel entity '$channel' NOT FOUND in database. Ensure 'app:install-drivers' ran correctly.");
                    continue; // Skip this channel instead of failing the whole command
                }

                $identityMapper = function (string $type, array $params) use ($channel, $channelEntity) {
                    $repoMap = [
                        'channeled_accounts' => \Entities\Analytics\Channeled\ChanneledAccount::class,
                        'pages' => \Entities\Analytics\Page::class,
                        'accounts' => \Entities\Analytics\Account::class,
                    ];
                    if (!isset($repoMap[$type])) return [];
                    /** @var \Repositories\PageRepository|\Repositories\ChanneledAccountRepository $repo */
                    $repo = $this->entityManager->getRepository($repoMap[$type]);
                    $map = [];
                    if ($type === 'pages') {
                        $lookupField = 'platformId';
                        $searchValues = (array)($params['platform_ids'] ?? []);
                        if (isset($params['canonical_ids'])) {
                            $lookupField = 'canonicalId';
                            $searchValues = (array)$params['canonical_ids'];
                        } elseif (isset($params['urls'])) {
                            $lookupField = 'canonicalId';
                            $urls = (array)$params['urls'];
                            $searchValues = array_map(fn($u) => Helpers::getCanonicalPageId($u, null, 'website'), $urls);
                        }
                        if (!empty($searchValues)) {
                            $entities = $repo->findBy([$lookupField => array_unique($searchValues)]);
                            $getter = 'get'.ucfirst($lookupField);
                            foreach ($entities as $e) $map[(string)$e->$getter()] = $e;
                        }
                    } elseif ($type === 'channeled_accounts' && isset($params['platform_ids'])) {
                        $entities = $repo->findBy(['platformId' => $params['platform_ids'], 'channel' => $channelEntity]);
                        foreach ($entities as $e) $map[(string)$e->getPlatformId()] = $e;
                    } elseif ($type === 'accounts' && isset($params['names'])) {
                        $entities = $repo->findBy(['name' => $params['names']]);
                        foreach ($entities as $e) $map[(string)$e->getName()] = $e;
                    }
                    return $map;
                };

                // 1. Sync Assets from YAML to Database (Ported from ConfigManagerController)
                $patterns = $driver->getAssetPatterns();

                // Determine Group Account
                $commonKey = $driver::getCommonConfigKey();
                $defaultGroupName = method_exists($driver, 'getChannelLabel') ? $driver::getChannelLabel() : "Default Group";
                $groupName = $chanConfig['accounts_group_name'] ?? ($channelsConfig[$commonKey]['accounts_group_name'] ?? $defaultGroupName);

                $accountRepo = $this->entityManager->getRepository(\Entities\Analytics\Account::class);
                $accountEntity = $accountRepo->findOneBy(['name' => $groupName]);
                if (!$accountEntity) {
                    $this->logger->info("  - Creating default account group: $groupName");
                    $accountEntity = new \Entities\Analytics\Account();
                    $accountEntity->addName($groupName);
                    $this->entityManager->persist($accountEntity);
                    $this->entityManager->flush($accountEntity);
                }

                $isUrlBasedProvider = ($channel === 'google_search_console' || str_contains($channel, 'search_console'));

                foreach ($patterns as $assetKey => $pattern) {
                    $configKey = $pattern['key'] ?? $assetKey;
                    $assets = $chanConfig[$configKey] ?? [];
                    if (empty($assets)) continue;

                    $typeMark = $pattern['type'] ?? null;
                    if (!$typeMark) continue;

                    foreach ($assets as $asset) {
                        $id = (string)($asset['id'] ?? ($asset['url'] ?? ''));
                        if (!$id) continue;

                        if ($isUrlBasedProvider && filter_var($id, FILTER_VALIDATE_URL)) {
                            $id = md5(rtrim($id, '/'));
                        }

                        $name = $asset['name'] ?? $asset['title'] ?? ("Asset " . $id);
                        $chanAccountRepo = $this->entityManager->getRepository(\Entities\Analytics\Channeled\ChanneledAccount::class);
                        
                        $dbChanneled = $chanAccountRepo->findOneBy([
                            'platformId' => $id, 
                            'channel' => $channelEntity
                        ]);

                        if (!$dbChanneled) {
                            $dbChanneled = new \Entities\Analytics\Channeled\ChanneledAccount();
                            $dbChanneled->addPlatformId($id)
                                ->addAccount($accountEntity)
                                ->addType($typeMark)
                                ->addChannel($channelEntity)
                                ->addName($name)
                                ->addPlatformCreatedAt(isset($asset['created_at']) ? new \DateTime($asset['created_at']) : null)
                                ->addData([]);
                            $this->entityManager->persist($dbChanneled);
                        } elseif ($dbChanneled->getName() !== $name) {
                            $dbChanneled->addName($name);
                            $this->entityManager->persist($dbChanneled);
                        }

                        // Children
                        if (isset($pattern['children'])) {
                            foreach ($pattern['children'] as $childPattern) {
                                $childId = (string)($asset[$childPattern['id_key']] ?? '');
                                if (!$childId) continue;

                                $childName = $asset[$childPattern['name_key']] ?? $name;
                                $childType = $childPattern['type'];

                                $dbChild = $chanAccountRepo->findOneBy([
                                    'platformId' => $childId, 
                                    'channel' => $channelEntity
                                ]);
                                if (!$dbChild) {
                                    $dbChild = new \Entities\Analytics\Channeled\ChanneledAccount();
                                    $dbChild->addPlatformId($childId)
                                        ->addAccount($accountEntity)
                                        ->addType($childType)
                                        ->addChannel($channelEntity)
                                        ->addName($childName)
                                        ->addPlatformCreatedAt(isset($asset['created_at']) ? new \DateTime($asset['created_at']) : null)
                                        ->addData([]);
                                    $this->entityManager->persist($dbChild);
                                }
                            }
                        }

                        // Page Entity
                        if ($typeMark === 'gsc_site' || $typeMark === 'facebook_page') {
                            $canonicalId = \Helpers\Helpers::getCanonicalPageId($asset['url'] ?? $id, null, 'website');
                            $pageRepo = $this->entityManager->getRepository(\Entities\Analytics\Page::class);
                            $dbPage = $pageRepo->findOneBy(['canonicalId' => $canonicalId]);
                            if (!$dbPage) {
                                $dbPage = new \Entities\Analytics\Page();
                                $dbPage->addCanonicalId($canonicalId)
                                    ->addUrl($asset['url'] ?? $id)
                                    ->addTitle($name)
                                    ->addAccount($accountEntity)
                                    ->addPlatformId($id)
                                    ->addHostname($asset['hostname'] ?? parse_url($asset['url'] ?? $id, PHP_URL_HOST))
                                    ->addData($asset);
                                $this->entityManager->persist($dbPage);
                            }
                        }
                    }
                }
                $this->entityManager->flush();

                // 2. Initialize Channel-specific Entities via Driver hook
                $results = $driver->initializeEntities(array_merge($chanConfig, [
                    'manager' => $this->entityManager,
                    'identityMapper' => $identityMapper,
                    'dataProcessor' => $dataProcessor
                ]));
                
                $init = $results['initialized'] ?? 0;
                $skip = $results['skipped'] ?? 0;
                
                $this->logger->info("  - $channel results: $init initialized, $skip skipped.");
            } catch (Exception $e) {
                $this->logger->error("  - Failed to initialize $channel: " . $e->getMessage());
            }
        }
        $this->entityManager->flush();
    }
}
