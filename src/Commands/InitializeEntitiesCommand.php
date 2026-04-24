<?php

declare(strict_types=1);

namespace Commands;

use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
use Anibalealvarezs\ApiDriverCore\Enums\AssetCategory;
use Anibalealvarezs\ApiSkeleton\Enums\Country as CountryEnum;
use Anibalealvarezs\ApiSkeleton\Enums\Device as DeviceEnum;
use Doctrine\ORM\EntityManagerInterface;
use Entities\Analytics\Country;
use Entities\Analytics\Device;
use Exception;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

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
            $exitCode = $this->initializeChannelEntities($output);
            if ($exitCode !== Command::SUCCESS) {
                return $exitCode;
            }

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
            if (! $country) {
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
            if (! $device) {
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

    protected function initializeChannelEntities(OutputInterface $output): int
    {
        $registry = DriverFactory::getRegistry();
        $channelsConfig = Helpers::getChannelsConfig();

        foreach ($registry as $channel => $driverCfg) {
            // Determine if specifically enabled for this channel
            $chanConfig = $channelsConfig[$channel] ?? [];

            $driverClass = $driverCfg['driver'] ?? null;
            if (! $driverClass || ! class_exists($driverClass)) {
                $this->logger->error("Driver class '$driverClass' not found for channel: $channel");

                continue;
            }

            // Merge common configurations if specified by the driver
            $commonKey = $driverClass::getCommonConfigKey();
            if ($commonKey && isset($channelsConfig[$commonKey])) {
                $chanConfig = array_replace_recursive($channelsConfig[$commonKey], $chanConfig);
            }

            if (! ($chanConfig['enabled'] ?? false)) {
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

                        try {
                            $this->entityManager->flush();
                        } catch (Exception $e) {
                            error_log("ERROR during initialization flush: " . $e->getMessage());

                            throw $e;
                        }
                    }

                    return $stats;
                };

                /** @var \Entities\Analytics\Channel $channelEntity */
                $channelEntity = $this->entityManager->getRepository(\Entities\Analytics\Channel::class)->findOneBy(['name' => $channel]);
                if (! $channelEntity) {
                    $this->logger->error("  - ERROR: Channel entity '$channel' NOT FOUND in database. Ensure 'app:install-drivers' ran correctly.");

                    continue; // Skip this channel instead of failing the whole command
                }

                $identityMapper = function (string $type, array $params) use ($channel, $channelEntity) {
                    $repoMap = [
                        'channeled_accounts' => \Entities\Analytics\Channeled\ChanneledAccount::class,
                        'pages' => \Entities\Analytics\Page::class,
                        'accounts' => \Entities\Analytics\Account::class,
                    ];
                    if (! isset($repoMap[$type])) {
                        return [];
                    }
                    /** @var \Repositories\PageRepository|\Repositories\ChanneledAccountRepository $repo */
                    $repo = $this->entityManager->getRepository($repoMap[$type]);
                    $map = [];
                    $category = match ($type) {
                        'pages' => AssetCategory::PAGEABLE,
                        'channeled_accounts' => AssetCategory::IDENTITY,
                        default => null
                    };

                    $driverClass = \Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory::getRegistry()[$channel]['driver'] ?? null;
                    $context = $driverClass && $category ? $driverClass::getContextForCategory($category) : '';

                    if ($type === 'pages' && $category) {
                        $lookupField = 'platformId';
                        $searchValues = (array)($params['platform_ids'] ?? []);
                        if (isset($params['canonical_ids'])) {
                            $lookupField = 'canonicalId';
                            $searchValues = (array)$params['canonical_ids'];
                        } elseif (isset($params['urls'])) {
                            $lookupField = 'canonicalId';
                            $urls = (array)$params['urls'];
                            $searchValues = array_map(function ($u) use ($driverClass, $category, $context) {
                                if ($driverClass) {
                                    return $driverClass::getCanonicalId(['url' => $u], $category, $context);
                                }

                                throw new \RuntimeException("Driver not found for channel: " . $channel);
                            }, $urls);
                        }
                        if (! empty($searchValues)) {
                            error_log("DEBUG: InitializeEntitiesCommand - identityMapper: Looking up 'pages' by $lookupField: " . json_encode(array_unique($searchValues)));
                            $entities = $repo->findBy([$lookupField => array_unique($searchValues)]);
                            $getter = 'get'.ucfirst($lookupField);
                            foreach ($entities as $e) {
                                $map[(string)$e->$getter()] = $e;
                            }
                        }
                    } elseif ($type === 'channeled_accounts' && isset($params['platform_ids']) && $category) {
                        $ids = (array)$params['platform_ids'];
                        if ($driverClass) {
                            $searchValues = array_map(fn ($id) => $driverClass::getPlatformId(['id' => $id], $category, $context), $ids);
                        } else {
                            $searchValues = $ids;
                        }
                        $searchValues = array_unique($searchValues);
                        error_log("DEBUG: InitializeEntitiesCommand - identityMapper: Looking up 'channeled_accounts' by platformId: " . json_encode($searchValues));
                        $entities = $repo->findBy(['platformId' => $searchValues, 'channel' => $channelEntity]);
                        foreach ($entities as $e) {
                            $map[(string)$e->getPlatformId()] = $e;
                        }
                    } elseif ($type === 'accounts' && isset($params['names'])) {
                        $entities = $repo->findBy(['name' => $params['names']]);
                        foreach ($entities as $e) {
                            $map[(string)$e->getName()] = $e;
                        }
                    }

                    return $map;
                };

                // 1. Sync Assets from YAML to Database (Ported from ConfigManagerController)
                $commonKey = $driver::getCommonConfigKey();
                $defaultGroupName = method_exists($driver, 'getChannelLabel') ? $driver::getChannelLabel() : "Default Group";
                $groupName = $chanConfig['accounts_group_name'] ?? ($channelsConfig[$commonKey]['accounts_group_name'] ?? $defaultGroupName);

                $accountRepo = $this->entityManager->getRepository(\Entities\Analytics\Account::class);
                $accountEntity = $accountRepo->findOneBy(['name' => $groupName]);

                if (! $accountEntity) {
                    $accountEntity = new \Entities\Analytics\Account();
                    $accountEntity->addName($groupName)->addDescription("Group Account for $channel");
                    $this->entityManager->persist($accountEntity);
                    $this->entityManager->flush();
                }

                $registryPatterns = $driverClass::getAssetPatterns();
                $assets = [];
                foreach ($registryPatterns as $pattern) {
                    $key = $pattern['key'] ?? null;
                    if ($key && isset($chanConfig[$key])) {
                        $rawAssets = (array)$chanConfig[$key];
                        if (! empty($rawAssets) && ! isset($rawAssets[0])) {
                            $rawAssets = [$rawAssets];
                        }
                        foreach ($rawAssets as $ra) {
                            $assets[] = [
                                'data' => $ra,
                                'category' => $pattern['category'] ?? null,
                            ];
                        }
                    }
                }

                foreach ($assets as $assetInfo) {
                    $asset = $assetInfo['data'];
                    $category = $assetInfo['category'];

                    $groupPages = [];
                    $groupAccounts = [];

                    if ($category === AssetCategory::PAGEABLE) {
                        $groupPages = method_exists($driver, 'getPages') ? $driver::getPages($asset) : [];
                    } elseif ($category === AssetCategory::IDENTITY) {
                        $groupAccounts = method_exists($driver, 'getChanneledAccounts') ? $driver::getChanneledAccounts($asset) : [];
                    } else {
                        // Fallback for ad accounts or other legacy assets
                        $groupPages = method_exists($driver, 'getPages') ? $driver::getPages($asset) : [];
                        $groupAccounts = method_exists($driver, 'getChanneledAccounts') ? $driver::getChanneledAccounts($asset) : [];
                    }

                    $anyEnabled = false;
                    foreach ($groupPages as $p) {
                        if ($p['enabled'] ?? true) {
                            $anyEnabled = true;

                            break;
                        }
                    }
                    if (! $anyEnabled) {
                        foreach ($groupAccounts as $a) {
                            if ($a['enabled'] ?? true) {
                                $anyEnabled = true;

                                break;
                            }
                        }
                    }

                    foreach ($groupPages as $page) {
                        $pId = $page['platformId'] ?? null;
                        $canonicalId = $page['canonicalId'] ?? null;
                        if (! $pId && ! $canonicalId) {
                            continue;
                        }

                        $dbPage = $canonicalId ? ($this->entityManager->getRepository(\Entities\Analytics\Page::class)->findOneBy(['canonicalId' => $canonicalId])) : null;
                        if (! $dbPage && ! $anyEnabled) {
                            continue;
                        }
                        if (! $dbPage) {
                            $dbPage = new \Entities\Analytics\Page();
                            if ($canonicalId) {
                                $dbPage->addCanonicalId($canonicalId);
                            }
                        }
                        $dbPage->addUrl($page['url'] ?? null)
                            ->addTitle($page['title'] ?? null)
                            ->addAccount($accountEntity)
                            ->addPlatformId($page['platformId'])
                            ->addHostname($page['hostname'] ?? null)
                            ->addData($page['data'] ?? []);
                        $this->entityManager->persist($dbPage);
                    }

                    foreach ($groupAccounts as $account) {
                        $pId = $account['platformId'] ?? null;
                        if (! $pId) {
                            continue;
                        }

                        $dbChanneledAccount = $this->entityManager->getRepository(\Entities\Analytics\Channeled\ChanneledAccount::class)->findOneBy([
                            'platformId' => (string)$pId,
                            'channel' => $channelEntity,
                        ]);
                        if (! $dbChanneledAccount && ! $anyEnabled) {
                            continue;
                        }
                        if (! $dbChanneledAccount) {
                            $dbChanneledAccount = new \Entities\Analytics\Channeled\ChanneledAccount();
                            $dbChanneledAccount->addPlatformId($pId);
                        }
                        $dbChanneledAccount->addAccount($accountEntity)
                            ->addType($account['type'])
                            ->addChannel($channelEntity)
                            ->addName($account['name'] ?? null)
                            ->addPlatformCreatedAt(is_string($account['platformCreatedAt'] ?? null) ? new \DateTime($account['platformCreatedAt']) : null)
                            ->addData($account['data'] ?? [])
                            ->setEnabled($account['enabled'] ?? true);
                        $this->entityManager->persist($dbChanneledAccount);
                    }
                }
                $this->entityManager->flush();
                $this->entityManager->flush();

                // 2. Initialize Channel-specific Entities via Driver hook
                $results = $driver->initializeEntities(array_merge($chanConfig, [
                    'manager' => $this->entityManager,
                    'identityMapper' => $identityMapper,
                    'dataProcessor' => $dataProcessor,
                ]));

                $init = $results['initialized'] ?? 0;
                $skip = $results['skipped'] ?? 0;

                $this->logger->info("  - $channel results: $init initialized, $skip skipped.");
            } catch (\Exception $e) {
                $output->writeln("<error>  - FATAL ERROR initializing channel '$channel': " . $e->getMessage() . "</error>");
                error_log("FATAL ERROR initializing channel '$channel': " . $e->getMessage());
                error_log($e->getTraceAsString());

                return Command::FAILURE;
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {
            $output->writeln("<error>  - Final flush failed: " . $e->getMessage() . "</error>");

            return Command::FAILURE;
        }

        $output->writeln("<info>  - All channels initialized successfully.</info>");

        return Command::SUCCESS;
    }
}
